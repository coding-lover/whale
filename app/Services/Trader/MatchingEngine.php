<?php

namespace App\Services\Trader;

use App\Services\Exchanges\TradingSymbol;
use App\Services\Trader\Enum\ExitType;
use App\Services\Trader\Enum\OrderSide;
use App\Services\Trader\Enum\OrderStatus;
use App\Services\Trader\Enum\OrderType;
use App\Services\Trader\Enum\TradingMode;
use App\Services\Trader\ExitRules\ExitRules;
use App\Services\Trader\Fee\FeeCalculator;
use App\Services\Trader\Fee\SlippageCalculator;
use App\Services\Trader\Model\OrderRecord;
use App\Services\Trader\Model\TradeRecord;
use App\Services\Trader\Model\Wallet;
use App\Services\Trader\Strategy\SignalCols;
use App\Services\Trader\Strategy\StrategyInterface;
use InvalidArgumentException;

/**
 * 撮合引擎（MatchingEngine）
 *
 * 对应 Freqtrade：
 *   _enter_trade()      → 本类 executeEntry()
 *   _check_trade_exit() → 本类 checkTradeExit() + executeExit()
 *   _get_close_rate()   → 本类 getExecutionPrice()（最关键的撮合价算法）
 *
 * 设计：把"交易对象/钱包/手续费/滑点"都封装在这个 class 内部，
 * Backtesting 只要传 strategy + 当前 K 线矩阵行就能执行，不再依赖外部分散工具。
 *
 * ⚠️ 执行价撮合规则（严格遵守 Freqtrade 避免前视偏差的方案）：
 *
 *  【入场】策略在第 i 根 K 线 close 之后出信号 → 必须在第 i+1 根 K 线的 open 或 open 之后执行
 *      → 本引擎使用 i+1 根的 open 作为默认限价 + 吃单方向滑点
 *      → 如果限价 ≤ low（buy）或限价 ≥ high（sell）则认为可以成交
 *  【止损/止盈】使用 high/low 判断是否"影线内已经触发"：
 *      - Long 止损：low ≤ stopPrice → 触发，以 stopPrice 成交
 *      - Long ROI 用 close 判定 → 以 close 成交（可以改成 high 更乐观，但 close 偏保守更合理）
 */
class MatchingEngine
{
    /** @var FeeCalculator 手续费 */
    private $fee;

    /** @var SlippageCalculator 滑点 */
    private $slippage;

    /** @var ExitRules 平仓规则检查器 */
    private $exitRules;

    /** @var int 订单号自增器 */
    private $orderIdCounter = 1;

    /** @var int Trade ID 自增器 */
    private $tradeIdCounter = 1;

    /** @var string stake 货币（默认 USDT）*/
    private $stakeCurrency;

    public function __construct(
        FeeCalculator $fee,
        SlippageCalculator $slippage,
        ExitRules $exitRules,
        string $stakeCurrency = 'USDT'
    ) {
        $this->fee           = $fee;
        $this->slippage      = $slippage;
        $this->exitRules     = $exitRules;
        $this->stakeCurrency = $stakeCurrency;
    }

    // -----------------------------------------------------------
    //  公共：执行入场
    // -----------------------------------------------------------

