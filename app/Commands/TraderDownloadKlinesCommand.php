<?php

namespace App\Commands;

use App\Services\Exchanges\ExchangeInterface;
use App\Services\Trader\Market\KlinesCsvWriter;
use Sikelan\Command\CommandInterface;
use Sikelan\Command\CommandManager;

/**
 * 命令：trader:download-klines
 *
 * 用途：通过 ExchangeManager（即用户说的 exchanges services）从指定交易所下载
 *       历史 K 线，保存到 RUNTIME_PATH/trader/data/<exchange>/<symbol>_<interval>.csv。
 *
 * ⭐ 默认参数就是用户要求的：binance / BTC/USDT / 1h / 最近 7 天
 *    所以直接 `php bin/sikelan trader:download-klines` 就能跑起来，不必每次写一堆参数。
 *
 * 参数（--key=value 形式，兼容 CommandManager::getOpt）：
 *   --exchange=binance|okx           交易所名称（默认 binance）
 *   --symbol=BTC/USDT                标准交易对（默认 BTC/USDT）
 *   --interval=1m|5m|15m|30m|1h|4h|1d|1w   K 线周期（默认 1h）
 *   --days=7                         下载最近多少自然天（默认 7；和 --from/--to 二选一）
 *   --from=YYYY-MM-DD                起始日期（含）
 *   --to=YYYY-MM-DD                  结束日期（含）
 *   --page-limit=1000                单页数量（Binance 最大 1000，默认 1000）
 *   --output-dir=<path>              自定义输出目录（默认 RUNTIME_PATH/trader/data）
 *   --dry-run                        只解析参数不下载不写盘，用于排错
 *
 * 分页策略：
 *   Binance /api/v3/klines 一次最多 1000 根；7 天 1h = 168 根，不会触发分页。
 *   为了通用性（如 --days=365 / 1m），本命令会按「结束时间 ← 每次 pageLimit，
 *   直到覆盖到起始时间前」循环拉取，每段起止时间用 [startTime, endTime) 防止重复，
 *   最后统一 KlinesCsvWriter 去重、排序。
 *
 * @package App\Commands
 */
class TraderDownloadKlinesCommand implements CommandInterface
{
    /** 允许的 K 线周期（和 ExchangeInterface 注释一致）*/
    protected const VALID_INTERVALS = ['1m', '5m', '15m', '30m', '1h', '4h', '1d', '1w'];

    /** 每个 interval 对应的毫秒数，用于分页推进 */
    protected const INTERVAL_MS = [
        '1m' => 60_000,
        '5m' => 300_000,
        '15m' => 900_000,
        '30m' => 1_800_000,
        '1h' => 3_600_000,
        '4h' => 14_400_000,
        '1d' => 86_400_000,
        '1w' => 604_800_000,
    ];

    /**
     * 单页最大根数（Binance API 限制 1000；OKX 也基本兼容此量级。
     * 用户可调 --page-limit，但命令内部取 min(用户值, 1000)）。
     */
    protected const MAX_PAGE_LIMIT = 1000;

    public function commandName(): string
    {
        return 'trader:download-klines';
    }

    public function desc(): string
    {
        return '从交易所服务下载历史K线，CSV 保存到 runtime/trader/data（默认 binance BTC/USDT 1h 近7天）';
    }

