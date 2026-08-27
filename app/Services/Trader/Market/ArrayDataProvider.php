<?php

namespace App\Services\Trader\Market;

use App\Services\Exchanges\TradingSymbol;
use InvalidArgumentException;

/**
 * 基于 PHP 数组 / CSV 的内存级 DataProvider（回测模式默认实现）
 *
 * 直接把预先加载的 K 线存在内存里：
 *   - 回测速度最快，无任何 I/O
 *   - 不依赖任何外部交易所（离线可跑，CI 里也能跑）
 *   - 构造时会自动校验时间戳严格升序 + 周期对齐
 *
 * 使用示例：
 *   $provider = new ArrayDataProvider();
 *   $provider->setCandles(
 *       TradingSymbol::parse('BTC/USDT'),
 *       Timeframe::TF_5M,
 *       array_map(fn($r) => Candle::fromArray($r), $rows)
 *   );
 */
class ArrayDataProvider implements DataProviderInterface
{
    /**
     * 二级 Map: [symbol_string][timeframe] => Candle[]
     *
     * @var array<string, array<string, Candle[]>>
     */
    private $store = [];

    /**
     * 手动设置某个 pair + timeframe 的 K 线序列
     *
     * 会自动校验：
     *   - 时间戳严格升序（禁止重复/乱序，否则撮合会出问题）
     *   - 相邻时间戳严格等于 timeframe（避免"缺 K 线"导致策略行为异常）
     *     （可以通过 $allowGaps = true 跳过检查）
     *
     * @param Candle[] $candles
     * @throws InvalidArgumentException 校验失败
     */
    public function setCandles(
        TradingSymbol $symbol,
        string $timeframe,
        array $candles,
        bool $allowGaps = false
    ): void {
        if (!Timeframe::isValid($timeframe)) {
            throw new InvalidArgumentException("Invalid timeframe: {$timeframe}");
        }

        if ($candles === []) {
            throw new InvalidArgumentException("Candles for {$symbol} {$timeframe} cannot be empty");
        }

        // 类型校验：每个元素都必须是 Candle
        foreach ($candles as $i => $c) {
            if (!$c instanceof Candle) {
                $type = is_object($c) ? get_class($c) : gettype($c);
                throw new InvalidArgumentException(
                    "Candle #{$i} must be instance of " . Candle::class . ", got {$type}"
                );
            }
        }

        // 严格升序 + 间隔检查
        $stepMs = Timeframe::toMilliseconds($timeframe);
        $prevTs = null;
        foreach ($candles as $i => $c) {
            $ts = $c->getTimestamp();
            if ($prevTs !== null) {
                if ($ts <= $prevTs) {
                    throw new InvalidArgumentException(
                        "Candles must be strictly ordered. Candle #{$i} ts={$ts} is not after previous {$prevTs}"
                    );
                }
                if (!$allowGaps && ($ts - $prevTs) !== $stepMs) {
                    $expected = $prevTs + $stepMs;
                    $prevDt = gmdate('Y-m-d H:i:s', (int) ($prevTs / 1000));
                    $thisDt = gmdate('Y-m-d H:i:s', (int) ($ts / 1000));
                    throw new InvalidArgumentException(
                        "Gap detected between candle #".($i - 1)." ({$prevDt}) and #{$i} ({$thisDt}). "
                        . "Expected exactly {$stepMs}ms, got " . ($ts - $prevTs) . "ms. "
                        . "Set allowGaps=true if you want to skip this validation."
                    );
                }
            }
            // 同时验证 timestamp 是否已按 TF 对齐到起点
            $floored = Timeframe::floorTimestamp($timeframe, $ts);
            if ($floored !== $ts) {
                $dt = gmdate('Y-m-d H:i:s', (int) ($ts / 1000));
                $fDt = gmdate('Y-m-d H:i:s', (int) ($floored / 1000));
                throw new InvalidArgumentException(
                    "Candle #{$i} timestamp ({$dt}) is not aligned to {$timeframe} boundary (expected {$fDt})"
                );
            }
            $prevTs = $ts;
        }

        $key = (string) $symbol;
        $this->store[$key][$timeframe] = array_values($candles);
    }

    public function getCandles(
        TradingSymbol $symbol,
        string $timeframe,
        ?int $fromMs = null,
        ?int $toMs = null
    ): array {
        $key = (string) $symbol;
        if (!isset($this->store[$key][$timeframe])) {
            throw new InvalidArgumentException("No data loaded for {$symbol} {$timeframe}");
        }
        $all = $this->store[$key][$timeframe];

        if ($fromMs === null && $toMs === null) {
            return $all;
        }
        $result = [];
        foreach ($all as $c) {
            $ts = $c->getTimestamp();
            if ($fromMs !== null && $ts < $fromMs) {
                continue;
            }
            if ($toMs !== null && $ts > $toMs) {
                break;
            }
            $result[] = $c;
        }
        return $result;
    }

    public function getAvailableRange(TradingSymbol $symbol, string $timeframe): array
    {
        $list = $this->getCandles($symbol, $timeframe);
        return [$list[0]->getTimestamp(), $list[count($list) - 1]->getTimestamp()];
    }

    /**
     * @return TradingSymbol[]
     */
    public function getAvailableSymbols(): array
    {
        $symbols = [];
        foreach (array_keys($this->store) as $key) {
            $symbols[] = TradingSymbol::parse($key);
        }
        return $symbols;
    }

    public function hasEnoughData(TradingSymbol $symbol, string $timeframe, int $minCandles): bool
    {
        $key = (string) $symbol;
        if (!isset($this->store[$key][$timeframe])) {
            return false;
        }
        return count($this->store[$key][$timeframe]) >= $minCandles;
    }
}
