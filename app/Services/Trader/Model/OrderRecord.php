<?php

namespace App\Services\Trader\Model;

use App\Services\Trader\Enum\OrderSide;
use App\Services\Trader\Enum\OrderStatus;
use App\Services\Trader\Enum\OrderType;
use InvalidArgumentException;

/**
 * 订单记录（相当于 Freqtrade Order + LocalTrade 里的单笔订单结构）
 *
 * 回测模式下：create 出来后立即 applyFill() 模拟交易所撮合
 * 实盘模式下：create 后交由 Exchange 异步填充
 *
 * 关键字段保证和 CCXT Order 对齐，方便 Dry/Live 模式下做结构转换。
 */
class OrderRecord
{
    /** @var int 本地订单 ID（回测用 OrderIdCounter 自增） */
    private $id;

    /** @var string 交易所订单号（回测下用本地 id 转 string）*/
    private $exchangeOrderId;

    /** @var string 标准交易对字符串（如 BTC/USDT:SWAP）*/
    private $symbol;

    /** @var string OrderSide::BUY / SELL */
    private $side;

    /** @var string OrderType::* */
    private $type;

    /** @var string OrderStatus::*（撮合过程中会从 OPEN → CLOSED）*/
    private $status;

    /** @var float 下单价格（限价单必填；市价单传 NaN，但撮合时用当前 close）*/
    private $price;

    /** @var float 下单量（基础货币）*/
    private $amount;

    /** @var float 已成交量（基础货币）*/
    private $filled = 0.0;

    /** @var float 平均成交价格（多批成交后做加权平均） */
    private $averagePrice = 0.0;

    /** @var float 成交总成本 stake 货币量（filled*price 再累加，保证精度） */
    private $cost = 0.0;

    /** @var float 手续费总额（stake 货币），允许多次成交累加 */
    private $feeCost = 0.0;

    /** @var string 手续费币种（USDT / BNB 等） */
    private $feeCurrency = '';

    /** @var int 下单时间（毫秒） */
    private $orderTimestamp;

    /** @var int|null 最后一次成交时间（毫秒） */
    private $filledTimestamp = null;

    /** @var bool 是否是入场单（Trade 中用于 filter 入场/出场） */
    private $entrySide;

    /** @var string 触发价（止损/止盈单适用，否则 null）*/
    private $stopPrice;

    /** @var float|null stake 货币投资额（stakeAmount / leverage 的参考）*/
    private $stakeAmount;

    /**
     * @param array{
     *     id?:int,
     *     exchange_order_id?:string,
     *     symbol:string,
     *     side:string,
     *     type:string,
     *     status?:string,
     *     price:float,
     *     amount:float,
     *     order_timestamp:int,
     *     entry_side:bool,
     *     stop_price?:float|null,
     *     stake_amount?:float,
     *     fee_currency?:string
     * } $data
     */
    public function __construct(array $data)
    {
        $this->validate($data);
        $this->id                = $data['id'] ?? 0;
        $this->exchangeOrderId   = $data['exchange_order_id'] ?? (string) ($data['id'] ?? 0);
        $this->symbol            = $data['symbol'];
        $this->side              = $data['side'];
        $this->type              = $data['type'];
        $this->status            = $data['status'] ?? OrderStatus::OPEN;
        $this->price             = $data['price'];
        $this->amount            = $data['amount'];
        $this->orderTimestamp    = $data['order_timestamp'];
        $this->entrySide         = (bool) ($data['entry_side'] ?? true);
        $this->stopPrice         = $data['stop_price'] ?? null;
        $this->stakeAmount       = $data['stake_amount'] ?? null;
        $this->feeCurrency       = $data['fee_currency'] ?? '';
    }

    /**
     * 校验构造参数的合法性（任何非法会在早期 throw，避免撮合引擎中产生幽灵订单）
     */
    private function validate(array $data): void
    {
        if (empty($data['symbol'])) {
            throw new InvalidArgumentException('Order symbol must not be empty');
        }
        if (!in_array($data['side'], OrderSide::all(), true)) {
            throw new InvalidArgumentException("Invalid order side: " . ($data['side'] ?? 'NULL'));
        }
        if (!in_array($data['type'], OrderType::all(), true)) {
            throw new InvalidArgumentException("Invalid order type: " . ($data['type'] ?? 'NULL'));
        }
        if (($data['amount'] ?? 0.0) <= 0) {
            throw new InvalidArgumentException("Order amount must be > 0, got " . ($data['amount'] ?? 0));
        }
        if (($data['price'] ?? 0.0) <= 0 && $data['type'] !== OrderType::MARKET) {
            throw new InvalidArgumentException("Order price must be > 0 for non-market orders");
        }
        if (($data['order_timestamp'] ?? 0) <= 0) {
            throw new InvalidArgumentException("Order order_timestamp must be > 0");
        }
    }