    public function exec(array $args): ?string
    {
        $cmd = CommandManager::getInstance();

        // ---- 参数解析 ----
        $exchangeName = (string) ($cmd->getOpt('--exchange', (string) getenv('DOWNLOAD_EXCHANGE')) ?: 'binance');
        $symbol       = (string) ($cmd->getOpt('--symbol',   (string) getenv('DOWNLOAD_SYMBOL'))   ?: 'BTC/USDT');
        $interval     = (string) ($cmd->getOpt('--interval', (string) getenv('DOWNLOAD_INTERVAL')) ?: '1h');
        $days         = (int)    ($cmd->getOpt('--days',     (string) getenv('DOWNLOAD_DAYS'))     ?: 7);
        $fromStr      = (string) $cmd->getOpt('--from', '');
        $toStr        = (string) $cmd->getOpt('--to',   '');
        $pageLimit    = (int)    ($cmd->getOpt('--page-limit', '') ?: self::MAX_PAGE_LIMIT);
        $retries      = (int)    ($cmd->getOpt('--retries',    (string) getenv('DOWNLOAD_RETRIES'))    ?: 5);
        $retryBase    = (float)  ($cmd->getOpt('--retry-base', (string) getenv('DOWNLOAD_RETRY_BASE')) ?: 1.2);
        $outputDir    = (string) ($cmd->getOpt('--output-dir', '') ?: (
            defined('RUNTIME_PATH') ? RUNTIME_PATH . '/trader/data' : sys_get_temp_dir() . '/sikelan_klines'
        ));
        $dryRun       = $cmd->getOpt('--dry-run', null) !== null;

        // ---- 参数校验 ----
        if (!in_array($interval, self::VALID_INTERVALS, true)) {
            return $this->error(sprintf(
                '--interval 不合法：%s。支持：%s',
                $interval,
                implode(', ', self::VALID_INTERVALS)
            ));
        }
        if ($days <= 0 && $fromStr === '') {
            return $this->error('--days 必须 > 0，或使用 --from/--to 指定起止日期');
        }
        $pageLimit = max(1, min($pageLimit, self::MAX_PAGE_LIMIT));
        if ($retries < 1) {
            return $this->error('--retries 必须 ≥ 1（表示「第一次尝试也算重试」？不，这里的语义是"最大总尝试次数"，1 = 只试 1 次，失败就抛）');
        }
        if ($retryBase <= 0) {
            return $this->error('--retry-base（指数退避秒数底数）必须 > 0，例如 1.2');
        }

        // ---- 解析时间窗口 [startMs, endMs] ----
        [$startMs, $endMs] = $this->resolveTimeWindow($days, $fromStr, $toStr);
        $intervalMs = self::INTERVAL_MS[$interval];

        // ---- 估算总根数（给用户展示） ----
        $estimated = (int) ceil(($endMs - $startMs) / $intervalMs);

        $header = $this->info(
            "准备下载 %s · %s · %s · %s（%s ~ %s，约 %d 根）",
            strtolower($exchangeName),
            $symbol,
            $interval,
            '最近 ' . $days . ' 天',
            self::msToIso($startMs),
            self::msToIso($endMs),
            $estimated
        );
        if ($dryRun) {
            return $header . "\n\033[33m[DRY-RUN]\033[0m 未实际下载。去掉 --dry-run 即可执行。\n"
                . "输出目录：{$outputDir}\n";
        }

        // ---- 取交易所服务 ----
        $exchange = $this->resolveExchange($exchangeName);

        // ---- 分页下载 ----
        // ⚠️ 交易所服务内部使用 Swoole\\Coroutine\\Http\\Client（项目规则强制）。
        //    普通 CLI（非 Swoole Server / 非 Coroutine\\run 上下文）调用会抛
        //    "Swoole\\Error: API must be called in the coroutine"。
        //    与 ExchangeIntegrationTest::runInCoroutine() 一致：这里用 Coroutine::run()
        //    启动同步阻塞的协程事件循环，在里面执行下载，完成后把结果 & 异常带回。
        $allKlines = $this->runInCoroutine(fn() => $this->fetchAllKlines(
            $exchange,
            $symbol,
            $interval,
            $startMs,
            $endMs,
            $pageLimit,
            $retries,
            $retryBase
        ));
        if ($allKlines === []) {
            return $header . "\n" . $this->warn('交易所返回 0 根 K 线（交易对/交易所/周期组合可能不支持，或 --from 超过当前）');
        }

        // ---- 落盘 ----
        $writer = new KlinesCsvWriter();
        [$path, $written] = $writer->write($outputDir, $exchangeName, $symbol, $interval, $allKlines);

        $firstTs = $allKlines[0][0] ?? 0;
        $lastTs  = $allKlines[count($allKlines) - 1][0] ?? 0;
        return $header
            . "\n"
            . sprintf(
                "✅ 已保存 \033[32m%d\033[0m 根 K 线 → %s\n"
                . "   起止：%s ~ %s\n"
                . "   数据覆盖：\033[33m%.2f%%\033[0m（%d / 约 %d）",
                $written,
                $path,
                self::msToIso($firstTs),
                self::msToIso($lastTs),
                $estimated > 0 ? ($written / $estimated) * 100 : 0.0,
                $written,
                $estimated
            );
    }

