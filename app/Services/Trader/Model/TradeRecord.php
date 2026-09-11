<?php

namespace App\Services\Trader\Model;

use App\Services\Exchanges\TradingSymbol;
use App\Services\Trader\Enum\ExitType;
use App\Services\Trader\Enum\OrderSide;
use App\Services\Trader\Enum\TradingMode;
use InvalidArgumentException;

/**
 * 持仓（Trade）模型 —— 内存实现（回测默认使用，和 Freqtrade LocalTrade 一致）
 *
 * 核心要点：
 *  - 不使用数据库，所有状态全在内存（backtest 结束后导出数组）
 *  - 支持多次加仓（DCA）：通过 attachEntryOrder() 维护加权平均 openRate
 *  - 支持分批平仓：attachExitOrder() 维护加权平均 closeRate
 *  - 每一笔订单都保存在 orders[]（1:N 关系），支持审计
 *  - minRate / maxRate 在持仓期间每根 K 线都会更新（给 stop_loss / trailing_stop 回调用）
 *
 * 盈亏计算规则（与 Freqtrade 完全对齐）：
 *  close_profit_abs = closeCost - openCost - 开平仓手续费 - 资金费
 *  相对比例       = close_profit_abs / stakeAmount（无论杠杆多大都用最初 stake）
 */
class TradeRecord
{
    /** @var int 本地 ID，回测下自增 */
    private $id;

    /** @var TradingSymbol 标准交易对 */
    private $symbol;

    /** @var bool true=short，false=long */
    private $isShort;

    /** @var string TradingMode::* */
    private $tradingMode;

    /** @var float 杠杆倍数（现货=1，期货按配置，≥ 1）*/
    private $leverage;

    // ====== 入场 ======
    /** @var float 初始 stake（stake 货币），不随加仓变动，用于算收益率 */
    private $stakeAmount;
    /** @var float 获得的 base 数量（入场全部成交后合并得到）*/
    private $entryAmount = 0.0;
    /** @var float 加权平均入场价 */
    private $openRate = 0.0;
    /** @var float 入场总成本 stake 数（openRate * entryAmount）*/
    private $openCost = 0.0;
    /** @var float 入场手续费合计（stake 货币），多笔订单累加 */
    private $feeOpen = 0.0;
    /** @var int 入场时间（毫秒），取第一笔入场订单的 filledTimestamp */
    private $openTimestamp = 0;
    /** @var int 入场批次计数（成功的 fill）*/
    private $nrSuccessfulEntries = 0;

    // ====== 平仓 ======
    /** @var bool 是否已平仓 */
    private $closed = false;
    /** @var float 已平仓数量 base 数 */
    private $exitAmount = 0.0;
    /** @var float 加权平均平仓价 */
    private $closeRate = 0.0;
    /** @var float 平仓总收入 stake 数 */
    private $closeCost = 0.0;
    /** @var float 平仓手续费合计 */
    private $feeClose = 0.0;
    /** @var int|null 平仓时间（最后一批 exit 订单的 fill 时间）*/
    private $closeTimestamp = null;
    /** @var string ExitType::* 平仓原因 */
    private $exitReason = ExitType::NONE;
    /** @var int 平仓批次计数 */
    private $nrSuccessfulExits = 0;

    // ====== 附加 ======
    /** @var float 借贷利息 / 资金费（期货）累计（stake 货币，正数=要付，负数=要收）*/
    private $fundingAndInterest = 0.0;
    /** @var float 强平价（仅期货适用）*/
    private $liquidationPrice = 0.0;
    /** @var string 入场标签（来自 enter_tag 列）*/
    private $enterTag = '';
    /** @var string 出场标签（来自 exit_tag 列 / custom_exit 返回）*/
    private $exitTag = '';

    // ====== 风控 & 回测辅助 ======
    /** @var float 持仓期间最低触及价格（用于触发止损 / 统计最大回撤）*/
    private $minRate = INF;
    /** @var float 持仓期间最高触及价格 */
    private $maxRate = -INF;
    /** @var float 追踪止损当前触发价（= highest_close × (1 - trailing_stop_pct)，long 方向）*/
    private $trailingStopPrice = null;

    /** @var OrderRecord[] 所有关联订单（包括 cancel / partial / closed）*/
    private $orders = [];

