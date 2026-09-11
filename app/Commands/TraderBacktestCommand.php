<?php

namespace App\Commands;

use App\Services\Exchanges\TradingSymbol;
use App\Services\Trader\BacktestServiceProvider;
use App\Services\Trader\Market\ArrayDataProvider;
use App\Services\Trader\PerformanceReport;
use Sikelan\Command\CommandInterface;
use Sikelan\Command\CommandManager;
use Sikelan\Core\Config;

/**
 * 命令：trader:backtest
 *
 * 一行跑回测：自动加载 CSV（缺失自动下载）→ 装配策略 → 运行 → 打印绩效报告。
 *
 * ⭐ 零参数即可跑（binance · BTC/USDT · 1h · MeanRevStd 策略，CSV 缺失自动下载近 7 天）：
 *      php bin/sikelan trader:backtest
 *
 * 设计原则：所有参数都有合理默认值；常用参数（交易对/周期/策略/资金/时间窗）
 * 全部可通过 --key=value 覆盖；输出默认人类可读彩色报告，--json 切换机器可读。
 *
 * @package App\Commands
 */
class TraderBacktestCommand implements CommandInterface
{
    /** 允许的 K 线周期（与 TraderDownloadKlinesCommand 保持一致）*/
    protected const VALID_TIMEFRAMES = ['1m', '5m', '15m', '30m', '1h', '4h', '1d', '1w'];

    /** 一天的秒数（时间窗口按 UTC 自然日对齐用） */
    protected const DAY_SEC = 86400;

    /** 报告标签列视觉宽度（中文全角=2、ASCII=1），最宽标签"初始/期末资金"=13 → 取 14 */
    private const LABEL_WIDTH = 14;

    public function commandName(): string
    {
        return 'trader:backtest';
    }

    public function desc(): string
    {
        return '一行跑回测：加载CSV（缺失自动下载）→ 跑策略 → 输出绩效报告（默认 binance BTC/USDT 1h MeanRevStd）';
    }

    // ========================================================================
    //  主流程
    // ========================================================================

    public function exec(array $args): ?string
    {
        unset($args); // 参数统一从 CommandManager 的 originArgv 读取

        try {
            $raw = $this->readRawOptions();

            // --list-strategies：不需要任何其他参数，直接列别名退出
            if (!empty($raw['list_strategies'])) {
                return $this->formatStrategies();
            }

            // 解析 + 校验参数（纯函数，不碰 IO）
            [$plan, $error] = $this->parsePlan($raw);
            if ($error !== null) {
                return $this->error($error);
            }

            // 加载数据（CSV 存在直接读；不存在按 plan.download_opts 自动下载）
            $dp = $this->loadProvider($plan);

            // --dry-run：只展示计划 + 数据量，不装配策略（不依赖 trader 扩展）
            if (!empty($plan['dry_run'])) {
                return $this->formatDryRun($plan, $dp);
            }

            // 装配 + 运行 + 绩效报告
            $perf = $this->runAndReport($plan, $dp);

            return !empty($plan['json'])
                ? $this->formatJson($perf, $plan)
                : $this->formatHuman($perf, $plan);
        } catch (\Throwable $e) {
            // 下载失败 / 策略未找到 / trader 扩展缺失 / warmup 不足等，统一友好输出
            return $this->error($e->getMessage());
        }
    }

    // ========================================================================
    //  参数读取与解析
    // ========================================================================

