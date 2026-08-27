<?php

namespace App\Services\Exchanges;

/**
 * 统一交易对标准格式
 *
 * 参考 CCXT 统一格式，设计为易读、易管理的结构化交易对表示。
 * 上层业务代码统一使用此格式，适配器内部自动转换为各交易所原生格式。
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │                         标准格式规范                                    │
 * ├──────────────────────────────┬──────────────────────────────────────────┤
 * │ 格式                         │ 说明                                     │
 * ├──────────────────────────────┼──────────────────────────────────────────┤
 * │ BTC/USDT                     │ 现货（Spot）                             │
 * │ BTC/USDT:SWAP                │ 永续合约（U本位，USDT 计价）              │
 * │ BTC/USD:SWAP                 │ 永续合约（币本位，BTC 结算）              │
 * │ BTC/USDT:THIS_WEEK           │ 交割合约 - 本周（本周五到期）              │
 * │ BTC/USDT:NEXT_WEEK           │ 交割合约 - 下周（下周五到期）              │
 * │ BTC/USDT:QUARTER             │ 交割合约 - 当季（本季末月最后一个周五）    │
 * │ BTC/USDT:BI_QUARTER          │ 交割合约 - 次季（下季末月最后一个周五）    │
 * │ BTC/USDT:CI_QUARTER          │ 交割合约 - 第三季（下下季末月最后一个周五）│
 * │ BTC/USDT:FUT-250328          │ 交割合约 - 指定日期（2025-03-28 到期）    │
 * └──────────────────────────────┴──────────────────────────────────────────┘
 *
 * 交割时间规则（Binance 和 OKX 一致）：
 *   - 周合约：每周五 08:00 UTC（16:00 UTC+8）交割
 *   - 季度合约：3/6/9/12 月最后一个周五 08:00 UTC 交割
 *
 * 各交易所原生格式对照：
 *
 * ┌────────────┬─────────────┬──────────────────┬────────────────────┐
 * │ 交易所     │ 现货        │ 永续合约          │ 交割合约            │
 * ├────────────┼─────────────┼──────────────────┼────────────────────┤
 * │ Binance    │ BTCUSDT     │ BTCUSDT (端点不同)│ BTCUSDT_250328     │
 * │ OKX        │ BTC-USDT    │ BTC-USDT-SWAP    │ BTC-USDT-250328    │
 * └────────────┴─────────────┴──────────────────┴────────────────────┘
 *
 * 使用示例：
 *   $symbol = TradingSymbol::parse('BTC/USDT:QUARTER');
 *   echo $symbol->getBase();              // BTC
 *   echo $symbol->getQuote();             // USDT
 *   echo $symbol->getResolvedDeliveryDate(); // 250926
 *
 *   // 交易所格式转换请使用 Formatters\SymbolFormatterInterface 实现
 *   // @see \App\Services\Exchanges\Formatters\SymbolFormatterInterface
 */
class TradingSymbol
{
    /** @var string 产品类型：现货 */
    public const TYPE_SPOT = 'spot';

    /** @var string 产品类型：永续合约 */
    public const TYPE_SWAP = 'swap';

    /** @var string 产品类型：交割合约 */
    public const TYPE_FUTURES = 'futures';

    /** @var string 交割周期：本周 */
    public const PERIOD_THIS_WEEK = 'this_week';

    /** @var string 交割周期：下周 */
    public const PERIOD_NEXT_WEEK = 'next_week';

    /** @var string 交割周期：当季 */
    public const PERIOD_QUARTER = 'quarter';

    /** @var string 交割周期：次季 */
    public const PERIOD_BI_QUARTER = 'bi_quarter';

    /** @var string 交割周期：第三季 */
    public const PERIOD_CI_QUARTER = 'ci_quarter';

    /** @var array 交割周期别名映射（大写 → 常量） */
    private const PERIOD_ALIASES = [
        'THIS_WEEK'   => self::PERIOD_THIS_WEEK,
        'NEXT_WEEK'   => self::PERIOD_NEXT_WEEK,
        'QUARTER'     => self::PERIOD_QUARTER,
        'BI_QUARTER'  => self::PERIOD_BI_QUARTER,
        'CI_QUARTER'  => self::PERIOD_CI_QUARTER,
    ];

    /** @var string 基础货币（如 BTC） */
    protected string $base;

    /** @var string 计价货币（如 USDT） */
    protected string $quote;

    /** @var string 产品类型 spot|swap|futures */
    protected string $type;

