<?php

namespace App\Services\Trader\Market;

/**
 * K 线 CSV 读工具（KlinesCsvWriter 的对称物）。
 *
 * 只负责：把 KlinesCsvWriter 写出来的标准 CSV → Candle[]。
 * 不负责找文件 / 不负责下载 / 不负责塞 ArrayDataProvider——那些交给上层协调（BacktestServiceProvider）。
 *
 * CSV 格式约定（和 Writer 严格对齐）：
 *   - 首行表头：timestamp,open,high,low,close,volume（跳过，用列索引而非列名——快且无歧义）
 *   - timestamp：毫秒整数，OHLCV：float
 *
 * @package App\Services\Trader\Market
 */
class KlinesCsvReader
{
    /**
     * 读取 CSV 文件 → Candle[]（校验 + 排序：timestamp 严格升序，同 ts 保留最后一行）。
     *
     * @param string $path 绝对路径
     * @return Candle[]
     * @throws \RuntimeException 文件不存在 / 打不开
     * @throws \InvalidArgumentException CSV 列数不对 / timestamp 非整数 / OHLCV 非数字
     */
    public function read(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException("KlinesCsvReader: 文件不存在 {$path}");
        }

        $fp = fopen($path, 'rb');
        if ($fp === false) {
            throw new \RuntimeException("KlinesCsvReader: 无法打开 {$path}");
        }

        $rows = [];
        $line = 0;
        try {
            // 跳表头（第一行），直接从第二行开始读
            $header = fgetcsv($fp);
            if ($header === false) {
                return [];
            }

            while (($data = fgetcsv($fp)) !== false) {
                $line++;
                if ($data === [null] || $data === []) {
                    continue; // 跳过空行
                }
                if (count($data) < 6) {
                    throw new \InvalidArgumentException(
                        "KlinesCsvReader: 第 {$line} 行列数不足 6（实际 " . count($data) . "）"
                    );
                }
                $tsRaw = trim($data[0]);
                if (!preg_match('/^-?\d+$/', $tsRaw)) {
                    throw new \InvalidArgumentException(
                        "KlinesCsvReader: 第 {$line} 行 timestamp 非整数（got {$tsRaw}）"
                    );
                }
                $rows[(int) $tsRaw] = [
                    (int)    $tsRaw,
                    (float)  $data[1],
                    (float)  $data[2],
                    (float)  $data[3],
                    (float)  $data[4],
                    (float)  $data[5],
                ];
            }
        } finally {
            fclose($fp);
        }

        if ($rows === []) {
            return [];
        }

        // 按 timestamp 升序（ksort 自动保证数值 key 升序）
        ksort($rows, SORT_NUMERIC);

        // 转 Candle 对象
        $candles = [];
        foreach (array_values($rows) as [$ts, $o, $h, $l, $c, $v]) {
            $candles[] = new Candle($ts, $o, $h, $l, $c, $v);
        }
        return $candles;
    }
}