    /**
     * 尝试入场
     *
     * @param TradingSymbol     $symbol    标准交易对
     * @param string            $direction 'long' 或 'short'
     * @param StrategyInterface $strategy
     * @param array             $entryRow  触发信号的那根 K 线矩阵行（第 i 根）
     * @param array             $execRow   执行 K 线（第 i+1 根）——撮合发生在这根
     * @param Wallet            $wallet    钱包（会真实扣款）
     * @param string            $tradingMode TradingMode 类常量字符串（TradingMode::SPOT 等）
     * @param string            $enterTag  入场标签
     * @return TradeRecord|null 成功返回持仓；null 表示余额不足 / 不成交（回测一般应该成交，余额不够也直接返回 null）
     */
    public function executeEntry(
        TradingSymbol $symbol,
        string $direction,
        StrategyInterface $strategy,
        array $entryRow,
        array $execRow,
        Wallet $wallet,
        string $tradingMode,
        string $enterTag = ''
    ): ?TradeRecord {
        if ($direction !== 'long' && $direction !== 'short') {
            throw new InvalidArgumentException("direction must be 'long' or 'short'");
        }
        $isShort = $direction === 'short';

        // 1. 决定入场价
        $side    = $isShort ? OrderSide::SELL : OrderSide::BUY;
        $openNext = (float) $execRow[SignalCols::OPEN];
        $customPrice = $strategy->customEntryPrice($symbol, $side, $execRow, $entryRow);
        $orderPrice = $customPrice ?? $openNext;

        // 2. 用滑点 + 订单类型决定撮合价（默认吃单 market，最保守）
        $orderType   = OrderType::MARKET;
        $isTaker     = OrderType::isTaker($orderType);
        $symbolKey   = (string) $symbol;
        $fillPrice   = $this->slippage->applySlippage($symbolKey, $side, $orderPrice, $isTaker);

        // 3. 判定可成交性（限价方向的 K 线高低价边界）
        if ($side === OrderSide::BUY) {
            // buy: 如果 fillPrice ≤ 当日 high（否则价格涨到 fillPrice 也买不到 → 不成交）
            if ($fillPrice > (float) $execRow[SignalCols::HIGH] + 1e-9) {
                return null; // 价格未触达 → 不成交（例如 limit buy 挂在影线下沿下面）
            }
        } else {
            // sell: 如果 fillPrice ≥ 当日 low
            if ($fillPrice < (float) $execRow[SignalCols::LOW] - 1e-9) {
                return null;
            }
        }
        // limit buy fillPrice < low → 能成交，成交价不能低于 low
        if ($side === OrderSide::BUY) {
            $fillPrice = max($fillPrice, (float) $execRow[SignalCols::LOW]);
        } else {
            $fillPrice = min($fillPrice, (float) $execRow[SignalCols::HIGH]);
        }

        // 4. 计算 stake 金额 + 仓位量
        $stakeAmount = $strategy->getStakeAmount($symbol);
        $leverage    = $strategy->getLeverage($tradingMode);
        if ($stakeAmount <= 0) {
            return null;
        }
        // wallet 可用余额校验（只有非借入部分算，现货 BTC/USDT 买 BTC 需要有足够 USDT）
        if (!$wallet->canAfford($this->stakeCurrency, $stakeAmount)) {
            return null;
        }
        // 如果是期货逐仓，占用 stakeAmount 与杠杆无关；但实际买入的 base 数量 = stake × leverage / price
        $baseAmount = ($stakeAmount * $leverage) / max(1e-12, $fillPrice);
        if ($baseAmount <= 0) {
            return null;
        }

        // 5. 创建订单并模拟完全成交（MARKET 总是一次满成交）
        $execTs    = (int) $execRow[SignalCols::DATE];
        $orderId   = $this->orderIdCounter++;
        $feeAmt    = $this->fee->calculate($orderType, $baseAmount, $fillPrice);

        $order = new OrderRecord([
            'id'              => $orderId,
            'symbol'          => $symbolKey,
            'side'            => $side,
            'type'            => $orderType,
            'price'           => $fillPrice,
            'amount'          => $baseAmount,
            'order_timestamp' => $execTs,
            'entry_side'      => true,
            'stake_amount'    => $stakeAmount,
            'fee_currency'    => $this->stakeCurrency,
        ]);
        $order->applyFill($baseAmount, $fillPrice, $feeAmt, $execTs);

        // 6. 扣减 stake 货币，增加 base 货币（对于 U本位，USDT 扣，BTC base 加）
        $quoteNeed = $stakeAmount; // 实际投入 USDT 就是 stakeAmount
        $wallet->debit($this->stakeCurrency, $quoteNeed);
        // 手续费：USDT 直接另扣
        if ($feeAmt > 0) {
            if (!$wallet->canAfford($this->stakeCurrency, $feeAmt)) {
                // 手续费不够就失败，退回
                $wallet->credit($this->stakeCurrency, $quoteNeed);
                return null;
            }
            $wallet->debit($this->stakeCurrency, $feeAmt);
        }
        // 增加 base 币（BTC 等）
        $wallet->credit($symbol->getBase(), $baseAmount);

        // 7. 构造 TradeRecord 并绑定订单
        $trade = new TradeRecord([
            'id'           => $this->tradeIdCounter++,
            'symbol'       => $symbol,
            'is_short'     => $isShort,
            'trading_mode' => $tradingMode,
            'leverage'     => $leverage,
            'enter_tag'    => $enterTag,
        ]);
        $trade->setStakeAmount($stakeAmount);
        $trade->attachEntryOrder($order);
        return $trade;
    }

    // -----------------------------------------------------------
    //  公共：检查是否要平仓 + 执行平仓（含撮合价、手续费、钱包归还）
    // -----------------------------------------------------------