    /** @var string|null 交割日期 YYMMDD（仅交割合约，显式指定时） */
    protected ?string $deliveryDate;

    /** @var string|null 交割周期别名（仅交割合约，用周期别名时） */
    protected ?string $deliveryPeriod;

    /**
     * 构造方法
     *
     * @param string $base           基础货币，如 BTC
     * @param string $quote          计价货币，如 USDT
     * @param string $type           产品类型 spot|swap|futures
     * @param string|null $deliveryDate    交割日期 YYMMDD（显式指定）
     * @param string|null $deliveryPeriod  交割周期别名（周期别名指定）
     */
    public function __construct(
        string $base,
        string $quote,
        string $type = self::TYPE_SPOT,
        ?string $deliveryDate = null,
        ?string $deliveryPeriod = null
    ) {
        $this->base = strtoupper($base);
        $this->quote = strtoupper($quote);
        $this->type = strtolower($type);
        $this->deliveryDate = $deliveryDate;
        $this->deliveryPeriod = $deliveryPeriod;
    }

    /**
     * 从标准格式字符串解析为 TradingSymbol 对象
     *
     * 支持的格式：
     *   BTC/USDT                  → 现货
     *   BTC/USDT:SWAP             → U本位永续
     *   BTC/USD:SWAP              → 币本位永续
     *   BTC/USDT:THIS_WEEK        → 交割合约-本周
     *   BTC/USDT:NEXT_WEEK        → 交割合约-下周
     *   BTC/USDT:QUARTER          → 交割合约-当季
     *   BTC/USDT:BI_QUARTER       → 交割合约-次季
     *   BTC/USDT:CI_QUARTER       → 交割合约-第三季
     *   BTC/USDT:FUT-250328       → 交割合约-指定日期
     *
     * @param string $symbol 标准格式交易对
     * @return self
     * @throws \InvalidArgumentException 格式非法时抛出
     */
    public static function parse(string $symbol): self
    {
        $symbol = trim($symbol);
        if ($symbol === '') {
            throw new \InvalidArgumentException("Symbol cannot be empty");
        }

        $type = self::TYPE_SPOT;
        $deliveryDate = null;
        $deliveryPeriod = null;
        $pairPart = $symbol;

        // 检查是否包含类型分隔符
        if (strpos($symbol, ':') !== false) {
            $parts = explode(':', $symbol, 2);
            $pairPart = $parts[0];
            $typePart = strtoupper($parts[1]);

            if ($typePart === 'SWAP') {
                // 永续合约
                $type = self::TYPE_SWAP;
            } elseif (isset(self::PERIOD_ALIASES[$typePart])) {
                // 交割合约 - 周期别名方式
                $type = self::TYPE_FUTURES;
                $deliveryPeriod = self::PERIOD_ALIASES[$typePart];
            } elseif (strpos($typePart, 'FUT-') === 0) {
                // 交割合约 - 显式日期方式
                $type = self::TYPE_FUTURES;
                $deliveryDate = substr($parts[1], 4);
                if (!preg_match('/^\d{6,8}$/', $deliveryDate)) {
                    throw new \InvalidArgumentException(
                        "Invalid delivery date format in symbol: {$symbol}, expected FUT-YYMMDD or FUT-YYYYMMDD"
                    );
                }
            } else {
                throw new \InvalidArgumentException(
                    "Unknown instrument type in symbol: {$symbol}, "
                    . "expected :SWAP, :THIS_WEEK, :NEXT_WEEK, :QUARTER, :BI_QUARTER, :CI_QUARTER or :FUT-YYMMDD"
                );
            }
        }

        // 拆分基础/计价货币
        if (strpos($pairPart, '/') === false) {
            throw new \InvalidArgumentException(
                "Invalid symbol format: {$symbol}, expected BASE/QUOTE (e.g. BTC/USDT)"
            );
        }

        [$base, $quote] = explode('/', $pairPart, 2);

        if ($base === '' || $quote === '') {
            throw new \InvalidArgumentException(
                "Base and quote currency cannot be empty in symbol: {$symbol}"
            );
        }

        return new self($base, $quote, $type, $deliveryDate, $deliveryPeriod);
    }