    /**
     * 从 CommandManager（originArgv）+ 环境变量读取原始参数（全部 string/bool，未校验）。
     */
    protected function readRawOptions(): array
    {
        $cmd = CommandManager::getInstance();

        // 资金默认值优先取 config/trader.php（CLI 下容器已加载全部 config）
        $defaultCapital = 10000.0;
        if (function_exists('container')) {
            try {
                $c = container();
                if ($c->has(Config::class)) {
                    $defaultCapital = (float) $c->get(Config::class)->get('trader.initial_capital', 10000.0);
                }
            } catch (\Throwable $e) {
                // 容器不可用（极简环境）→ 用内置默认，不阻塞
            }
        }

        return [
            'exchange'   => (string) ($cmd->getOpt('--exchange', (string) getenv('BACKTEST_EXCHANGE')) ?: 'binance'),
            // --symbol / --symbols 互为别名，逗号分隔多个交易对
            'symbol'     => (string) ($cmd->getOpt('--symbol',
                                $cmd->getOpt('--symbols', (string) getenv('BACKTEST_SYMBOLS'))) ?: 'BTC/USDT'),
            // --timeframe / --interval 互为别名（与下载命令的 --interval 习惯对齐）
            'timeframe'  => (string) ($cmd->getOpt('--timeframe',
                                $cmd->getOpt('--interval', (string) getenv('BACKTEST_TIMEFRAME'))) ?: '1h'),
            'strategy'   => (string) ($cmd->getOpt('--strategy', (string) getenv('BACKTEST_STRATEGY')) ?: 'MeanRevStd'),
            'days'       => (string) ($cmd->getOpt('--days', (string) getenv('BACKTEST_DAYS')) ?: ''),
            'from'       => (string) $cmd->getOpt('--from', ''),
            'to'         => (string) $cmd->getOpt('--to', ''),
            'capital'    => (string) ($cmd->getOpt('--capital', (string) getenv('BACKTEST_CAPITAL')) ?: $defaultCapital),
            'warmup'     => (string) ($cmd->getOpt('--warmup', (string) getenv('BACKTEST_WARMUP')) ?: '60'),
            'no_download' => $cmd->getOpt('--no-download', null) !== null,
            'allow_gaps'  => $cmd->getOpt('--allow-gaps', null) !== null,
            'list_strategies' => $cmd->getOpt('--list-strategies', null) !== null,
            'json'       => $cmd->getOpt('--json', null) !== null,
            'dry_run'    => $cmd->getOpt('--dry-run', null) !== null,
        ];
    }

    /**
     * 规范化 + 校验参数（纯函数，便于单测）。
     *
     * @return array{0:array<string,mixed>,1:?string} [规范化后的 plan, 错误消息(null=通过)]
     */
    protected function parsePlan(array $raw): array
    {
        $exchange = strtolower(trim((string) $raw['exchange']));
        if ($exchange === '') {
            return [[], '请通过 --exchange 指定交易所（如 binance / okx）'];
        }

        $symbols = $this->parseSymbols((string) $raw['symbol']);
        if ($symbols === []) {
            return [[], '请通过 --symbol 指定至少一个交易对（多个用逗号分隔，如 --symbol=BTC/USDT,ETH/USDT）'];
        }

        $timeframe = strtolower(trim((string) $raw['timeframe']));
        if (!in_array($timeframe, self::VALID_TIMEFRAMES, true)) {
            return [[], "不支持的周期 --timeframe={$timeframe}，支持：" . implode('/', self::VALID_TIMEFRAMES)];
        }

        $strategy = trim((string) $raw['strategy']);
        if ($strategy === '') {
            return [[], '请通过 --strategy 指定策略别名（--list-strategies 查看已注册策略）'];
        }

        // 资金
        if (!is_numeric($raw['capital']) || (float) $raw['capital'] <= 0) {
            return [[], "--capital 必须是正数，收到：{$raw['capital']}"];
        }
        // 预热
        if (!ctype_digit((string) $raw['warmup'])) {
            return [[], "--warmup 必须是非负整数，收到：{$raw['warmup']}"];
        }

        // 时间窗口 + 下载参数（--days 与 --from/--to 互斥）
        [$window, $windowErr] = $this->resolveWindow(
            (string) $raw['days'],
            (string) $raw['from'],
            (string) $raw['to']
        );
        if ($windowErr !== null) {
            return [[], $windowErr];
        }

        $plan = [
            'exchange'      => $exchange,
            'symbols'       => $symbols,
            'timeframe'     => $timeframe,
            'strategy'      => $strategy,
            'capital'       => (float) $raw['capital'],
            'warmup'        => (int) $raw['warmup'],
            'from_ms'       => $window['from_ms'],
            'to_ms'         => $window['to_ms'],
            'download_opts' => $window['download_opts'],
            'auto_download' => empty($raw['no_download']),
            'allow_gaps'    => !empty($raw['allow_gaps']),
            'json'          => !empty($raw['json']),
            'dry_run'       => !empty($raw['dry_run']),
        ];
        return [$plan, null];
    }

