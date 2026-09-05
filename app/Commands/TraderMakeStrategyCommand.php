<?php

namespace App\Commands;

use Sikelan\Command\CommandInterface;
use Sikelan\Command\CommandManager;

/**
 * 命令：trader:make-strategy
 *
 * 一行创建策略：生成策略类文件 + 自动注册到 config/trader.php 的 strategies 注册表。
 *
 * ⭐ 最简用法（只给策略别名，类名自动生成 = 别名 + 'Strategy'）：
 *      php bin/sikelan trader:make-strategy --name=MyStrat
 *
 * 设计：
 *   - 默认模板 ema（EMA 金叉死叉，完整可跑），也可选 meanrev / blank
 *   - 类文件默认落 app/Services/Trader/Strategies/，--dir 可改
 *   - 自动把 '别名' => ['class'=>..., 'construct'=>[...]] 注入 config/trader.php
 *   - 写完自动 php -l 语法校验；失败不阻塞但给出警告
 *
 * @package App\Commands
 */
class TraderMakeStrategyCommand implements CommandInterface
{
    /** 默认策略目录（相对 APP_PATH） */
    protected const DEFAULT_STRATEGY_DIR = 'Services/Trader/Strategies';

    /** 支持的模板 */
    protected const TEMPLATES = ['ema', 'meanrev', 'blank'];

    /** 各模板对应的构造参数签名（仅用于校验 --params 数量提示） */
    protected const TEMPLATE_PARAM_COUNT = [
        'ema'     => 3,   // emaShort, emaLong, filterPct
        'meanrev' => 6,   // bbPeriod, bbStdMult, rsiPeriod, rsiOversold, rsiOverbought, volFilterFactor
        'blank'   => 0,
    ];

    /** 各模板的默认构造参数值 */
    protected const TEMPLATE_DEFAULT_PARAMS = [
        'ema'     => [20, 50, 0.003],
        'meanrev' => [20, 2.0, 14, 30.0, 65.0, 0.8],
        'blank'   => [],
    ];

    public function commandName(): string
    {
        return 'trader:make-strategy';
    }

    public function desc(): string
    {
        return '创建新策略：生成类文件 + 自动注册到 config/trader.php（默认 ema 模板）';
    }

    // ========================================================================
    //  主流程
    // ========================================================================

    public function exec(array $args): ?string
    {
        unset($args);

        try {
            $raw = $this->readOptions();

            // --name 必填
            if ($raw['name'] === '') {
                return $this->error('请通过 --name=<策略别名> 指定策略名（用于 config/trader.php 的 key）');
            }

            [$plan, $error] = $this->buildPlan($raw);
            if ($error !== null) {
                return $this->error($error);
            }

            // 目标文件已存在？
            if (is_file($plan['file_path']) && !$plan['force']) {
                return $this->warn("策略文件已存在：{$plan['file_path']}\n使用 --force 覆盖，或换一个 --name。");
            }

            // --dry-run：只展示计划
            if ($plan['dry_run']) {
                return $this->formatDryRun($plan);
            }

            // 1) 写策略类文件
            $this->writeStrategyFile($plan);

            // 2) 注册到 config/trader.php
            if (!$plan['no_register']) {
                $this->registerInConfig($plan['config_path'], $plan['alias'], $plan['full_class'], $plan['construct']);
            }

            // 3) 语法校验（php -l）
            $lintOk = $this->lintFile($plan['file_path']);

            return $this->formatSuccess($plan, $lintOk);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    // ========================================================================
    //  参数读取 / 解析
    // ========================================================================

    protected function readOptions(): array
    {
        $cmd = CommandManager::getInstance();
        return [
            'name'        => (string) $cmd->getOpt('--name', (string) getenv('STRATEGY_NAME')),
            'class'       => (string) $cmd->getOpt('--class', ''),
            'dir'         => (string) $cmd->getOpt('--dir', ''),
            'namespace'   => (string) $cmd->getOpt('--namespace', ''),
            'template'    => (string) ($cmd->getOpt('--template', (string) getenv('STRATEGY_TEMPLATE')) ?: 'ema'),
            'params'      => (string) $cmd->getOpt('--params', ''),
            'no_register' => $cmd->getOpt('--no-register', null) !== null,
            'force'       => $cmd->getOpt('--force', null) !== null || $cmd->getOpt('-f', null) !== null,
            'dry_run'     => $cmd->getOpt('--dry-run', null) !== null,
            'config_path' => (string) ($cmd->getOpt('--config', '') ?: (
                defined('CONFIG_PATH') ? CONFIG_PATH . '/trader.php' : dirname(__DIR__, 2) . '/config/trader.php'
            )),
        ];
    }

    /**
     * 规范化 + 校验，产出执行计划。
     *
     * @return array{0:array<string,mixed>,1:?string}
     */
    protected function buildPlan(array $raw): array
    {
        $alias = trim((string) $raw['name']);
        if ($alias === '') {
            return [[], '策略别名 --name 不能为空'];
        }
        // 别名只能含字母/数字/_/-，避免破坏 PHP 数组 key 和命令行
        if (!preg_match('/^[A-Za-z0-9_\-]+$/', $alias)) {
            return [[], "策略别名只能含字母、数字、_、-，收到：{$alias}"];
        }

        // 类名：--class 优先，否则 别名 + 'Strategy'（已以 Strategy 结尾则不加）
        $className = $raw['class'] !== ''
            ? trim((string) $raw['class'])
            : (preg_match('/Strategy$/', $alias) ? $alias : $alias . 'Strategy');
        // 统一 PascalCase（首字母大写，其余保留）
        $className = ucfirst($className);
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $className)) {
            return [[], "类名不合法（需符合 PHP 标识符）：{$className}"];
        }

        // 模板
        $template = strtolower(trim((string) $raw['template']));
        if (!in_array($template, self::TEMPLATES, true)) {
            return [[], '不支持的模板 --template=' . $template . '，支持：' . implode('/', self::TEMPLATES)];
        }

        // 构造参数
        [$construct, $paramWarn] = $this->resolveConstruct($template, (string) $raw['params']);

        // 目录
        $baseApp = defined('APP_PATH') ? APP_PATH : (dirname(__DIR__, 2) . '/app');
        if ($raw['dir'] !== '') {
            $dir = $this->resolveDir((string) $raw['dir']);
        } else {
            $dir = $baseApp . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::DEFAULT_STRATEGY_DIR);
        }