    /**
     * 转换为标准格式字符串
     *
     * 如果使用周期别名，保留别名；如果使用显式日期，保留日期。
     *
     * @return string
     */
    public function __toString(): string
    {
        $result = $this->base . '/' . $this->quote;

        if ($this->type === self::TYPE_SWAP) {
            $result .= ':SWAP';
        } elseif ($this->type === self::TYPE_FUTURES) {
            if ($this->deliveryPeriod !== null) {
                // 周期别名方式：输出大写别名
                $result .= ':' . strtoupper($this->deliveryPeriod);
            } elseif ($this->deliveryDate !== null) {
                $result .= ':FUT-' . $this->deliveryDate;
            }
        }

        return $result;
    }

    // ==================== 属性访问 ====================

    public function getBase(): string
    {
        return $this->base;
    }

    public function getQuote(): string
    {
        return $this->quote;
    }

    public function getType(): string
    {
        return $this->type;
    }

    /**
     * 获取显式指定的交割日期（不含周期别名推算的）
     */
    public function getDeliveryDate(): ?string
    {
        return $this->deliveryDate;
    }

    /**
     * 获取交割周期别名
     */
    public function getDeliveryPeriod(): ?string
    {
        return $this->deliveryPeriod;
    }

    /**
     * 获取解析后的交割日期
     *
     * 如果是周期别名方式，自动推算出实际交割日期。
     * 如果是显式日期方式，直接返回该日期。
     *
     * @return string|null YYMMDD 格式的交割日期
     */
    public function getResolvedDeliveryDate(): ?string
    {
        // 显式日期优先
        if ($this->deliveryDate !== null) {
            return $this->deliveryDate;
        }

        // 周期别名方式：推算日期
        if ($this->deliveryPeriod !== null) {
            return $this->resolveDeliveryDate($this->deliveryPeriod);
        }

        return null;
    }

    /**
     * 判断是否为现货
     */
    public function isSpot(): bool
    {
        return $this->type === self::TYPE_SPOT;
    }

    /**
     * 判断是否为永续合约
     */
    public function isSwap(): bool
    {
        return $this->type === self::TYPE_SWAP;
    }

    /**
     * 判断是否为交割合约
     */
    public function isFutures(): bool
    {
        return $this->type === self::TYPE_FUTURES;
    }

    /**
     * 将显式交割日期归一化为周期别名（若匹配）
     *
     * 当从交易所原生格式反向解析出交割合约时，日期是显式的（如 FUT-260925）。
     * 但本地系统的标准格式优先使用周期别名（QUARTER 等），
     * 此方法将显式日期与 5 个周期别名的推算日期逐一比较，
     * 匹配成功则将 $deliveryDate 清空，改用 $deliveryPeriod 表示，
     * 这样 __toString() 输出就会是 :QUARTER 而不是 :FUT-日期。
     *
     * 匹配顺序：this_week → next_week → quarter → bi_quarter → ci_quarter
     * （因为 next_week 有可能与某个季度合约的最后一个周五恰好重合，
     *   按"就近优先"原则匹配小的周期。）
     *
     * @return bool 是否成功归一化为周期别名
     */
    public function normalizeToPeriodAlias(): bool
    {
        if ($this->type !== self::TYPE_FUTURES) {
            return false;
        }
        if ($this->deliveryPeriod !== null) {
            // 已经是周期别名，无需再归一化
            return true;
        }
        if ($this->deliveryDate === null) {
            return false;
        }

        $periods = [
            self::PERIOD_THIS_WEEK,
            self::PERIOD_NEXT_WEEK,
            self::PERIOD_QUARTER,
            self::PERIOD_BI_QUARTER,
            self::PERIOD_CI_QUARTER,
        ];

        foreach ($periods as $period) {
            $resolvedDate = $this->resolveDeliveryDate($period);
            if ($resolvedDate === $this->deliveryDate) {
                $this->deliveryPeriod = $period;
                $this->deliveryDate = null;
                return true;
            }
        }

        // 不匹配任何周期别名，保留显式日期
        return false;
    }

    // ==================== 交割周期日期推算 ====================