    /**
     * 解析逗号分隔的交易对列表：去空白 / 去空项 / 去重（保持首次出现顺序）。
     *
     * @return string[]
     */
    protected function parseSymbols(string $csv): array
    {
        $result = [];
        foreach (explode(',', $csv) as $part) {
            $s = trim($part);
            if ($s !== '' && !in_array($s, $result, true)) {
                $result[] = $s;
            }
        }
        return $result;
    }

    /**
     * 解析时间窗口，同时产出"CSV 缺失时自动下载"的参数。
     *
     * 规则：
     *   - --days=N          → [今天0点-N天, 现在]；下载用 ['days' => N]
     *   - --from/--to       → 按自然日（UTC）；下载用 ['from'=>..., 'to'=>...]
     *   - 都不给            → 回测全部已加载数据；下载默认近 7 天（与下载命令一致）
     *   - --days 与 --from/--to 同时给 → 报错（语义冲突）
     *
     * @return array{0:array{from_ms:?int,to_ms:?int,download_opts:array},1:?string}
     */
    protected function resolveWindow(string $days, string $from, string $to): array
    {
        if ($days !== '' && ($from !== '' || $to !== '')) {
            return [[], '--days 与 --from/--to 不能同时使用（二选一：最近 N 天，或指定起止日期）'];
        }

        if ($days !== '') {
            if (!ctype_digit($days) || (int) $days <= 0) {
                return [[], "--days 必须是正整数，收到：{$days}"];
            }
            $n = (int) $days;
            // 起点按 UTC 自然日对齐，终点不截断（= 数据最新处）
            $todayStart = (int) floor(time() / self::DAY_SEC) * self::DAY_SEC;
            return [[
                'from_ms'       => ($todayStart - $n * self::DAY_SEC) * 1000,
                'to_ms'         => null,
                'download_opts' => ['days' => $n],
            ], null];
        }

        if ($from !== '' || $to !== '') {
            $fromMs = null;
            $toMs = null;
            $downloadOpts = [];
            if ($from !== '') {
                $ms = $this->parseDateBoundary($from, false);
                if ($ms === null) {
                    return [[], "--from 日期格式应为 YYYY-MM-DD，收到：{$from}"];
                }
                $fromMs = $ms;
                $downloadOpts['from'] = $from;
            }
            if ($to !== '') {
                $ms = $this->parseDateBoundary($to, true);
                if ($ms === null) {
                    return [[], "--to 日期格式应为 YYYY-MM-DD，收到：{$to}"];
                }
                $toMs = $ms;
                $downloadOpts['to'] = $to;
            }
            if ($fromMs !== null && $toMs !== null && $fromMs > $toMs) {
                return [[], "--from（{$from}）不能晚于 --to（{$to}）"];
            }
            return [[
                'from_ms'       => $fromMs,
                'to_ms'         => $toMs,
                'download_opts' => $downloadOpts,
            ], null];
        }

        // 默认：不限制回测窗口；若 CSV 缺失，自动下载近 7 天
        return [[
            'from_ms'       => null,
            'to_ms'         => null,
            'download_opts' => ['days' => 7],
        ], null];
    }

