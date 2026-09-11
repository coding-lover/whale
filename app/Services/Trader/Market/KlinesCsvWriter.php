<?php

namespace App\Services\Trader\Market;

/**
 * K 线 CSV 写工具（面向回测/样本种子数据的标准持久化格式）。
 *
 * 文件格式约定：
 *   - 首行必含表头：timestamp,open,high,low,close,volume
 *   - timestamp 列：毫秒级 Unix 时间戳（整数，和 Binance/OKX 适配器返回一致）
 *   - OHLCV 数值：float，高精度（不用 number_format 截断）
 *   - 编码：UTF-8，LF 换行（fputcsv 默认）
 *   - 去重 & 升序：写入前按 timestamp 去重（保留最后出现的那行）+ 升序排序
 *
 * 输出路径：{baseDir}/{exchange}/{symbol}_{interval}.csv
 *   （例：<RUNTIME_PATH>/trader/data/binance/BTC-USDT_1h.csv）
 *   symbol 用 TradingSymbol 的 BASE-QUOTE 安全形式替换非法的 '/'，':' 也替换避免跨平台文件系统兼容。
 *
 * ⭐ 与 ArrayDataProvider + Trader module 兼容：ArrayDataProvider 通过 setCandles(symbol,tf,rows) 接受
 *   [[ts,o,h,l,c,v], …]，本类 write 返回的 CSV 读回来再转就能原样塞入。
 *
 * @package App\Services\Trader\Market
 */
class KlinesCsvWriter
{
    /** 表头（严格顺序，与 ExchangeInterface::getKlines 6 列对齐）*/
    protected const HEADERS = ['timestamp', 'open', 'high', 'low', 'close', 'volume'];

    /**
     * 把原始 K 线写入 CSV。返回最终写入的绝对路径 + 记录数（去重后）。
     *
     * @param string $baseDir   基础目录（例如 RUNTIME_PATH . '/trader/data'）
     * @param string $exchange  交易所名（binance/okx ……），小写，会用作子目录
     * @param string $symbol    标准交易对字符串，如 BTC/USDT、BTC/USDT:SWAP
     * @param string $interval  标准周期，如 1m/5m/15m/1h/4h/1d
     * @param list<list{0:int,1:float,2:float,3:float,4:float,5:float}> $klines  [[timestamp,o,h,l,c,v],...]
     *
     * @return array{0:string, 1:int}  [path, writtenRowCount]
     *
     * @throws \InvalidArgumentException 数据列数不对 / timestamp 非整数 / OHLCV 非数字
     */
    public function write(string $baseDir, string $exchange, string $symbol, string $interval, array $klines): array
    {
        // ---- 校验：入参 ----
        if ($exchange === '' || $symbol === '' || $interval === '') {
            throw new \InvalidArgumentException(
                'KlinesCsvWriter: exchange/symbol/interval 不能为空'
            );
        }

        // ---- 校验 & 规范化：K 线 ----
        $normalized = $this->normalizeAndValidate($klines);

        // ---- 路径组装：确保目标目录存在 ----
        $dir = rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR . strtolower($exchange);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("KlinesCsvWriter: 无法创建目录 {$dir}（权限问题？）");
        }
        $filename = $this->buildFilename($symbol, $interval);
        $path = $dir . DIRECTORY_SEPARATOR . $filename;

        // ---- 写入 CSV ----
        $fp = fopen($path, 'wb');
        if ($fp === false) {
            throw new \RuntimeException("KlinesCsvWriter: 无法打开文件写入 {$path}");
        }
        try {
            // 写表头
            fputcsv($fp, self::HEADERS);
            foreach ($normalized as $row) {
                // 保持 timestamp 为 int 字符串（不加小数点），其他保持字符串形式的 float
                fputcsv($fp, [
                    (string) $row[0],
                    self::numberToCsv($row[1]),
                    self::numberToCsv($row[2]),
                    self::numberToCsv($row[3]),
                    self::numberToCsv($row[4]),
                    self::numberToCsv($row[5]),
                ]);
            }
        } finally {
            fclose($fp);
        }