    public function help(array $args): ?string
    {
        $defaults = [
            'exchange'   => 'binance',
            'symbol'     => 'BTC/USDT',
            'interval'   => '1h',
            'days'       => 7,
            'retries'    => 5,
            'retry-base' => 1.2,
            'output-dir' => 'RUNTIME_PATH/trader/data',
        ];
        $help = <<<HELP
Download historical klines from exchange service (binance / okx) → save as CSV into runtime/trader/data.

Usage:
  php sikelan trader:download-klines [options]

⭐ Defaults match the most common case:
  php sikelan trader:download-klines
    = download binance · BTC/USDT · 1h · last 7 days → runtime/trader/data/binance/BTC-USDT_1h.csv

Options:
  --exchange=binance|okx        Exchange name                  (default: {$defaults['exchange']})
  --symbol=BTC/USDT             Standard trading pair          (default: {$defaults['symbol']}; spot OK, SWAP OK)
  --interval=1m|5m|15m|30m|1h|4h|1d|1w  Candle timeframe      (default: {$defaults['interval']})
  --days=N                      Download last N calendar days  (default: {$defaults['days']})
  --from=YYYY-MM-DD             Start date (inclusive); if set, overrides --days
  --to=YYYY-MM-DD               End date (inclusive); default = today
  --page-limit=1000             Rows per page; max=1000        (default: 1000)
  --retries=N                   Max total attempts per page (1 = only 1 try, no retries) (default: {$defaults['retries']})
  --retry-base=FLOAT            Exponential backoff base (seconds). delay=base^retry * (1+jitter) (default: {$defaults['retry-base']})
  --output-dir=/path            Custom output base dir         (default: {$defaults['output-dir']})
  --dry-run                     Only parse & print plan, no fetch/save

Retry logic:
  Retryable (exponential backoff + random jitter):
    • ExchangeException with code=0 (connection timeout / DNS / TCP reset)
    • HTTP status 5xx + 429 (server-side transient / rate-limit)
    • Message matches (timeout|connection|Invalid JSON|broken pipe|reset by peer)
  Non-retryable (fail fast):
    • HTTP 4xx (invalid symbol / unauthorized / bad params)
    • Any non-ExchangeException (code-level bug)
  After reaching max attempts: the last exception is rethrown, wrapped with page context.

Examples:
  # 常用：BTC/USDT 现货 1h 近7天（命令一行搞定）
  php sikelan trader:download-klines

  # 网络差时：加重试次数 + 加大退避（极端不稳定网络可用）
  php sikelan trader:download-klines --symbol=ETH/USDT --interval=1m --days=10 --retries=10 --retry-base=1.5

  # 自定义：OKX BTC/USDT:SWAP 4h 最近30天
  php sikelan trader:download-klines --exchange=okx --symbol=BTC/USDT:SWAP --interval=4h --days=30

  # 指定日期窗口
  php sikelan trader:download-klines --exchange=binance --symbol=ETH/USDT --interval=1d --from=2024-01-01 --to=2024-06-30

  # 只看参数，不下载
  php sikelan trader:download-klines --dry-run
HELP;
        return $help;
    }

    // ------------------------------------------------------------------------
    //  下载主逻辑
    // ------------------------------------------------------------------------