    /**
     * 根据周期别名推算交割日期
     *
     * 交割规则（Binance 和 OKX 一致）：
     *   - 周合约：每周五 08:00 UTC 交割
     *   - 季度合约：3/6/9/12 月最后一个周五 08:00 UTC 交割
     *
     * 周期别名与交割日期的对应关系：
     *   this_week   → 离当前最近的那个周五（本周五）
     *   next_week   → 下一个周五（下周五）
     *   quarter     → 当前日历季度的最后一个周五
     *   bi_quarter  → 下一个日历季度的最后一个周五
     *   ci_quarter   → 下两个日历季度的最后一个周五
     *
     * 注意：交割时间点为周五 08:00 UTC，如果当前时间已过本周五 08:00 UTC，
     * 则本周合约已交割，this_week 自动顺延到下周五。
     *
     * @param string $period 周期别名常量
     * @return string YYMMDD 格式的交割日期
     */
    public function resolveDeliveryDate(string $period): string
    {
        // 当前 UTC 时间戳
        $now = time();

        // 交割时间点：周五 08:00 UTC
        $deliveryHour = 8;

        switch ($period) {
            case self::PERIOD_THIS_WEEK:
                // 本周五 08:00 UTC
                $friday = $this->findUpcomingFriday($now, $deliveryHour);
                return gmdate('ymd', $friday);

            case self::PERIOD_NEXT_WEEK:
                // 下周五 08:00 UTC
                $friday = $this->findUpcomingFriday($now, $deliveryHour);
                // 加 7 天
                $nextFriday = $friday + (7 * 86400);
                return gmdate('ymd', $nextFriday);

            case self::PERIOD_QUARTER:
                // 当前季度的最后一个周五
                return $this->findQuarterLastFriday($now, 0);

            case self::PERIOD_BI_QUARTER:
                // 下一个季度的最后一个周五
                return $this->findQuarterLastFriday($now, 1);

            case self::PERIOD_CI_QUARTER:
                // 下两个季度的最后一个周五
                return $this->findQuarterLastFriday($now, 2);

            default:
                throw new \InvalidArgumentException("Unknown delivery period: {$period}");
        }
    }

    /**
     * 找到即将到来的周五（含今天，如果还未到交割时间）
     *
     * 如果当前时间在本周五 08:00 UTC 之前，返回本周五；
     * 如果已过本周五 08:00 UTC，返回下周五。
     *
     * @param int $timestamp 当前时间戳
     * @param int $hour 交割小时（UTC）
     * @return int 周五的 UTC 时间戳
     */
    private function findUpcomingFriday(int $timestamp, int $hour): int
    {
        // 获取当前 UTC 日期信息
        $dow = (int) gmdate('N', $timestamp); // 1=周一, 7=周日
        $day = (int) gmdate('j', $timestamp);
        $month = (int) gmdate('n', $timestamp);
        $year = (int) gmdate('Y', $timestamp);

        // 计算到本周五的天数差（周五=5）
        $daysToFriday = 5 - $dow;
        if ($daysToFriday < 0) {
            $daysToFriday += 7;
        }

        // 构造本周五 08:00 UTC 的时间戳
        $fridayTs = gmmktime($hour, 0, 0, $month, $day + $daysToFriday, $year);

        // 如果本周五交割时间已过，顺延到下周五
        if ($fridayTs <= $timestamp) {
            $fridayTs += 7 * 86400;
        }

        return $fridayTs;
    }

    /**
     * 找到指定季度偏移的最后一个周五
     *
     * 季度划分：
     *   Q1: 1-3月  Q2: 4-6月  Q3: 7-9月  Q4: 10-12月
     *
     * @param int $timestamp 当前时间戳
     * @param int $offset 季度偏移量（0=当季, 1=下季, 2=下下季）
     * @return string YYMMDD 格式的日期
     */
    private function findQuarterLastFriday(int $timestamp, int $offset): string
    {
        $month = (int) gmdate('n', $timestamp);
        $year = (int) gmdate('Y', $timestamp);

        // 当前季度（1-4）
        $currentQuarter = (int) ceil($month / 3);

        // 目标季度
        $targetQuarter = $currentQuarter + $offset;

        // 处理跨年
        while ($targetQuarter > 4) {
            $targetQuarter -= 4;
            $year++;
        }

        // 目标季度的末月（Q1=3, Q2=6, Q3=9, Q4=12）
        $endMonth = $targetQuarter * 3;

        // 找到该月的最后一个周五
        $lastDayOfMonth = (int) gmdate('t', gmmktime(0, 0, 0, $endMonth, 1, $year));

        // 从月末倒推找周五
        for ($day = $lastDayOfMonth; $day >= 1; $day--) {
            $ts = gmmktime(8, 0, 0, $endMonth, $day, $year);
            if ((int) gmdate('N', $ts) === 5) {
                // 找到最后一个周五
                return gmdate('ymd', $ts);
            }
        }

        // 理论上不会走到这里
        throw new \RuntimeException("Cannot find last Friday of month {$endMonth}/{$year}");
    }

}