    /**
     * 撮合引擎在"订单成交"时调用：更新 filled / average / cost / fee + 状态
     *
     * 支持多次调用（DCA 分批、部分成交场景）：
     *   第一次 50% 成交 → 记录第一批 cost；第二次再 50% → 合并加权
     *
     * @param float $fillAmount    本次成交量（基础货币数量，正数）
     * @param float $fillPrice     本次成交价（不要用限价，要用真实撮合价）
     * @param float $fee           本次手续费（stake 货币，正数）
     * @param int   $fillTimestamp 本次成交时间
     */
    public function applyFill(
        float $fillAmount,
        float $fillPrice,
        float $fee,
        int $fillTimestamp
    ): void {
        if ($fillAmount <= 0) {
            throw new InvalidArgumentException("Fill amount must be > 0, got {$fillAmount}");
        }
        if ($fillPrice <= 0) {
            throw new InvalidArgumentException("Fill price must be > 0, got {$fillPrice}");
        }
        if ($this->status === OrderStatus::CLOSED) {
            throw new InvalidArgumentException("Cannot fill already CLOSED order {$this->id}");
        }
        if ($fillAmount > $this->amount - $this->filled + 1e-12) {
            // 允许 1e-12 浮点容差，超过则报错
            $remaining = $this->amount - $this->filled;
            throw new InvalidArgumentException(
                "Fill amount {$fillAmount} exceeds remaining {$remaining} for order {$this->id}"
            );
        }

        $this->cost         += $fillAmount * $fillPrice;
        $this->feeCost      += $fee;
        $this->filled       += $fillAmount;
        $this->filledTimestamp = $fillTimestamp;

        if ($this->filled > 0) {
            $this->averagePrice = $this->cost / $this->filled;
        }

        // 完全成交 → 状态变 CLOSED；否则 PARTIAL
        $epsilon = max(1e-9, $this->amount * 1e-9);
        if (abs($this->filled - $this->amount) <= $epsilon) {
            $this->status = OrderStatus::CLOSED;
        } else {
            $this->status = OrderStatus::PARTIAL;
        }
    }

    /**
     * 取消订单（OPEN / PARTIAL 都可以取消，已经 CLOSED 或 CANCELED 则忽略）
     */
    public function markCanceled(): void
    {
        if (!OrderStatus::isTerminal($this->status)) {
            $this->status = OrderStatus::CANCELED;
        }
    }

    // ---------------- Getters ----------------

    public function getId(): int                  { return $this->id; }
    public function getExchangeOrderId(): string   { return $this->exchangeOrderId; }
    public function getSymbol(): string            { return $this->symbol; }
    public function getSide(): string              { return $this->side; }
    public function getType(): string              { return $this->type; }
    public function getStatus(): string            { return $this->status; }
    public function getPrice(): float              { return $this->price; }
    public function getAmount(): float             { return $this->amount; }
    public function getFilled(): float             { return $this->filled; }
    public function getRemaining(): float          { return $this->amount - $this->filled; }
    public function getAveragePrice(): float       { return $this->averagePrice; }
    public function getCost(): float               { return $this->cost; }
    public function getFeeCost(): float            { return $this->feeCost; }
    public function getFeeCurrency(): string       { return $this->feeCurrency; }
    public function getOrderTimestamp(): int       { return $this->orderTimestamp; }
    public function getFilledTimestamp(): ?int     { return $this->filledTimestamp; }
    public function isEntrySide(): bool            { return $this->entrySide; }
    public function getStopPrice(): ?float         { return $this->stopPrice; }
    public function getStakeAmount(): ?float       { return $this->stakeAmount; }
    public function isTaker(): bool                { return OrderType::isTaker($this->type); }
}