    /**
     * @param array{
     *     id?:int,
     *     symbol:TradingSymbol,
     *     is_short?:bool,
     *     trading_mode?:string,
     *     leverage?:float,
     *     enter_tag?:string,
     *     liquidation_price?:float,
     * } $data
     */
    public function __construct(array $data)
    {
        if (!isset($data['symbol']) || !($data['symbol'] instanceof TradingSymbol)) {
            throw new InvalidArgumentException('TradeRecord requires TradingSymbol at "symbol"');
        }
        $this->id          = $data['id'] ?? 0;
        $this->symbol      = $data['symbol'];
        $this->isShort     = !empty($data['is_short']);
        $this->tradingMode = $data['trading_mode'] ?? TradingMode::SPOT;
        $this->leverage    = max(1.0, (float) ($data['leverage'] ?? 1.0));
        $this->enterTag    = (string) ($data['enter_tag'] ?? '');
        $this->liquidationPrice = (float) ($data['liquidation_price'] ?? 0.0);

        // 只有非现货才能做空（防御性检查）
        if ($this->isShort && !TradingMode::supportsShort($this->tradingMode)) {
            throw new InvalidArgumentException(
                "Short trades are not supported for trading mode {$this->tradingMode}"
            );
        }
    }

    // ------------------------- 入场 -------------------------

    /**
     * 附加一笔入场订单（支持 DCA 多次加仓）
     *
     * 内部会自动用 cost 重新算 openRate，保证多次加仓后"加权平均价"精准
     */
    public function attachEntryOrder(OrderRecord $order): void
    {
        if ($order->getStatus() === \App\Services\Trader\Enum\OrderStatus::OPEN) {
            throw new InvalidArgumentException("Cannot attach entry order, still OPEN: " . $order->getId());
        }
        if (!$order->isEntrySide()) {
            throw new InvalidArgumentException("attachEntryOrder expects entry order (side matched)");
        }
        if ($order->getFilled() <= 0) {
            return; // 零成交忽略（比如撤单）
        }

        $this->orders[] = $order;

        if ($this->openTimestamp === 0) {
            $this->openTimestamp = (int) $order->getFilledTimestamp();
        }

        // 如果 stakeAmount 未设定，用首批入场订单的 stake_amount
        if ($this->stakeAmount === null || $this->stakeAmount == 0.0) {
            $this->stakeAmount = (float) $order->getStakeAmount();
        }

        $this->openCost  += $order->getCost();
        $this->feeOpen   += $order->getFeeCost();
        $this->entryAmount += $order->getFilled();
        $this->nrSuccessfulEntries++;

        if ($this->entryAmount > 0) {
            $this->openRate = $this->openCost / $this->entryAmount;
        }
    }

    // ------------------------- 平仓 -------------------------

    /**
     * 附加一笔平仓订单（支持分批平仓）
     *
     * @param string $exitReason ExitType::*
     */
    public function attachExitOrder(OrderRecord $order, string $exitReason, string $exitTag = ''): void
    {
        if ($order->getStatus() === \App\Services\Trader\Enum\OrderStatus::OPEN) {
            throw new InvalidArgumentException("Cannot attach exit order, still OPEN: " . $order->getId());
        }
        if ($order->isEntrySide()) {
            throw new InvalidArgumentException("attachExitOrder expects exit order");
        }
        if ($order->getFilled() <= 0) {
            return;
        }

        $this->orders[]       = $order;
        $this->closeCost     += $order->getCost();
        $this->feeClose      += $order->getFeeCost();
        $this->exitAmount    += $order->getFilled();
        $this->nrSuccessfulExits++;

        if ($this->exitAmount > 0) {
            $this->closeRate = $this->closeCost / $this->exitAmount;
        }
        $this->closeTimestamp = (int) $order->getFilledTimestamp();

        // 只有全部平仓后，标记为 closed
        $epsilon = max(1e-9, $this->entryAmount * 1e-9);
        if (abs($this->exitAmount - $this->entryAmount) <= $epsilon) {
            $this->closed = true;
            $this->exitReason = $exitReason;
            $this->exitTag    = $exitTag ?: $this->exitTag;
        }
    }

    /**
     * 强平（手动或爆仓时调用，直接标记 closed）
     */
    public function forceClose(float $closeRate, int $timestamp, string $exitReason, float $feeClose = 0.0): void
    {
        $remaining = $this->entryAmount - $this->exitAmount;
        if ($remaining <= 1e-12 || $this->closed) {
            return;
        }
        $cost = $remaining * $closeRate;
        $this->closeCost     += $cost;
        $this->feeClose      += $feeClose;
        $this->exitAmount    = $this->entryAmount;
        $this->closeRate     = $this->closeCost / $this->exitAmount;
        $this->closeTimestamp = $timestamp;
        $this->closed         = true;
        $this->exitReason     = $exitReason;
    }