        // 命名空间
        $namespace = $raw['namespace'] !== ''
            ? trim((string) $raw['namespace'])
            : $this->deriveNamespace($dir, $baseApp);

        $filePath = $dir . DIRECTORY_SEPARATOR . $className . '.php';
        $fullClass = $namespace . '\\' . $className;

        return [[
            'alias'         => $alias,
            'class'         => $className,
            'full_class'    => $fullClass,
            'namespace'     => $namespace,
            'dir'           => $dir,
            'file_path'     => $filePath,
            'template'      => $template,
            'construct'     => $construct,
            'param_warn'    => $paramWarn,
            'no_register'   => (bool) $raw['no_register'],
            'force'         => (bool) $raw['force'],
            'dry_run'       => (bool) $raw['dry_run'],
            'config_path'   => (string) $raw['config_path'],
        ], null];
    }

    /**
     * 解析 --params=逗号分隔列表，产出构造参数 PHP 值数组。
     *
     * 解析规则（每个 token）：
     *   - 整数 / 浮点数 → int / float
     *   - '...' 或 "..." → 去掉引号的字符串
     *   - 其他 → 字符串
     *
     * @return array{0:array,1:?string}  [参数数组, 数量不匹配警告]
     */
    protected function resolveConstruct(string $template, string $paramsCsv): array
    {
        $expected = self::TEMPLATE_PARAM_COUNT[$template];
        $defaults = self::TEMPLATE_DEFAULT_PARAMS[$template];

        if ($paramsCsv === '') {
            return [$defaults, null];
        }

        $construct = [];
        foreach (explode(',', $paramsCsv) as $tok) {
            $tok = trim($tok);
            if ($tok === '') {
                continue;
            }
            $construct[] = $this->parseScalar($tok);
        }

        $warn = null;
        if (count($construct) !== $expected) {
            $warn = "模板 {$template} 期望 {$expected} 个构造参数，实际传了 " . count($construct)
                . " 个（构造器有默认值会兜底，多余参数会运行时报错）";
        }
        return [$construct, $warn];
    }

    /** 把单个字符串 token 解析成 PHP 标量（int/float/string） */
    protected function parseScalar(string $token)
    {
        // 单引号 / 双引号字符串
        if ((strlen($token) >= 2)
            && (($token[0] === "'" && $token[strlen($token) - 1] === "'")
                || ($token[0] === '"' && $token[strlen($token) - 1] === '"'))
        ) {
            return substr($token, 1, -1);
        }
        // 整数
        if (preg_match('/^-?\d+$/', $token)) {
            return (int) $token;
        }
        // 浮点数
        if (preg_match('/^-?\d+\.\d+$/', $token)) {
            return (float) $token;
        }
        // 其他保留字符串
        return $token;
    }

    /** 解析 --dir：绝对路径直接用；相对路径基于 BASE_PATH（项目根）解析 */
    protected function resolveDir(string $dir): string
    {
        if (str_starts_with($dir, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $dir)) {
            return rtrim($dir, DIRECTORY_SEPARATOR);
        }
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        return $base . DIRECTORY_SEPARATOR . trim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $dir), DIRECTORY_SEPARATOR);
    }

    /** 从目录推导命名空间：若目录在 app/ 下，把相对路径转成 App\... 命名空间 */
    protected function deriveNamespace(string $dir, string $appPath): string
    {
        $realDir = realpath($dir) ?: $dir;
        $realApp = realpath($appPath) ?: $appPath;

        if ($realApp !== false && strpos($realDir, $realApp) === 0) {
            $rel = substr($realDir, strlen($realApp));
            $rel = trim(str_replace(DIRECTORY_SEPARATOR, '\\', $rel), '\\');
            return $rel === '' ? 'App' : ('App\\' . $rel);
        }
        // 目录在 app 外：用默认命名空间（用户可 --namespace 覆盖）
        return 'App\\Services\\Trader\\Strategies';
    }

    // ========================================================================
    //  文件生成
    // ========================================================================

    protected function writeStrategyFile(array $plan): void
    {
        if (!is_dir($plan['dir'])) {
            mkdir($plan['dir'], 0755, true);
        }
        $code = $this->renderTemplate($plan['template'], $plan['namespace'], $plan['class']);
        file_put_contents($plan['file_path'], $code);
    }

    /**
     * 渲染策略模板代码。
     */
    protected function renderTemplate(string $template, string $namespace, string $className): string
    {
        switch ($template) {
            case 'meanrev':
                return $this->templateMeanRev($namespace, $className);
            case 'blank':
                return $this->templateBlank($namespace, $className);
            case 'ema':
            default:
                return $this->templateEma($namespace, $className);
        }
    }

    // ----------------------------------------------------------------
    //  模板 1：EMA 金叉死叉（默认，完整可跑）
    // ----------------------------------------------------------------
    private function templateEma(string $namespace, string $className): string
    {
        return <<<PHP
<?php

namespace {$namespace};

use App\\Services\\Exchanges\\TradingSymbol;
use App\\Services\\Trader\\Strategy\\AbstractStrategy;
use App\\Services\\Trader\\Strategy\\IndicatorCalculator;
use App\\Services\\Trader\\Strategy\\SignalCols;

/**
 * {$className} — EMA 金叉死叉策略
 *
 * 规则：
 *   入场 LONG：EMA(short) 上穿 EMA(long)，且收盘价 > EMA(long) × (1 + filterPct)
 *   出场 LONG：EMA(short) 下穿 EMA(long)
 *
 * 构造参数（可在 config/trader.php 的 construct 数组里覆盖）：
 *   int   \$emaShort    短周期 EMA
 *   int   \$emaLong     长周期 EMA（须 > short）
 *   float \$filterPct   假信号过滤百分比（0.003 = 0.3%）
 */
class {$className} extends AbstractStrategy
{
    public const COL_EMA_SHORT = SignalCols::NUM_COLUMNS + 0;
    public const COL_EMA_LONG  = SignalCols::NUM_COLUMNS + 1;

    private int \$emaShortPeriod;
    private int \$emaLongPeriod;
    private float \$filterPct;

    // 风控（覆写父类属性）
    protected \$stoploss       = 0.03;
    protected \$minimalRoi     = [
        0   => 0.03,
        60  => 0.02,
        180 => 0.01,
        360 => 0,
    ];
    protected \$trailingStop   = 0.0;
    protected \$defaultStakeAmount = 500.0;
    protected \$maxOpenTrades = 5;
    protected \$maxOpenTradesPerPair = 1;

    protected \$version = '1.0';
    protected \$description = 'EMA 金叉死叉策略';

    public function __construct(int \$emaShort = 20, int \$emaLong = 50, float \$filterPct = 0.003)
    {
        if (\$emaShort <= 0 || \$emaLong <= 0 || \$emaShort >= \$emaLong) {
            throw new \\InvalidArgumentException(
                "EMA 参数非法：期望 0 < short < long（short={\$emaShort}, long={\$emaLong}）"
            );
        }
        \$this->emaShortPeriod = \$emaShort;
        \$this->emaLongPeriod  = \$emaLong;
        \$this->filterPct      = \$filterPct;
    }

    public function getName(): string
    {
        return sprintf('{$className}(%d/%d)', \$this->emaShortPeriod, \$this->emaLongPeriod);
    }

    public function populateIndicators(array \$matrix, TradingSymbol \$symbol, string \$timeframe): array
    {
        unset(\$symbol, \$timeframe);
        \$n = count(\$matrix);
        \$close = [];
        for (\$i = 0; \$i < \$n; \$i++) {
            \$close[] = (float) \$matrix[\$i][SignalCols::CLOSE];
        }
        \$emaShort = IndicatorCalculator::ema(\$close, \$this->emaShortPeriod);
        \$emaLong  = IndicatorCalculator::ema(\$close, \$this->emaLongPeriod);
        for (\$i = 0; \$i < \$n; \$i++) {
            \$matrix[\$i][self::COL_EMA_SHORT] = \$emaShort[\$i];
            \$matrix[\$i][self::COL_EMA_LONG]  = \$emaLong[\$i];
        }
        return \$matrix;
    }

    public function populateEntryTrend(array \$matrix): array
    {
        for (\$i = 1, \$n = count(\$matrix); \$i < \$n; \$i++) {
            \$prevShort = \$matrix[\$i - 1][self::COL_EMA_SHORT];
            \$prevLong  = \$matrix[\$i - 1][self::COL_EMA_LONG];
            \$curShort  = \$matrix[\$i][self::COL_EMA_SHORT];
            \$curLong   = \$matrix[\$i][self::COL_EMA_LONG];
            \$close     = (float) \$matrix[\$i][SignalCols::CLOSE];

            if (\$prevShort <= \$prevLong && \$curShort > \$curLong
                && \$close > \$curLong * (1 + \$this->filterPct)
            ) {
                \$matrix[\$i][SignalCols::ENTER_LONG] = SignalCols::SIG_NORMAL;
                \$matrix[\$i][SignalCols::ENTER_TAG]  = sprintf(
                    'ema_cross_up(close=%.2f,emaS=%.2f,emaL=%.2f)',
                    \$close, \$curShort, \$curLong
                );
            }
        }
        return \$matrix;
    }

    public function populateExitTrend(array \$matrix): array
    {
        for (\$i = 1, \$n = count(\$matrix); \$i < \$n; \$i++) {
            \$prevShort = \$matrix[\$i - 1][self::COL_EMA_SHORT];
            \$prevLong  = \$matrix[\$i - 1][self::COL_EMA_LONG];
            \$curShort  = \$matrix[\$i][self::COL_EMA_SHORT];
            \$curLong   = \$matrix[\$i][self::COL_EMA_LONG];

            if (\$prevShort >= \$prevLong && \$curShort < \$curLong) {
                \$matrix[\$i][SignalCols::EXIT_LONG] = SignalCols::SIG_NORMAL;
                \$matrix[\$i][SignalCols::EXIT_TAG]  = 'ema_cross_down';
            }
        }
        return \$matrix;
    }
}

PHP;
    }

    // ----------------------------------------------------------------
    //  模板 2：均值回归（布林带 + RSI）
    // ----------------------------------------------------------------
    private function templateMeanRev(string $namespace, string $className): string
    {
        return <<<PHP
<?php

namespace {$namespace};

use App\\Services\\Exchanges\\TradingSymbol;
use App\\Services\\Trader\\Strategy\\AbstractStrategy;
use App\\Services\\Trader\\Strategy\\IndicatorCalculator;
use App\\Services\\Trader\\Strategy\\SignalCols;

/**
 * {$className} — 布林带 + RSI 均值回归策略
 *
 * 规则：
 *   入场 LONG：收盘价 ≤ 下轨 AND RSI ≤ oversold
 *   出场 LONG：收盘价 ≥ 中轨 OR RSI ≥ overbought
 *
 * 构造参数：
 *   int   \$bbPeriod       布林带周期
 *   float \$bbStdMult      标准差倍数
 *   int   \$rsiPeriod      RSI 周期
 *   float \$rsiOversold    RSI 超卖阈值
 *   float \$rsiOverbought  RSI 超买阈值
 *   float \$volFilterFactor 量能过滤系数（当前量 > SMA × 系数才允许入场，0 表示不过滤）
 */
class {$className} extends AbstractStrategy
{
    public const COL_BB_MID   = SignalCols::NUM_COLUMNS + 0;
    public const COL_BB_LOWER = SignalCols::NUM_COLUMNS + 1;
    public const COL_BB_UPPER = SignalCols::NUM_COLUMNS + 2;
    public const COL_RSI      = SignalCols::NUM_COLUMNS + 3;
    public const COL_VOL_SMA  = SignalCols::NUM_COLUMNS + 4;

    private int \$bbPeriod;
    private float \$bbStdMult;
    private int \$rsiPeriod;
    private float \$rsiOversold;
    private float \$rsiOverbought;
    private float \$volFilterFactor;

    protected \$stoploss     = 0.05;
    protected \$minimalRoi   = [0 => 0.06, 30 => 0.03, 120 => 0.015, 240 => 0];
    protected \$trailingStop = 0.03;
    protected \$trailingStopPositive = 0.02;
    protected \$maxHoldBars  = 180;
    protected \$defaultStakeAmount = 500.0;
    protected \$maxOpenTrades = 5;
    protected \$maxOpenTradesPerPair = 1;

    protected \$version = '1.0';
    protected \$description = '布林带 + RSI 均值回归';

    public function __construct(
        int \$bbPeriod = 20,
        float \$bbStdMult = 2.0,
        int \$rsiPeriod = 14,
        float \$rsiOversold = 30.0,
        float \$rsiOverbought = 65.0,
        float \$volFilterFactor = 0.8
    ) {
        \$this->bbPeriod = \$bbPeriod;
        \$this->bbStdMult = \$bbStdMult;
        \$this->rsiPeriod = \$rsiPeriod;
        \$this->rsiOversold = \$rsiOversold;
        \$this->rsiOverbought = \$rsiOverbought;
        \$this->volFilterFactor = \$volFilterFactor;
    }

    public function getName(): string
    {
        return sprintf('{$className}(BB%d,%.1fσ,RSI%d)', \$this->bbPeriod, \$this->bbStdMult, \$this->rsiPeriod);
    }

    public function populateIndicators(array \$matrix, TradingSymbol \$symbol, string \$timeframe): array
    {
        unset(\$symbol, \$timeframe);
        \$n = count(\$matrix);
        \$close = \$volume = [];
        for (\$i = 0; \$i < \$n; \$i++) {
            \$close[]  = (float) \$matrix[\$i][SignalCols::CLOSE];
            \$volume[] = (float) \$matrix[\$i][SignalCols::VOLUME];
        }

        \$bbands = IndicatorCalculator::bbands(\$close, \$this->bbPeriod, \$this->bbStdMult, \$this->bbStdMult);
        \$rsi    = IndicatorCalculator::rsi(\$close, \$this->rsiPeriod);
        \$volSma = IndicatorCalculator::sma(\$volume, \$this->bbPeriod);

        for (\$i = 0; \$i < \$n; \$i++) {
            \$matrix[\$i][self::COL_BB_MID]   = \$bbands['mid'][\$i] ?? null;
            \$matrix[\$i][self::COL_BB_LOWER] = \$bbands['lower'][\$i] ?? null;
            \$matrix[\$i][self::COL_BB_UPPER] = \$bbands['upper'][\$i] ?? null;
            \$matrix[\$i][self::COL_RSI]      = \$rsi[\$i] ?? null;
            \$matrix[\$i][self::COL_VOL_SMA]  = \$volSma[\$i] ?? null;
        }
        return \$matrix;
    }

    public function populateEntryTrend(array \$matrix): array
    {
        for (\$i = 0, \$n = count(\$matrix); \$i < \$n; \$i++) {
            \$close    = (float) \$matrix[\$i][SignalCols::CLOSE];
            \$lower    = \$matrix[\$i][self::COL_BB_LOWER];
            \$rsi      = \$matrix[\$i][self::COL_RSI];
            \$vol      = (float) \$matrix[\$i][SignalCols::VOLUME];
            \$volSma   = \$matrix[\$i][self::COL_VOL_SMA];

            if (\$lower === null || \$rsi === null) {
                continue;
            }
            \$volOk = \$this->volFilterFactor <= 0
                || (\$volSma !== null && \$vol > \$volSma * \$this->volFilterFactor);

            if (\$close <= (float) \$lower && (float) \$rsi <= \$this->rsiOversold && \$volOk) {
                \$matrix[\$i][SignalCols::ENTER_LONG] = SignalCols::SIG_NORMAL;
                \$matrix[\$i][SignalCols::ENTER_TAG]  = sprintf('bb_oversold(close=%.2f,lower=%.2f,rsi=%.1f)', \$close, (float) \$lower, (float) \$rsi);
            }
        }
        return \$matrix;
    }

    public function populateExitTrend(array \$matrix): array
    {
        for (\$i = 0, \$n = count(\$matrix); \$i < \$n; \$i++) {
            \$close = (float) \$matrix[\$i][SignalCols::CLOSE];
            \$mid   = \$matrix[\$i][self::COL_BB_MID];
            \$rsi   = \$matrix[\$i][self::COL_RSI];
            if (\$mid === null || \$rsi === null) {
                continue;
            }
            if (\$close >= (float) \$mid || (float) \$rsi >= \$this->rsiOverbought) {
                \$matrix[\$i][SignalCols::EXIT_LONG] = SignalCols::SIG_NORMAL;
                \$matrix[\$i][SignalCols::EXIT_TAG]  = 'bb_mean_revert';
            }
        }
        return \$matrix;
    }
}

PHP;
    }

    // ----------------------------------------------------------------
    //  模板 3：空白骨架（只留必须实现的 3 个方法，用户自行填充）
    // ----------------------------------------------------------------
    private function templateBlank(string $namespace, string $className): string
    {
        return <<<PHP
<?php

namespace {$namespace};

use App\\Services\\Exchanges\\TradingSymbol;
use App\\Services\\Trader\\Strategy\\AbstractStrategy;
use App\\Services\\Trader\\Strategy\\SignalCols;

/**
 * {$className} — 自定义策略
 *
 * 继承 AbstractStrategy，只需实现以下三个方法（其余配置通过 protected 属性赋值）：
 *   - populateIndicators()  计算指标，把自定义列追加到矩阵末尾（下标从 SignalCols::NUM_COLUMNS 开始）
 *   - populateEntryTrend()  写入 ENTER_LONG / ENTER_SHORT / ENTER_TAG 列
 *   - populateExitTrend()   写入 EXIT_LONG  / EXIT_SHORT  / EXIT_TAG  列
 *
 * 矩阵标准列见 SignalCols（DATE/OPEN/HIGH/LOW/CLOSE/VOLUME = 0..5）。
 * 信号常量：SIG_NONE=0 / SIG_NORMAL=1 / SIG_FORCE=2。
 */
class {$className} extends AbstractStrategy
{
    protected \$version = '1.0';
    protected \$description = '';

    public function getName(): string
    {
        return '{$className}';
    }

    public function populateIndicators(array \$matrix, TradingSymbol \$symbol, string \$timeframe): array
    {
        // TODO: 在此计算指标并追加到 \$matrix
        return \$matrix;
    }

    public function populateEntryTrend(array \$matrix): array
    {
        // TODO: 写入 SignalCols::ENTER_LONG / ENTER_SHORT / ENTER_TAG
        return \$matrix;
    }

    public function populateExitTrend(array \$matrix): array
    {
        // TODO: 写入 SignalCols::EXIT_LONG / EXIT_SHORT / EXIT_TAG
        return \$matrix;
    }
}

PHP;
    }

    // ========================================================================
    //  config/trader.php 注入
    // ========================================================================

    /**
     * 把策略条目注入到 config/trader.php 的 'strategies' 数组中。
     *
     * 实现：用 token_get_all 精确定位 'strategies' 子数组的起止，在其结束 ] 之前
     * 插入新条目，保证不破坏文件里的注释/env()/其他结构。
     *
     * @param string $configPath  config/trader.php 绝对路径
     * @param string $alias       策略别名
     * @param string $fullClass   完整类名（带命名空间）
     * @param array  $construct   构造参数数组
     *
     * @throws \RuntimeException 找不到 strategies 块 / 别名已存在
     */
    public function registerInConfig(string $configPath, string $alias, string $fullClass, array $construct): void
    {
        if (!is_file($configPath)) {
            throw new \RuntimeException("配置文件不存在：{$configPath}");
        }

        $content = file_get_contents($configPath);
        $tokens  = token_get_all($content);

        // 1) 找 'strategies' 字符串 token
        $strategiesIdx = null;
        foreach ($tokens as $i => $t) {
            if (is_array($t)
                && $t[0] === T_CONSTANT_ENCAPSED_STRING
                && trim($t[1], "'\"") === 'strategies'
            ) {
                $strategiesIdx = $i;
                break;
            }
        }
        if ($strategiesIdx === null) {
            throw new \RuntimeException("config/trader.php 中未找到 'strategies' 配置块");
        }

        // 2) 找 strategies 后面的数组开始 [
        $openIdx = null;
        for ($i = $strategiesIdx + 1; $i < count($tokens); $i++) {
            if (!is_array($tokens[$i]) && $tokens[$i] === '[') {
                $openIdx = $i;
                break;
            }
        }
        if ($openIdx === null) {
            throw new \RuntimeException("'strategies' 后未找到数组开始 [");
        }

        // 3) 括号匹配找对应的 ]
        $closeIdx = $this->findMatchingBracket($tokens, $openIdx);
        if ($closeIdx === null) {
            throw new \RuntimeException("'strategies' 数组括号不匹配");
        }

        // 4) 检查别名是否已存在（在 openIdx..closeIdx 范围内搜 'alias' 字符串）
        for ($i = $openIdx + 1; $i < $closeIdx; $i++) {
            if (is_array($tokens[$i])
                && $tokens[$i][0] === T_CONSTANT_ENCAPSED_STRING
                && trim($tokens[$i][1], "'\"") === $alias
            ) {
                throw new \RuntimeException(
                    "策略别名 '{$alias}' 已存在于 config/trader.php，换一个 --name 或手动修改配置。"
                );
            }
        }

        // 5) 定位闭合 ] 之前的连续空白 token（T_WHITESPACE），把它替换为
        //    "新条目 + 换行 + 4 空格缩进"，保证闭合 ] 独占一行且缩进正确。
        $wsStartIdx = $closeIdx;
        for ($i = $closeIdx - 1; $i > $openIdx; $i--) {
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
                $wsStartIdx = $i;
                continue;
            }
            break;
        }

        // 计算替换区间在原文中的偏移 [replaceFrom, replaceTo)
        $replaceFrom = 0;
        for ($i = 0; $i < $wsStartIdx; $i++) {
            $replaceFrom += strlen(is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i]);
        }
        $replaceTo = $replaceFrom;
        for ($i = $wsStartIdx; $i < $closeIdx; $i++) {
            $replaceTo += strlen(is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i]);
        }

        // 6) 生成条目代码（8 空格缩进，与文件内现有条目对齐；结尾换行 + 4 空格让 ] 回到正确缩进）
        $entry = $this->buildConfigEntry($alias, $fullClass, $construct);
        $entryText = "\n        " . $entry . ",\n    ";

        $newContent = substr($content, 0, $replaceFrom) . $entryText . substr($content, $replaceTo);
        file_put_contents($configPath, $newContent);
    }

    /**
     * 给定 tokens 中 [ 的下标，返回匹配 ] 的下标（计数法，自动跳过字符串/注释）。
     *
     * @param array<int, array|string> $tokens  token_get_all 结果
     * @param int $openIdx  '[' 在 tokens 中的下标
     * @return int|null
     */
    protected function findMatchingBracket(array $tokens, int $openIdx): ?int
    {
        $depth = 1;
        for ($i = $openIdx + 1, $n = count($tokens); $i < $n; $i++) {
            $t = $tokens[$i];
            if (is_array($t)) {
                continue; // 字符串/注释 token 内部的 [ ] 忽略
            }
            if ($t === '[') {
                $depth++;
            } elseif ($t === ']') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }
        return null;
    }

    /**
     * 生成 config 条目代码。
     *   - construct 非空：'alias' => ['class'=>..., 'construct'=>[...]]
     *   - construct 为空：'alias' => \Full\Class::class
     */
    protected function buildConfigEntry(string $alias, string $fullClass, array $construct): string
    {
        $classLit = '\\' . ltrim($fullClass, '\\') . '::class';
        if ($construct === []) {
            return "'{$alias}' => {$classLit}";
        }
        $constructLit = '[' . implode(', ', array_map([$this, 'renderPhpValue'], $construct)) . ']';
        return "'{$alias}' => [\n"
            . "            'class'     => {$classLit},\n"
            . "            'construct' => {$constructLit},\n"
            . "        ]";
    }

    /** 把 PHP 标量渲染成字面量代码 */
    protected function renderPhpValue($value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            // 用 var_export 保证 0.003 不会变 3.0E-3
            return var_export($value, true);
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        return "'" . addslashes((string) $value) . "'";
    }

    // ========================================================================
    //  语法校验 / 输出
    // ========================================================================

    /** 对生成的 PHP 文件跑 php -l，返回是否通过 */
    protected function lintFile(string $filePath): bool
    {
        $esc = escapeshellarg($filePath);
        exec("php -l {$esc} 2>&1", $lines, $code);
        return $code === 0;
    }

    /** 渲染 dry-run 计划 */
    protected function formatDryRun(array $plan): string
    {
        $out = $this->info('[DRY-RUN] 将执行以下操作（去掉 --dry-run 实际执行）：') . "\n\n";
        $out .= "  策略别名        {$plan['alias']}\n";
        $out .= "  类名            {$plan['class']}\n";
        $out .= "  完整类名        {$plan['full_class']}\n";
        $out .= "  命名空间        {$plan['namespace']}\n";
        $out .= "  保存目录        {$plan['dir']}\n";
        $out .= "  类文件          {$plan['file_path']}\n";
        $out .= "  模板            {$plan['template']}\n";
        $out .= "  构造参数        " . json_encode($plan['construct'], JSON_UNESCAPED_UNICODE) . "\n";
        $out .= "  注册到 config   " . ($plan['no_register'] ? '否（--no-register）' : $plan['config_path']) . "\n";
        $out .= "  覆盖已存在      " . ($plan['force'] ? '是' : '否') . "\n";
        if ($plan['param_warn'] !== null) {
            $out .= "\n" . $this->warn($plan['param_warn']) . "\n";
        }
        return $out;
    }

    /** 成功输出 */
    protected function formatSuccess(array $plan, bool $lintOk): string
    {
        $out = "\n" . $this->info("策略创建成功：{$plan['alias']}") . "\n";
        $out .= "  类文件   {$plan['file_path']}\n";
        $out .= "  完整类名 {$plan['full_class']}\n";
        $out .= "  模板     {$plan['template']}\n";
        $out .= "  构造参数 " . json_encode($plan['construct'], JSON_UNESCAPED_UNICODE) . "\n";

        if ($plan['no_register']) {
            $out .= "  注册     " . $this->warn('跳过（--no-register）') . "\n";
        } else {
            $out .= "  注册     已写入 {$plan['config_path']}\n";
        }

        if (!$lintOk) {
            $out .= "\n" . $this->warn('语法校验（php -l）未通过，请检查生成的文件。') . "\n";
        }

        $out .= "\n使用：\n";
        $out .= "  php bin/sikelan trader:backtest --strategy={$plan['alias']}\n";
        return $out;
    }

    // ------------------------------------------------------------------------
    //  输出辅助
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
创建新策略：生成策略类文件 + 自动注册到 config/trader.php