    /**
     * 分页拉取 K 线 —— 正向从 startMs 起推进到 endMs，每页精确传 [pageStart, pageEnd] 给交易所。
     *
     * 根因修复（之前 15 次分页只拿到 1000 根的本质问题）：
     *   ExchangeInterface::getKlines(symbol, interval, limit, ?startMs, ?endMs) 现已支持时间窗口参数。
     *   不传 start/end 时交易所只会返回「最近 limit 根」（最新 1000 根）→ 跨 15 次分页返回完全重叠，
     *   CSV 写器按 timestamp 去重只剩 1000。
     *   现在每页都传明确的 startMs/endMs，窗口完全不重叠 → 最终得到完整连续的 K 线段。
     *
     * @return list<array{0:int,1:float,2:float,3:float,4:float,5:float}>
     */
    protected function fetchAllKlines(
        ExchangeInterface $exchange,
        string $symbol,
        string $interval,
        int $startMs,
        int $endMs,
        int $pageLimit,
        int $maxAttempts = 5,
        float $retryBaseSeconds = 1.2
    ): array {
        $intervalMs = self::INTERVAL_MS[$interval];
        $stepMs     = (int) $pageLimit * $intervalMs;   // 一页最多覆盖 stepMs 毫秒（= 1000 根）

        $collected = [];
        $page      = 0;
        $curStart  = $startMs;

        while ($curStart <= $endMs) {
            $page++;
            // 本页 [pageStart, pageEnd] 是闭区间（交易所 startMs/endMs 都是 inclusive）
            $pageEnd = min($curStart + $stepMs - 1, $endMs);

            $this->stderrOut(sprintf(
                "  · page %-3d | %s ~ %s",
                $page,
                self::msToIso($curStart),
                self::msToIso($pageEnd)
            ));

            // 关键：把单页请求包进 retryWithBackoff — 只对「网络类瞬时异常」重试；
            // 业务 4xx（symbol 错、interval 不合法）直接抛给外层，不浪费尝试。
            try {
                $rows = $this->retryWithBackoff(
                    $maxAttempts,
                    $retryBaseSeconds,
                    // 第 N 次失败回调：打印给用户看（进度条的语义）
                    static function (int $attempt, int $total, \Throwable $e, float $sleepSeconds): void {
                        fwrite(STDERR, sprintf(
                            "    \033[33m[retry %d/%d]\033[0m %s → sleep %.2fs\n",
                            $attempt,
                            $total - 1,
                            rtrim($e->getMessage()),
                            $sleepSeconds
                        ));
                    },
                    static fn() => $exchange->getKlines($symbol, $interval, $pageLimit, $curStart, $pageEnd)
                );
            } catch (\Throwable $e) {
                throw new \RuntimeException(
                    "下载第 {$page} 页失败（已重试至上限 {$maxAttempts} 次）：" . $e->getMessage()
                    . '（exchange=' . $exchange->getName() . ', symbol=' . $symbol
                    . ', window=' . self::msToIso($curStart) . ' ~ ' . self::msToIso($pageEnd) . '）',
                    (int) $e->getCode(),
                    $e
                );
            }

            if ($rows === []) {
                // 交易所返回空页 → 直接继续下一段（可能是该时间断档，不中断整体）
                $curStart = $pageEnd + 1;
                continue;
            }

            // 收本页所有严格落在 [startMs, endMs] 闭区间的行
            $countThisPage = 0;
            $newestTsOnPage = 0;
            foreach ($rows as $row) {
                $ts = (int) ($row[0] ?? 0);
                if ($ts < $startMs || $ts > $endMs) {
                    continue;
                }
                $collected[] = $row;
                $countThisPage++;
                if ($ts > $newestTsOnPage) {
                    $newestTsOnPage = $ts;
                }
            }

            // 正向推进：下一窗口起点 = 本页最后一根 ts + 1 毫秒（让相邻页 [ts0,ts1] 无重叠无漏缝）
            // 如果本页无任何有效数据，按页尾推进 1ms（保证断档时能越过空白段）
            if ($newestTsOnPage > 0) {
                $curStart = $newestTsOnPage + 1;
            } else {
                $curStart = $pageEnd + 1;
            }

            // 安全网：超过 500 页 ≈ 50 万根强停（用户若要 1 分钟 + 365 天 = 525,600 根，刚好在此处）
            if ($page > 500) {
                $this->stderrOut("\033[33m[WARN]\033[0m 页数 > 500，安全停止（已下载 " . count($collected) . " 根）");
                break;
            }
        }

        return $collected;
    }