    /**
     * 检查 Trade 是否命中任何平仓规则（Exit Rules + 策略信号 + custom_exit）
     *
     * 调用者 Backtesting 顺序应为：
     *   1) 先用 ExitRules::check() 跑一轮 → 如果返回 [type, price] 直接执行
     *   2) 否则检查 exit_signal / exit_tag
     *   3) 否则 custom_exit
     *
     * @return array{0:string,1:float,2:string}|null [exit_type, 触发价, exit_tag]
     */
    public function checkTradeExit(
        TradeRecord $trade,
        array $currentRow,
        int $barDurationMs,
        StrategyInterface $strategy,
        bool $exitSignal,
        string $exitTagFromRow,
        int $barIndex
    ): ?array {
        // 1. Exit Rules（LIQUIDATION / STOP_LOSS / TRAILING_STOP / ROI / HOLD）
        $ruleHit = $this->exitRules->check(
            $trade,
            $currentRow,
            $barDurationMs,
            $strategy->getStoploss(),
            $strategy->getMinimalRoi(),
            $strategy->getTrailingStop(),
            $strategy->getTrailingStopPositive(),
            $strategy->getMaxHoldBars()
        );
        if ($ruleHit !== null) {
            return [$ruleHit[0], $ruleHit[1], ''];
        }

        // 2. custom_exit（策略回调）
        if ($strategy->customExit($trade, $barIndex, $currentRow)) {
            $customPrice = $strategy->customExitPrice($trade, ExitType::CUSTOM_EXIT, $currentRow);
            $price = $customPrice ?? (float) $currentRow[SignalCols::CLOSE];
            return [ExitType::CUSTOM_EXIT, $price, ''];
        }

        // 3. 策略 exit_signal
        if ($exitSignal) {
            $customPrice = $strategy->customExitPrice($trade, ExitType::EXIT_SIGNAL, $currentRow);
            $price = $customPrice ?? (float) $currentRow[SignalCols::CLOSE];
            return [ExitType::EXIT_SIGNAL, $price, $exitTagFromRow];
        }

        return null;
    }

    /**
     * 执行平仓：
     *   - 决定最终执行价（getExecutionPrice，严格按 high/low）
     *   - 创建平仓 OrderRecord
     *   - 归还/扣减钱包
     *   - attachExitOrder 到 Trade（会自动标记为 closed）
     */
    public function executeExit(
        TradeRecord $trade,
        array $currentRow,
        string $exitType,
        float $triggerPrice,
        string $exitTag,
        Wallet $wallet
    ): void {
        if ($trade->isClosed()) {
            return; // 已平仓直接忽略
        }

        $symbolKey = (string) $trade->getSymbol();
        $side      = $trade->getExitSide();   // long→sell, short→buy
        // 最终撮合价（按 high/low 夹逼）
        $fillPrice = $this->getExecutionPrice($trade, $exitType, $triggerPrice, $currentRow);

        // 订单类型：STOP_LOSS/TRAILING 视为 taker
        $orderType = $this->resolveExitOrderType($exitType);
        $isTaker   = OrderType::isTaker($orderType);
        $fillPrice = $this->slippage->applySlippage($symbolKey, $side, $fillPrice, $isTaker);

        // 平仓数量 = entryAmount - exitAmount（可能部分平，但回测默认一次全平）
        $remaining = $trade->getEntryAmount() - $trade->getExitAmount();
        if ($remaining <= 1e-12) {
            return;
        }

        $execTs = (int) $currentRow[SignalCols::DATE];
        $feeAmt = $this->fee->calculate($orderType, $remaining, $fillPrice);

        // 订单
        $orderId = $this->orderIdCounter++;
        $order   = new OrderRecord([
            'id'              => $orderId,
            'symbol'          => $symbolKey,
            'side'            => $side,
            'type'            => $orderType,
            'price'           => $fillPrice,
            'amount'          => $remaining,
            'order_timestamp' => $execTs,
            'entry_side'      => false, // 平仓单
            'fee_currency'    => $this->stakeCurrency,
        ]);
        $order->applyFill($remaining, $fillPrice, $feeAmt, $execTs);

        // 钱包变动：先扣 base（BTC 平掉多仓 → BTC 减少 → stakeCurrency 增加）
        $baseCurr = $trade->getSymbol()->getBase();
        if (!$wallet->canAfford($baseCurr, $remaining)) {
            // base 不够（理论不会发生，trade 里已经有 baseAmount 在开仓时 credit 过）
            // 兜底：直接强行记平，避免回测崩溃
            $wallet->setBalance($baseCurr, $remaining);
        }
        $wallet->debit($baseCurr, $remaining);

        $gross = $remaining * $fillPrice;  // 卖出得到的 stake
        if ($side === OrderSide::BUY) {
            // short 平仓：buy 回补 → 付出 stake（USDT 减少）
            if (!$wallet->canAfford($this->stakeCurrency, $gross)) {
                $wallet->setBalance($this->stakeCurrency, max(0, $gross)); // 兜底
            }
            $wallet->debit($this->stakeCurrency, $gross);
        } else {
            // long 平仓：sell → stake 增加
            $wallet->credit($this->stakeCurrency, $gross);
        }
        // 手续费（无论哪侧都用 stake 扣）
        if (!$wallet->canAfford($this->stakeCurrency, $feeAmt)) {
            $wallet->setBalance($this->stakeCurrency, $feeAmt);
        }
        $wallet->debit($this->stakeCurrency, $feeAmt);

        // 归还初始 stake 占用？不，stake 在开仓时已扣为 base，平仓上述逻辑会自动把 base 换成 stake 的毛收入
        // 盈亏 = Trade.closeProfitAbs()，这里不用再调。

        $trade->attachExitOrder($order, $exitType, $exitTag);
    }