    // ------------------------- 风控 / 追踪止损 -------------------------

    /**
     * 更新当前价格触及范围，以及触发 trailing_stop 的基准价
     *
     * long:
     *   每次新高 close → 抬高 trailing stop = close × (1 - pct)
     * short:
     *   每次新低 close → 压低 trailing stop = close × (1 + pct)
     *
     * @param float $currentHigh   当前 K 线 high
     * @param float $currentLow    当前 K 线 low
     * @param float $currentClose  当前 K 线 close（trailing 的基准价）
     * @param float|null $trailingStopPct 小数（0.03 = 3%），未启用传 null
     */
    public function updateExtremesAndTrailing(
        float $currentHigh,
        float $currentLow,
        float $currentClose,
        ?float $trailingStopPct
    ): void {
        if ($currentLow < $this->minRate) {
            $this->minRate = $currentLow;
        }
        if ($currentHigh > $this->maxRate) {
            $this->maxRate = $currentHigh;
        }

        if ($trailingStopPct === null || $trailingStopPct <= 0) {
            return;
        }

        if (!$this->isShort) {
            // long：历史最高 close 作为锚
            if ($this->trailingStopPrice === null || $currentClose > $this->_getAnchorPrice()) {
                $this->_setAnchorPrice($currentClose);
                $this->trailingStopPrice = $currentClose * (1 - $trailingStopPct);
            }
        } else {
            // short：历史最低 close 作为锚
            if ($this->trailingStopPrice === null || $currentClose < $this->_getAnchorPrice()) {
                $this->_setAnchorPrice($currentClose);
                $this->trailingStopPrice = $currentClose * (1 + $trailingStopPct);
            }
        }
    }

    /**
     * 持仓期间最高价（long 时是 trailing 锚点）
     * 用单独属性存而不是复用 maxRate，因为 maxRate 可能是 high（影线），trailing 要用 close
     */
    private $_anchorPrice = null;
    private function _getAnchorPrice(): float { return $this->_anchorPrice ?? 0.0; }
    private function _setAnchorPrice(float $p): void { $this->_anchorPrice = $p; }

    // ------------------------- 盈亏计算 -------------------------

    /**
     * 返回当前未实现盈亏（stake 货币），手续费算入
     *
     * 未平仓时用 markRate 当作当前平仓价
     */
    public function getUnrealizedProfitAbs(float $markRate): float
    {
        $remaining = $this->entryAmount - $this->exitAmount;
        if ($remaining <= 0) {
            return 0.0;
        }
        if (!$this->isShort) {
            $gross = ($markRate - $this->openRate) * $remaining;
        } else {
            $gross = ($this->openRate - $markRate) * $remaining;
        }
        return $gross - $this->fundingAndInterest - $this->feeOpen - $this->feeClose;
    }

    /**
     * 已实现盈亏（仅当 closed=true 有效，作为统计报表出口）
     */
    public function getCloseProfitAbs(): float
    {
        if (!$this->closed) {
            return 0.0;
        }
        if (!$this->isShort) {
            $gross = $this->closeCost - $this->openCost;
        } else {
            $gross = $this->openCost - $this->closeCost;
        }
        return $gross - $this->feeOpen - $this->feeClose - $this->fundingAndInterest;
    }

    /**
     * 相对收益率（和 Freqtrade 一致：相对 initial stakeAmount，杠杆后实际投入）
     */
    public function getCloseProfitRatio(): float
    {
        if (!$this->closed || $this->stakeAmount == 0.0) {
            return 0.0;
        }
        return $this->getCloseProfitAbs() / $this->stakeAmount;
    }

    /**
     * 入场方向（buy/sell）：long=buy, short=sell
     */
    public function getEntrySide(): string
    {
        return $this->isShort ? OrderSide::SELL : OrderSide::BUY;
    }

    /**
     * 平仓方向（buy/sell）：long 平=sell, short 平=buy
     */
    public function getExitSide(): string
    {
        return $this->isShort ? OrderSide::BUY : OrderSide::SELL;
    }

    // ------------------------- Getters -------------------------