    /**
     * 在 Swoole 协程内同步执行一个 callable，返回 callable 的返回值；协程内异常会原样抛出给调用方。
     *
     * 本项目所有 Exchange 适配器都基于 Swoole\\Coroutine\\Http\\Client；
     * 在普通 CLI 入口（bin/sikelan trader:download-klines）调用时必须先进入 Coroutine::run() 调度，
     * 否则会抛 "API must be called in the coroutine"。
     *
     * 实现模式与 ExchangeIntegrationTest::runInCoroutine() 完全一致，保证一致性。
     *
     * @template T
     * @param callable():T $cb
     * @return T
     */
    protected function runInCoroutine(callable $cb)
    {
        $result = null;
        $ex     = null;
        \Swoole\Coroutine\run(static function () use ($cb, &$result, &$ex) {
            try {
                $result = $cb();
            } catch (\Throwable $e) {
                $ex = $e;
            }
        });
        if ($ex !== null) {
            throw $ex;
        }
        return $result;
    }

    /**
     * 通用「指数退避 + 抖动」重试器。
     *
     * 说明：
     *   - maxAttempts 表示**总尝试次数**（1 = 只试 1 次，失败直接抛；5 = 先试 1 次，失败再重试最多 4 次）。
     *   - 第 k 次"重试"发生在第 k 次尝试失败之后，k ∈ [1, maxAttempts-1]。
     *   - 等待时长 = base^k * (1 + jitter)；jitter ∈ [0, 0.5]，防止多个客户端同时重试"共振"打爆服务端。
     *   - 在 Swoole 协程环境下使用 \Swoole\Coroutine::sleep()，不阻塞整个进程调度；如果未处于协程
     *     上下文（单测环境 / 非 Swoole），退化到 sleep/usleep。
     *
     * 重试分类判定（由 isRetryableException 决定）：
     *   ✅ 重试：ExchangeException (code === 0 超时)
     *           ExchangeException (code ≥ 500，服务端错误)
     *           ExchangeException (消息含 timeout / connection / Invalid JSON / cURL error)
     *   ❌ 不重试：4xx 业务错误（交易对不存在 / 参数非法）
     *             其他任何非 ExchangeException（编程错误必须快速暴露）
     *
     * @template T
     * @param int                     $maxAttempts  ≥1
     * @param float                   $baseSec      >0，退避指数底数（建议 1.0~2.0）
     * @param callable(int,int,\Throwable,float):void|null $onRetry  每次失败后 sleep 前回调：(第几次重试, 最多重试次数, 异常, 即将sleep秒数)
     * @param callable():T           $operation    被重试的操作
     * @return T operation 的返回值
     * @throws \Throwable  达到重试上限抛出「最后一次」的异常
     */
    protected function retryWithBackoff(
        int $maxAttempts,
        float $baseSec,
        ?callable $onRetry,
        callable $operation
    ) {
        if ($maxAttempts < 1) {
            throw new \InvalidArgumentException('retryWithBackoff: maxAttempts 必须 ≥ 1');
        }
        if ($baseSec <= 0) {
            throw new \InvalidArgumentException('retryWithBackoff: baseSec 必须 > 0');
        }

        $lastException = null;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return $operation();
            } catch (\Throwable $e) {
                $lastException = $e;
                $isLast = $attempt === $maxAttempts;
                if ($isLast || !$this->isRetryableException($e)) {
                    // 最后一次 or 业务类不可重试 → 直接抛给外层
                    throw $e;
                }
                // 本次失败后需要 sleep；次数：第 1 次失败 k=1, 第 2 次失败 k=2 …
                $retryIndex = $attempt; // 已经尝试 attempt 次，这是第 attempt 次"之后"的重试号
                $delay = $this->computeBackoffDelay($baseSec, $retryIndex);
                if ($onRetry !== null) {
                    $onRetry($attempt, $maxAttempts - 1, $e, $delay);
                }
                $this->nonBlockingSleep($delay);
            }
        }
        // 理论不可达（上面任一 for 分支要么 return 要么 throw）；保留防止 IDE 报 "missing return"
        // @codeCoverageIgnoreStart
        throw $lastException ?? new \RuntimeException('retryWithBackoff: unexpected control-flow');
        // @codeCoverageIgnoreEnd
    }

    /**
     * 计算指数退避时长（秒，float），并叠加 [0, 50%] 的均匀抖动避免惊群。
     *
     *   delay(k) = base^k * (1 + rand(0,0.5))
     *
     * 默认 base=1.2, k=1..4 → 期望等待 ≈ 1.2 / 1.44 / 1.73 / 2.07 ×1.25 ≈ 1.5/1.8/2.2/2.6 s。
     * 这对「瞬时超时/单次 502/连接重置」够用；若用户调 base=2 则是 2/4/8/16 秒强力退避。
     *
     * @param float $base     退避底数
     * @param int   $retryNum 第几次重试（从 1 开始）
     */
    protected function computeBackoffDelay(float $base, int $retryNum): float
    {
        $exponentiated = $base ** max(1, $retryNum);
        $jitter = mt_rand() / (mt_getrandmax() ?: 1) * 0.5;  // [0, 0.5]
        return $exponentiated * (1.0 + $jitter);
    }

    /**
     * 判断一个异常是否「可通过重试大概率自愈」。
     */
    protected function isRetryableException(\Throwable $e): bool
    {
        // 只处理交易所适配器抛出来的 ExchangeException；其他任何异常（TypeError/RuntimeException/NPE）
        // 都算代码 bug，禁止靠重试吞掉 —— 否则排错困难。
        if (!$e instanceof \App\Services\Exchanges\ExchangeException) {
            return false;
        }
        $code = $e->getCode();
        $msg  = $e->getMessage();

        // 1) 连接级：超时 / DNS / TCP RST — AbstractExchange 会把 statusCode=0 转成这种（正好命中你栈顶示例）
        if ($code === 0) {
            return true;
        }
        // 2) HTTP 5xx：服务端临时故障 / 限流 (429 Too Many Requests 交易所一般用它但各家不同
        //    429 严格说是业务级，但重试通常有效；我们把 429 也视作可重试)
        if ($code >= 500 || $code === 429) {
            return true;
        }
        // 3) 文本兜底（某些适配器里 200 但 body 非 JSON，也属于"偶发网络截断"可重试）
        if (preg_match('/(timeout|connection|timed\s*out|Invalid JSON response|empty response|broken pipe|reset by peer)/i', $msg)) {
            return true;
        }
        // 其他（400/401/403/404 业务参数错误）— 不重试
        return false;
    }

    /**
     * 协程友好 sleep。若在 Swoole 协程里 → \Swoole\Coroutine::sleep 让出调度；
     * 否则退化到原生 usleep（单测 / 脚本环境）。
     */
    protected function nonBlockingSleep(float $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }
        // 判断是否在协程调度内（类存在 + 当前 Cid ≠ 0/-1）
        if (class_exists(\Swoole\Coroutine::class, false)) {
            $cid = \Swoole\Coroutine::getCid();
            if ($cid >= 0) {
                \Swoole\Coroutine::sleep($seconds);
                return;
            }
        }
        // 退化：usleep 接受微秒
        usleep((int) round($seconds * 1_000_000));
    }

    // ------------------------------------------------------------------------
    //  参数/环境辅助
    // ------------------------------------------------------------------------

    /**
     * 解析时间窗口，返回 [startMs, endMs]，毫秒闭区间。
     *
     * @return array{0:int, 1:int}
     */
    protected function resolveTimeWindow(int $days, string $fromStr, string $toStr): array
    {
        // 结束时间：--to > 默认当前
        if ($toStr !== '') {
            $to = \DateTime::createFromFormat('!Y-m-d', $toStr, new \DateTimeZone('UTC'));
            if (!$to) {
                throw new \InvalidArgumentException("--to 日期格式错误：{$toStr}（必须是 YYYY-MM-DD）");
            }
            // 结束日期"含" → 取该日 23:59:59.999 UTC
            $to->setTime(23, 59, 59, 999000);
            $endMs = (int) ($to->getTimestamp() * 1000) + 999;
        } else {
            $endMs = (int) (microtime(true) * 1000);
        }

        // 起始时间：--from 优先，否则用 days
        if ($fromStr !== '') {
            $from = \DateTime::createFromFormat('!Y-m-d', $fromStr, new \DateTimeZone('UTC'));
            if (!$from) {
                throw new \InvalidArgumentException("--from 日期格式错误：{$fromStr}（必须是 YYYY-MM-DD）");
            }
            $startMs = (int) $from->getTimestamp() * 1000;
        } else {
            $days = max(1, $days);
            $startMs = (int) ((time() - $days * 86400) * 1000);
        }

        if ($startMs > $endMs) {
            throw new \InvalidArgumentException('时间窗口非法：--from 晚于 --to');
        }
        return [$startMs, $endMs];
    }

    /**
     * 通过公共快捷函数 resolve 交易所服务；保证走 ExchangeManager 服务注册 + 配置链路。
     *
     * ⚠️ 这里封装一层 try/catch 是为了在 CLI 环境（没启动 Framework、容器没初始化、
     *   Redis/DB 组件没连但 ExchangeManager 只依赖 Config+Logger → 可安全运行）
     *   出问题时给出"是否没运行在项目根目录"的友好提示。
     */
    protected function resolveExchange(string $name): ExchangeInterface
    {
        if (!function_exists('exchange')) {
            throw new \RuntimeException(
                'exchange() 函数未加载。请确保 app/common.php 已被 Bootstrap 加载（运行 bin/sikelan 会自动加载）。'
            );
        }
        try {
            return exchange(strtolower($name));
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                "初始化交易所服务 {$name} 失败：" . $e->getMessage()
                . '（提示：请确认 config/exchanges.php 中已配置该交易所，或.env 中代理/SSL 可用）',
                (int) $e->getCode(),
                $e
            );
        }
    }

    // ------------------------------------------------------------------------
    //  输出辅助（颜色：info 绿/警告黄/错误红，保持 Allman 风格。）
    // ------------------------------------------------------------------------

    protected function info(string $format, ...$args): string
    {
        $msg = $args === [] ? $format : vsprintf($format, $args);
        return "\033[32m[INFO]\033[0m {$msg}";
    }

    protected function warn(string $msg): string
    {
        return "\033[33m[WARN]\033[0m {$msg}";
    }

    protected function error(string $msg): string
    {
        return "\033[31m[ERROR]\033[0m {$msg}\n\n" . $this->help([]);
    }

    /** 向 stderr 打印进度（stdout 用作 exec 返回值，会被 CommandRunner 末尾加换行）*/
    protected function stderrOut(string $line): void
    {
        fwrite(STDERR, $line . PHP_EOL);
    }

    protected static function msToIso(int $ms): string
    {
        if ($ms <= 0) {
            return '-';
        }
        //return gmdate('Y-m-d H:i:s', (int) floor($ms / 1000)) . ' UTC';
        return date('Y-m-d H:i:s', (int) floor($ms / 1000));
    }
}