Usage:
  php sikelan trader:make-strategy --name=<别名> [options]

⭐ 最简（只给别名，类名自动 = 别名 + 'Strategy'，默认 ema 模板）:
  php sikelan trader:make-strategy --name=MyStrat

常用示例:
  # 指定类名（不带 Strategy 后缀也会自动判断）
  php sikelan trader:make-strategy --name=MacdTrend --class=MacdTrendStrategy
  # 用均值回归模板
  php sikelan trader:make-strategy --name=MeanRev2 --template=meanrev
  # 用空白骨架，自己实现逻辑
  php sikelan trader:make-strategy --name=Custom --template=blank
  # 覆盖构造参数（ema 模板: short,long,filterPct）
  php sikelan trader:make-strategy --name=FastEma --params=10,20,0.001
  # 自定义保存目录
  php sikelan trader:make-strategy --name=MyStrat --dir=app/MyStrats
  # 只生成文件，不注册到 config
  php sikelan trader:make-strategy --name=MyStrat --no-register
  # 先看计划不执行
  php sikelan trader:make-strategy --name=MyStrat --dry-run

Options:
  --name=ALIAS         策略别名（必填，config/trader.php 的 key）
  --class=CLASS        类名（可选，默认 = 别名 + 'Strategy'）
  --dir=PATH           保存目录（默认 app/Services/Trader/Strategies；相对路径基于项目根）
  --namespace=NS       命名空间（可选，默认从目录自动推导）
  --template=ema|meanrev|blank  模板（默认 ema）
  --params=v1,v2,...   构造参数值（按模板顺序，逗号分隔；如 20,50,0.003）
  --no-register        只生成类文件，不写入 config/trader.php
  --config=PATH        指定 config/trader.php 路径（默认项目 config/trader.php）
  -f, --force          已存在时覆盖
  --dry-run            只打印计划，不实际生成
  -h, --help           查看本帮助

模板说明:
  ema      EMA 金叉死叉（默认，3 个构造参数：short, long, filterPct）
  meanrev  布林带 + RSI 均值回归（6 个构造参数）
  blank    空白骨架，只留 3 个必须方法的 TODO
HELP;
    }
}