        return [$path, count($normalized)];
    }

    /**
     * 生成安全文件名：把 '/', ':', '\\', '|' 等文件系统非法字符替换为 '-'。
     *   BTC/USDT       → BTC-USDT_1h.csv
     *   BTC/USDT:SWAP  → BTC-USDT-SWAP_1h.csv
     */
    public function buildFilename(string $symbol, string $interval): string
    {
        $safeSymbol = preg_replace('#[\\s/:\\\\|?*"<>]+#u', '-', trim($symbol));
        $safeInterval = preg_replace('#[^A-Za-z0-9]+#', '', trim($interval));
        return sprintf('%s_%s.csv', $safeSymbol, $safeInterval);
    }

    // ------------------------------------------------------------------------
    //  内部工具
    // ------------------------------------------------------------------------

    /**
     * 校验每一行是「6 列 [ts,o,h,l,c,v]」→ 类型转换 → 按 ts 升序去重。
     *
     * @return list<array{0:int,1:float,2:float,3:float,4:float,5:float}>
     */
    private function normalizeAndValidate(array $klines): array
    {
        $byTs = [];
        foreach ($klines as $idx => $row) {
            if (!is_array($row) || count($row) < 6) {
                throw new \InvalidArgumentException(sprintf(
                    'KlinesCsvWriter: 第 %d 根 K 线格式错误，必须是长度 ≥6 的 [ts,o,h,l,c,v] 数组，实际 = %s',
                    $idx,
                    var_export($row, true)
                ));
            }
            [$ts, $o, $h, $l, $c, $v] = array_values($row);
            if (!self::isIntLike($ts)) {
                throw new \InvalidArgumentException(
                    "KlinesCsvWriter: 第 {$idx} 根时间戳必须是整数毫秒（got: " . var_export($ts, true) . "）"
                );
            }
            foreach (['o' => $o, 'h' => $h, 'l' => $l, 'c' => $c, 'v' => $v] as $tag => $num) {
                if (!is_numeric($num)) {
                    throw new \InvalidArgumentException(
                        "KlinesCsvWriter: 第 {$idx} 根 {$tag} 必须是数字（got: " . var_export($num, true) . "）"
                    );
                }
            }
            $tsInt = (int) $ts;
            $byTs[$tsInt] = [
                $tsInt,
                (float) $o,
                (float) $h,
                (float) $l,
                (float) $c,
                (float) $v,
            ];
        }
        // 按 timestamp 升序；key 排序
        ksort($byTs, SORT_NUMERIC);

        // 验证：升序（ksort 之后不可能有逆序，这里冗余双保险）
        $prev = -1;
        foreach (array_keys($byTs) as $t) {
            if ($prev >= 0 && $t <= $prev) {
                throw new \RuntimeException(
                    "KlinesCsvWriter: 去重后时间戳不严格递增（相邻 {$prev} 与 {$t}）——请检查源数据"
                );
            }
            $prev = $t;
        }
        return array_values($byTs);
    }

    /**
     * 数字 → CSV 字符串。禁止 json_encode 对极小/极大浮点数输出的科学计数法（1.2e-7），
     * 因为绝大多数 CSV 工具链（R、pandas、ArrayDataProvider 自己加载）读到 '1.2e-7'
     * 会被当字符串或解析失败。
     *
     * 实现：
     *   1. 先 json_encode 让普通整数/小数输出稳定十进制；
     *   2. 如果结果里含 e/E 科学计数法，则用 sprintf('%.12f') 拿到高定点小数再 rtrim 去尾零；
     *      负数要保留 "-0.00000012" 前面的负号，strip 后不要留下 '.'。
     *
     * @param mixed $num 已在校验阶段保证为 numeric
     */
    private static function numberToCsv($num): string
    {
        $v = (float) $num;
        $encoded = json_encode($v, JSON_UNESCAPED_UNICODE);
        if ($encoded !== false && !preg_match('/[eE]/', $encoded)) {
            return $encoded;
        }
        // 回退：用高精度 sprintf + rtrim 去尾 0/点
        $formatted = number_format($v, 12, '.', '');
        // 去尾零
        $formatted = rtrim($formatted, '0');
        $formatted = rtrim($formatted, '.');
        if ($formatted === '' || $formatted === '-') {
            return '0';
        }
        return $formatted;
    }

    /** "像整数"校验：int 本身 / string 形式的纯数字（如 "1715472000000"）*/
    private static function isIntLike($v): bool
    {
        if (is_int($v)) {
            return true;
        }
        if (is_bool($v)) {
            return false;
        }
        if (is_float($v)) {
            return $v === (float) (int) $v && !is_nan($v) && !is_infinite($v);
        }
        if (is_string($v)) {
            return (bool) preg_match('/^-?\d+$/', $v);
        }
        return false;
    }
}