    // -----------------------------------------------------------
    //  撮合价算法（最容易引入前视偏差的地方，单独公开便于测试）
    // -----------------------------------------------------------

    /**
     * 根据 exit_type 把触发价夹逼到 [low, high] 区间内。
     *
     * 关键规则（Freqtrade 行为对齐）：
     *  - STOP_LOSS：一旦 low <= stopPrice（long）或 high >= stopPrice（short）→ 以 stopPrice 成交
     *    而不是 low/high，因为止损限价单就挂在那根价上。
     *  - TRAILING_STOP：与 STOP_LOSS 同算法
     *  - ROI / EXIT_SIGNAL / CUSTOM_EXIT / STOP_ON_TIMEOUT：用 close（但在 [low, high] 内）
     *  - LIQUIDATION：用触发价（= liquidationPrice），一般恰好是清算价
     */
    public function getExecutionPrice(
        TradeRecord $trade,
        string $exitType,
        float $triggerPrice,
        array $currentRow
    ): float {
        $high = (float) $currentRow[SignalCols::HIGH];
        $low  = (float) $currentRow[SignalCols::LOW];
        $close = (float) $currentRow[SignalCols::CLOSE];

        // 首先把任何价格先 clamp 到 [low, high]，防止任何越界
        $withinBar = min($high, max($low, $triggerPrice));

        switch ($exitType) {
            case ExitType::STOP_LOSS:
            case ExitType::TRAILING_STOP:
            case ExitType::LIQUIDATION:
                // 止损/强平 → 使用触发价（stopPrice），但如果触发价落在 bar 外则使用 bar 的极值
                // 例：long stop at 48000, low = 47800 → 48000 触及，按 48000 成交
                //     long stop at 48000, low = 48500 → 没触及？（ExitRules 已确保触及，所以这里直接夹逼即可）
                return $withinBar;

            case ExitType::ROI:
            case ExitType::EXIT_SIGNAL:
            case ExitType::CUSTOM_EXIT:
            case ExitType::STOP_ON_TIMEOUT:
            case ExitType::FORCE_EXIT:
                // 以 close 成交，但 close 一定在 [low, high]
                return min($high, max($low, $close));

            default:
                return $withinBar;
        }
    }

    /**
     * 根据平仓原因决定用 maker 还是 taker 手续费
     */
    private function resolveExitOrderType(string $exitType): string
    {
        switch ($exitType) {
            case ExitType::STOP_LOSS:
                return OrderType::STOP_LOSS;
            case ExitType::TRAILING_STOP:
                return OrderType::STOP_LOSS; // 触发后 market
            case ExitType::LIQUIDATION:
                return OrderType::MARKET;
            case ExitType::ROI:
            case ExitType::EXIT_SIGNAL:
            case ExitType::CUSTOM_EXIT:
            case ExitType::STOP_ON_TIMEOUT:
            case ExitType::FORCE_EXIT:
                return OrderType::MARKET; // 简化：所有非止损都按 market taker 处理（最保守）
            default:
                return OrderType::MARKET;
        }
    }
}