    /**
     * 把 YYYY-MM-DD 转成毫秒时间戳（UTC）。
     *
     * @param bool $endOfDay false=当天 00:00:00.000（含当天起点）；true=当天 23:59:59.999（含当天最后一根）
     */
    protected function parseDateBoundary(string $date, bool $endOfDay): ?int
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }
        $ts = strtotime("{$date} UTC");
        if ($ts === false) {
            return null;
        }
        $dayStart = (int) floor($ts / self::DAY_SEC) * self::DAY_SEC;
        return ($endOfDay ? $dayStart + (self::DAY_SEC - 1) : $dayStart) * 1000 + ($endOfDay ? 999 : 0);
    }

    // ========================================================================
    //  数据加载 / 回测执行
    // ========================================================================

    /**
     * 按 plan 批量加载数据到同一个 ArrayDataProvider。
     * auto_download=false 时不传下载参数，CSV 缺失会抛带手动下载指引的异常。
     */
    protected function loadProvider(array $plan): ArrayDataProvider
    {
        $pairs = array_map(static fn(string $s): array => ['symbol' => $s], $plan['symbols']);
        $downloadOpts = $plan['auto_download'] ? $plan['download_opts'] : [];

        return BacktestServiceProvider::loadDataProviderBatch(
            $plan['exchange'],
            $plan['timeframe'],
            $pairs,
            $downloadOpts,
            (bool) $plan['allow_gaps']
        );
    }

    /**
     * 装配 Backtesting（按策略别名）→ 运行（symbols/timeframe 自动推导）→ 绩效报告。
     */
    protected function runAndReport(array $plan, ArrayDataProvider $dp): PerformanceReport
    {
        $backtest = BacktestServiceProvider::newBacktestingByName(
            container(),
            $dp,
            $plan['strategy'],
            [
                'initial_capital' => $plan['capital'],
                'warmup_candles'  => $plan['warmup'],
            ]
        );

        // symbols/timeframe 省略：自动从 DataProvider 推导（避免与加载时重复声明）
        $result = $backtest->run(null, null, $plan['from_ms'], $plan['to_ms']);

        return new PerformanceReport($result, (float) $plan['capital'], 365);
    }

    /**
     * 读取 config/trader.php 中已注册的策略别名表。
     *
     * @return array<string, array{class:string, construct:array}>
     */
    protected function resolveStrategyRegistry(): array
    {
        if (function_exists('container')) {
            try {
                $c = container();
                if ($c->has(Config::class)) {
                    return BacktestServiceProvider::getStrategyRegistry($c->get(Config::class));
                }
            } catch (\Throwable $e) {
                // 容器不可用时退化为空表
            }
        }
        return BacktestServiceProvider::getStrategyRegistry(['strategies' => []]);
    }

    // ========================================================================
    //  输出格式化
    // ========================================================================

    /** --list-strategies：列出所有策略别名 */
    protected function formatStrategies(): string
    {
        $registry = $this->resolveStrategyRegistry();
        $out = $this->info('已注册策略别名（config/trader.php → strategies）：') . "\n\n";
        if ($registry === []) {
            $out .= "  （暂无注册策略，请在 config/trader.php 的 strategies 中配置）\n";
        }
        foreach ($registry as $alias => $entry) {
            $shortClass = substr(strrchr($entry['class'], '\\'), 1);
            $construct = $entry['construct'] === []
                ? '默认构造参数'
                : '构造参数: ' . implode(', ', array_map([$this, 'scalar'], $entry['construct']));
            $out .= sprintf("  \033[36m%-16s\033[0m %s  (%s)\n", $alias, $shortClass, $construct);
        }
        $out .= "\n使用 --strategy=<别名> 指定；也支持直接传完整类名。\n";
        return $out;
    }

    /** --dry-run：展示回测计划 + 每个交易对实际可用 K 线数 */
    protected function formatDryRun(array $plan, ArrayDataProvider $dp): string
    {
        $out = $this->info('[DRY-RUN] 回测计划（未实际运行，去掉 --dry-run 即正式执行）：') . "\n\n";
        $out .= $this->planSummaryLines($plan);

        $out .= "  数据量（按时间窗口过滤后）：\n";
        foreach ($plan['symbols'] as $s) {
            $candles = $dp->getCandles(
                TradingSymbol::parse($s),
                $plan['timeframe'],
                $plan['from_ms'],
                $plan['to_ms']
            );
            $out .= sprintf("    • %-14s %d 根\n", $s, count($candles));
        }
        return $out;
    }

    /** 人类可读彩色绩效报告 */
    protected function formatHuman(PerformanceReport $perf, array $plan): string
    {
        $m = $perf->all();
        $out = "\n" . $this->info('回测完成') . "\n";
        $out .= str_repeat('─', 52) . "\n";

        // ---- 基本信息 ----
        $range = $this->rangeText($m['backtest_start_iso'], $m['backtest_end_iso']);
        $out .= $this->kvLine('策略', (string) $m['strategy']);
        $out .= $this->kvLine('交易所/周期', $plan['exchange'] . ' · ' . $plan['timeframe']);
        $out .= $this->kvLine('交易对', implode(', ', $plan['symbols']));
        $out .= $this->kvLine('回测区间', $range);
        $out .= $this->kvLine('初始/期末资金',
            $this->money($plan['capital']) . ' → ' . $this->money((float) $m['final_capital']) . ' ' . (string) $m['stake_currency']);
        $out .= str_repeat('─', 52) . "\n";

        // ---- 收益 / 风险 ----
        $retPct = (float) $m['total_return_pct'];
        $out .= $this->kvLine('总收益率',
            $this->pctText($retPct) . '  (净利 ' . $this->coloredMoney((float) $m['total_net_profit']) . ')');
        $out .= $this->kvLine('年化收益', $this->pctText((float) $m['cagr_pct']));
        $out .= $this->kvLine('夏普比率', $this->num($m['sharpe_ratio']));
        $out .= $this->kvLine('索提诺比率', $this->num($m['sortino_ratio']));
        $out .= $this->kvLine('卡玛比率', $this->num($m['calmar_ratio']));
        $out .= $this->kvLine('最大回撤',
            $this->pctText(-(float) $m['max_drawdown_pct']) . '  (' . $this->money(-(float) $m['max_drawdown_abs']) . ')');
        $out .= str_repeat('─', 52) . "\n";

        // ---- 交易统计 ----
        $out .= $this->kvLine('交易总数',
            (string) $m['total_trades'] . '  (盈 ' . (string) $m['win_count'] . ' / 亏 ' . (string) $m['loss_count'] . ')');
        $out .= $this->kvLine('胜率', $this->pctText((float) $m['win_rate_pct'], false));
        $out .= $this->kvLine('盈亏比', $this->num($m['profit_loss_ratio']));
        $out .= $this->kvLine('利润因子', $this->num($m['profit_factor']));
        $out .= $this->kvLine('平均持仓', $this->num($m['avg_duration_min']) . ' 分钟');
        $out .= $this->kvLine('信号/拒绝', (string) $m['signals_total'] . ' / ' . (string) $m['signals_rejected']);
        $out .= str_repeat('─', 52) . "\n";

        if ((int) $m['total_trades'] === 0) {
            $out .= $this->warn('本次回测没有产生任何平仓交易；可尝试更长 --days、更小 --warmup 或换策略。') . "\n";
        }
        return $out;
    }

    /** JSON 报告（脚本/二次处理用） */
    protected function formatJson(PerformanceReport $perf, array $plan): string
    {
        $payload = [
            'plan' => [
                'exchange'  => $plan['exchange'],
                'symbols'   => $plan['symbols'],
                'timeframe' => $plan['timeframe'],
                'strategy'  => $plan['strategy'],
                'capital'   => $plan['capital'],
                'warmup'    => $plan['warmup'],
                'from_ms'   => $plan['from_ms'],
                'to_ms'     => $plan['to_ms'],
            ],
            'metrics' => $perf->all(),
        ];
        return (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // ========================================================================
    //  输出小工具
    // ========================================================================

    /** 计划摘要（dry-run 与未来其他场景共用） */
    private function planSummaryLines(array $plan): string
    {
        $window = $plan['from_ms'] === null && $plan['to_ms'] === null
            ? '全部已加载数据'
            : $this->msDate($plan['from_ms']) . ' ~ ' . $this->msDate($plan['to_ms']);
        $download = $plan['auto_download']
            ? 'CSV 缺失时自动下载（' . $this->formatDownloadOpts($plan['download_opts']) . '）'
            : '不自动下载（--no-download，CSV 缺失即报错）';

        $lines  = $this->kvLine('交易所/周期', $plan['exchange'] . ' · ' . $plan['timeframe']);
        $lines .= $this->kvLine('交易对', implode(', ', $plan['symbols']));
        $lines .= $this->kvLine('策略', $plan['strategy']);
        $lines .= $this->kvLine('资金/预热', $this->money($plan['capital']) . ' · 预热 ' . $plan['warmup'] . ' 根');
        $lines .= $this->kvLine('时间窗口', $window);
        $lines .= $this->kvLine('数据缺失时', $download);
        $lines .= $this->kvLine('允许缺口', $plan['allow_gaps'] ? '是' : '否（严格周期对齐）');
        return $lines;
    }

    /**
     * 单行 "  label  value"。
     * 用 mb_strwidth 按视觉宽度对齐（中文全角占 2、ASCII 占 1），避免 sprintf 按字节 padding 错位。
     */
    private function kvLine(string $label, string $value): string
    {
        $pad = max(1, self::LABEL_WIDTH - mb_strwidth($label, 'UTF-8'));
        return '  ' . $label . str_repeat(' ', $pad) . $value . "\n";
    }

    /** 百分比文本：正数绿色、负数红色；null 显示 - */
    private function pctText(?float $v, bool $signed = true): string
    {
        if ($v === null) {
            return '-';
        }
        $text = ($signed && $v > 0 ? '+' : '') . number_format($v, 2) . '%';
        return $this->colorBySign($text, $v);
    }

    /** 金额着色：正绿负红 */
    private function coloredMoney(float $v): string
    {
        return $this->colorBySign($this->money($v), $v);
    }

    private function colorBySign(string $text, float $v): string
    {
        if ($v > 0) {
            return "\033[32m{$text}\033[0m";
        }
        if ($v < 0) {
            return "\033[31m{$text}\033[0m";
        }
        return $text;
    }

    private function money($v): string
    {
        return number_format((float) $v, 2);
    }

    private function num($v, int $decimals = 2): string
    {
        return $v === null ? '-' : number_format((float) $v, $decimals);
    }

    /** ISO 时间（2026-09-05T12:00:00+00:00）→ 日期短串，并在末尾追加区间天数 */
    private function rangeText($startIso, $endIso): string
    {
        $s = $startIso ? substr((string) $startIso, 0, 10) : '?';
        $e = $endIso ? substr((string) $endIso, 0, 10) : '?';
        $range = "{$s} ~ {$e} (UTC)";

        // 按展示的两个自然日计算区间天数（与 --days 参数口径一致，如 08-06 ~ 09-05 = 30 天）
        if ($startIso && $endIso) {
            $days = (int) round((strtotime($e . ' UTC') - strtotime($s . ' UTC')) / 86400);
            $range .= " · 共 {$days} 天";
        }
        return $range;
    }

    private function msDate(?int $ms): string
    {
        return $ms === null ? '至今' : gmdate('Y-m-d', (int) ($ms / 1000));
    }

    /** 构造参数在 --list-strategies 中的简化显示 */
    private function scalar($v): string
    {
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_string($v)) {
            return "'{$v}'";
        }
        return (string) $v;
    }

    /** 把下载参数数组格式化成人类可读串，如 "days=7" / "from=2026-01-01, to=2026-03-31" */
    private function formatDownloadOpts(array $opts): string
    {
        if ($opts === []) {
            return '无';
        }
        $parts = [];
        foreach ($opts as $k => $v) {
            $parts[] = "{$k}={$v}";
        }
        return implode(', ', $parts);
    }

    // ------------------------------------------------------------------------
    //  输出辅助（颜色风格与 TraderDownloadKlinesCommand 保持一致）
    // ------------------------------------------------------------------------

    protected function info(string $msg): string
    {
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

    public function help(array $args): ?string
    {
        unset($args);
        return <<<'HELP'
回测：加载 CSV（缺失自动下载）→ 装配策略 → 运行 → 输出绩效报告

Usage:
  php sikelan trader:backtest [options]

⭐ 零参数即可跑（binance · BTC/USDT · 1h · MeanRevStd，CSV 缺失自动下载近 7 天）:
  php sikelan trader:backtest

常用示例:
  # 多交易对（逗号分隔）
  php sikelan trader:backtest --symbol=BTC/USDT,ETH/USDT
  # 指定策略 + 15分钟周期 + 最近 30 天
  php sikelan trader:backtest --strategy=EmaCross20_50 --timeframe=15m --days=30
  # 指定回测区间（UTC 自然日）
  php sikelan trader:backtest --from=2026-01-01 --to=2026-03-31
  # 只用本地已有 CSV，不联网下载
  php sikelan trader:backtest --no-download
  # 机器可读 JSON 输出（便于脚本/看板处理）
  php sikelan trader:backtest --json
  # 先看计划和数据量，不实际运行
  php sikelan trader:backtest --dry-run
  # 查看所有已注册策略别名
  php sikelan trader:backtest --list-strategies

Options:
  --exchange=NAME        交易所 binance|okx            (默认 binance；env BACKTEST_EXCHANGE)
  --symbol=P1,P2         交易对，逗号分隔多个           (默认 BTC/USDT；env BACKTEST_SYMBOLS；别名 --symbols)
  --timeframe=TF         周期 1m|5m|15m|30m|1h|4h|1d|1w (默认 1h；别名 --interval；env BACKTEST_TIMEFRAME)
  --strategy=ALIAS       策略别名或完整类名             (默认 MeanRevStd；env BACKTEST_STRATEGY)
  --days=N               回测最近 N 天（同时作为缺失 CSV 时的下载天数；env BACKTEST_DAYS）
  --from=YYYY-MM-DD      回测起始日期（含，UTC），与 --days 二选一
  --to=YYYY-MM-DD        回测结束日期（含，UTC）
  --capital=N            初始资金                       (默认 config trader.initial_capital=10000)
  --warmup=N             指标预热 K 线数                (默认 60；K 线不足时调小，数据足时可调大)
  --no-download          CSV 缺失时不自动下载，直接报错并给出手动下载命令
  --allow-gaps           允许 K 线存在缺口（默认严格校验周期连续/对齐）
  --list-strategies      列出 config/trader.php 已注册策略别名后退出
  --json                 以 JSON 输出全部 30+ 指标
  --dry-run              只打印回测计划和每个 pair 的 K 线数，不实际运行
  -h, --help             查看本帮助

提示:
  • 数据文件位于 runtime/trader/data/<exchange>/<SYMBOL>_<TF>.csv，可用 trader:download-klines 预下载
  • 报告含：总收益率/年化/夏普/索提诺/卡玛/最大回撤/胜率/盈亏比/利润因子/平均持仓等
HELP;
    }
}