    public function getId(): int { return $this->id; }
    public function setId(int $id): void { $this->id = $id; }
    public function getSymbol(): TradingSymbol { return $this->symbol; }
    public function isShort(): bool { return $this->isShort; }
    public function isLong(): bool  { return !$this->isShort; }
    public function getTradingMode(): string { return $this->tradingMode; }
    public function getLeverage(): float { return $this->leverage; }
    public function getStakeAmount(): float { return $this->stakeAmount; }
    public function setStakeAmount(float $v): void { $this->stakeAmount = $v; }
    public function getEntryAmount(): float { return $this->entryAmount; }
    public function getExitAmount(): float { return $this->exitAmount; }
    public function getOpenRate(): float { return $this->openRate; }
    public function getCloseRate(): float { return $this->closeRate; }
    public function getOpenCost(): float { return $this->openCost; }
    public function getCloseCost(): float { return $this->closeCost; }
    public function getFeeOpen(): float { return $this->feeOpen; }
    public function getFeeClose(): float { return $this->feeClose; }
    public function getOpenTimestamp(): int { return $this->openTimestamp; }
    public function getCloseTimestamp(): ?int { return $this->closeTimestamp; }
    public function getExitReason(): string { return $this->exitReason; }
    public function getNrSuccessfulEntries(): int { return $this->nrSuccessfulEntries; }
    public function getNrSuccessfulExits(): int { return $this->nrSuccessfulExits; }
    public function getFundingAndInterest(): float { return $this->fundingAndInterest; }
    public function addFundingAndInterest(float $v): void { $this->fundingAndInterest += $v; }
    public function getLiquidationPrice(): float { return $this->liquidationPrice; }
    public function setLiquidationPrice(float $v): void { $this->liquidationPrice = $v; }
    public function getEnterTag(): string { return $this->enterTag; }
    public function getExitTag(): string { return $this->exitTag; }
    public function getMinRate(): float { return $this->minRate; }
    public function getMaxRate(): float { return $this->maxRate; }
    public function getTrailingStopPrice(): ?float { return $this->trailingStopPrice; }
    public function resetTrailingStop(): void { $this->trailingStopPrice = null; $this->_anchorPrice = null; }
    public function isClosed(): bool { return $this->closed; }
    public function isOpen(): bool   { return !$this->closed; }
    public function getDurationMinutes(): int
    {
        if (!$this->closed) {
            return 0;
        }
        return (int) ceil(max(0, ($this->closeTimestamp - $this->openTimestamp) / 60_000));
    }

    /**
     * @return OrderRecord[]
     */
    public function getOrders(): array { return $this->orders; }

    /**
     * 返回所有入场方向的订单（含成功/失败）
     * @return OrderRecord[]
     */
    public function getEntryOrders(): array
    {
        return array_values(array_filter($this->orders, static function (OrderRecord $o) {
            return $o->isEntrySide();
        }));
    }

    /**
     * 导出扁平数组（报表 / JSON / CSV 使用）
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'trade_id'             => $this->id,
            'pair'                 => (string) $this->symbol,
            'direction'            => $this->isShort ? 'short' : 'long',
            'trading_mode'         => $this->tradingMode,
            'leverage'             => $this->leverage,
            'stake_amount'         => $this->stakeAmount,
            'amount'               => $this->entryAmount,
            'open_rate'            => $this->openRate,
            'close_rate'           => $this->closeRate,
            'open_timestamp'       => $this->openTimestamp,
            'close_timestamp'      => $this->closeTimestamp,
            'open_date_utc'        => $this->openTimestamp  ? gmdate('Y-m-d H:i:s', (int) ($this->openTimestamp  / 1000)) : null,
            'close_date_utc'       => $this->closeTimestamp ? gmdate('Y-m-d H:i:s', (int) ($this->closeTimestamp / 1000)) : null,
            'is_open'              => !$this->closed,
            'close_reason'         => $this->exitReason,
            'enter_tag'            => $this->enterTag,
            'exit_tag'             => $this->exitTag,
            'fee_open'             => $this->feeOpen,
            'fee_close'            => $this->feeClose,
            'funding_interest'     => $this->fundingAndInterest,
            'liquidation_price'    => $this->liquidationPrice,
            'min_rate'             => is_infinite($this->minRate) ? null : $this->minRate,
            'max_rate'             => is_infinite(-$this->maxRate) ? null : $this->maxRate,
            'nr_entries'           => $this->nrSuccessfulEntries,
            'nr_exits'             => $this->nrSuccessfulExits,
            'duration_minutes'     => $this->getDurationMinutes(),
            'close_profit_abs'     => $this->getCloseProfitAbs(),
            'close_profit_ratio'   => $this->getCloseProfitRatio(),
            'order_count'          => count($this->orders),
        ];
    }
}
