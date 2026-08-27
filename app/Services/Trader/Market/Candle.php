<?php

namespace App\Services\Trader\Market;

use InvalidArgumentException;

/**
 * K 线值对象（Candle / OHLCV）
 *
 * 设计：
 * - 不可变（No Setter），值对象一旦构造不能改，避免撮合引擎中被意外篡改
 * - 所有价格/成交量用 float，但打印比较时统一 round 到 8 位小数
 * - timestamp 始终为毫秒 int（与 Binance/OKX API 一致），方便按位对齐
 *
 * 关键字段顺序固定：timestamp / open / high / low / close / volume
 *   可直接用 `$candle->toArray()` 插入数据库或 CSV
 */
class Candle
{
    /** @var int 毫秒级时间戳（K 线起点） */
    private $timestamp;

    /** @var float 开盘价 */
    private $open;

    /** @var float 最高价（撮合止损/影线触及时必须用这个，不能用 close）*/
    private $high;

    /** @var float 最低价 */
    private $low;

    /** @var float 收盘价 */
    private $close;

    /** @var float 成交量（基础货币数量，BTC/USDT 对的 BTC 量）*/
    private $volume;

    /**
     * @param int   $timestamp 毫秒时间戳（K 线开盘时刻）
     * @param float $open      开盘价
     * @param float $high      最高价，必须 ≥ max(open, close, low)（构造时会自动校验）
     * @param float $low       最低价，必须 ≤ min(open, close, high)
     * @param float $close     收盘价
     * @param float $volume    成交量（≥ 0）
     */
    public function __construct(
        int $timestamp,
        float $open,
        float $high,
        float $low,
        float $close,
        float $volume
    ) {
        if ($timestamp <= 0) {
            throw new InvalidArgumentException("Candle timestamp must be positive, got {$timestamp}");
        }
        if ($volume < 0) {
            throw new InvalidArgumentException("Candle volume must be >= 0, got {$volume}");
        }
        if ($high < $open || $high < $close || $high < $low) {
            throw new InvalidArgumentException(
                "Candle high ({$high}) must be >= open({$open}) / close({$close}) / low({$low})"
            );
        }
        if ($low > $open || $low > $close || $low > $high) {
            throw new InvalidArgumentException(
                "Candle low ({$low}) must be <= open({$open}) / close({$close}) / high({$high})"
            );
        }

        $this->timestamp = $timestamp;
        $this->open = $open;
        $this->high = $high;
        $this->low = $low;
        $this->close = $close;
        $this->volume = $volume;
    }

    public function getTimestamp(): int
    {
        return $this->timestamp;
    }

    public function getOpen(): float
    {
        return $this->open;
    }

    public function getHigh(): float
    {
        return $this->high;
    }

    public function getLow(): float
    {
        return $this->low;
    }

    public function getClose(): float
    {
        return $this->close;
    }

    public function getVolume(): float
    {
        return $this->volume;
    }

    /**
     * 转换为标准 PHP 数组（方便存入数据库 / CSV / JSON）
     *
     * @return array{timestamp:int,open:float,high:float,low:float,close:float,volume:float}
     */
    public function toArray(): array
    {
        return [
            'timestamp' => $this->timestamp,
            'open'      => $this->open,
            'high'      => $this->high,
            'low'       => $this->low,
            'close'     => $this->close,
            'volume'    => $this->volume,
        ];
    }

    /**
     * 从数组反构造（数据库/CSV 读取后常用）
     *
     * @param array{timestamp:int,open:float,high:float,low:float,close:float,volume:float} $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            (int)    ($row['timestamp'] ?? 0),
            (float)  ($row['open']      ?? 0.0),
            (float)  ($row['high']      ?? 0.0),
            (float)  ($row['low']       ?? 0.0),
            (float)  ($row['close']     ?? 0.0),
            (float)  ($row['volume']    ?? 0.0)
        );
    }

    /**
     * 返回 Y-m-d H:i:s 格式的开盘时间（仅用于日志/调试）
     */
    public function getTimeString(): string
    {
        return gmdate('Y-m-d H:i:s', (int) ($this->timestamp / 1000));
    }
}
