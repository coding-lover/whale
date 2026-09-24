<?php

namespace App\Services\Trader;

use App\Models\Strategy;
use App\Services\Trader\Strategy\StrategyInterface;

/**
 * 策略同步服务：把 config/trader.php 注册表 + 策略目录中的策略类同步到 strategies 表。
 *
 * 数据源（优先级从高到低）：
 *   1. config/trader.php 的 strategies 注册表（权威源：含别名 + 构造参数）
 *   2. app/Services/Trader/Strategies 目录扫描（补充注册表遗漏的类，无构造参数）
 *
 * 增量同步规则（以 alias 为唯一键）：
 *   - 表中不存在 → 插入（status 默认 enabled）
 *   - 表中已存在 → 逐字段比较，只更新发生变化的字段；无变化不写库
 *   - status 属于运营字段（可被手动 disabled），同步时一律保留 DB 现值
 *
 * 用法：
 *   $stats = (new StrategySyncService())->syncAll();
 *   // $stats = ['created'=>[...], 'updated'=>[...], 'unchanged'=>[...], 'skipped'=>[alias=>原因]]
 */
class StrategySyncService
{
    /** 同步时由代码侧覆盖的字段（status 不在其中，归运营管理） */
    private const MANAGED_FIELDS = [
        'name',
        'class_name',
        'version',
        'params',
        'default_timeframe',
        'trading_mode',
        'can_short',
        'description',
    ];

    /** 策略类默认目录（相对 APP_PATH） */
    public const DEFAULT_STRATEGY_DIR = 'Services/Trader/Strategies';

    // ========================================================================
    //  对外入口
    // ========================================================================

    /**
     * 全量同步：读取 config/trader.php 注册表（+ 目录扫描）后写入 strategies 表。
     *
     * @param array{
     *   no_scan?: bool,
     *   dir?: string,
     *   dry_run?: bool,
     *   default_timeframe?: string,
     *   trading_mode?: string
     * } $options
     *     no_scan  true=只同步 config 注册表；dir=策略目录覆盖；dry_run=true 只不落库
     * @return array{created:array<int,string>, updated:array<int,string>,
     *               unchanged:array<int,string>, skipped:array<string,string>}
     */
    public function syncAll(array $options = []): array
    {
        $registry = BacktestServiceProvider::getStrategyRegistry($this->resolveTraderConfig());
        return $this->sync($registry, $options);
    }

    /**
     * 同步给定注册表（便于测试 / 外部传入自定义注册表）。
     *
     * @param array<string, array{class:class-string, construct:array<int,mixed>}> $registry
     * @param array<string, mixed> $options 同 syncAll()
     * @return array{created:array<int,string>, updated:array<int,string>,
     *               unchanged:array<int,string>, skipped:array<string,string>}
     */
    public function sync(array $registry, array $options = []): array
    {
        $registry = $this->normalizeRegistry($registry);

        // 目录扫描结果在前，config 注册表在后（同名 key config 覆盖扫描结果）
        if (empty($options['no_scan'])) {
            $dir = (string) ($options['dir'] ?? $this->defaultStrategyDir());
            $registry = array_merge($this->scanDirectory($dir, $registry), $registry);
        }

        $stats = ['created' => [], 'updated' => [], 'unchanged' => [], 'skipped' => []];
        foreach ($registry as $alias => $entry) {
            $result = $this->upsertEntry(
                (string) $alias,
                $entry['class'],
                $entry['construct'],
                $options,
                (bool) ($options['dry_run'] ?? false)
            );
            if ($result === 'created' || $result === 'updated' || $result === 'unchanged') {
                $stats[$result][] = (string) $alias;
            } else {
                // 'skipped:原因'
                $stats['skipped'][(string) $alias] = substr($result, strlen('skipped:'));
            }
        }
        return $stats;
    }

    /**
     * 同步单个策略（trader:make-strategy 创建后调用）。
     *
     * @param string               $alias    策略别名
     * @param class-string         $class    完整类名
     * @param array<int, mixed>    $construct 构造参数
     * @param array{
     *   file_path?: string,
     *   default_timeframe?: string,
     *   trading_mode?: string
     * } $options  file_path：新建类文件路径（类尚未进 autoload 时先 require）
     * @param bool                 $dryRun   true=只计算结果不落库
     * @return string created|updated|unchanged|skipped:<原因>
     */
    public function upsertEntry(
        string $alias,
        string $class,
        array $construct = [],
        array $options = [],
        bool $dryRun = false
    ): string {
        // 新建的策略类可能还没进入 composer autoload，先按需 require
        if (!class_exists($class) && !empty($options['file_path']) && is_file($options['file_path'])) {
            require_once $options['file_path'];
        }
        if (!class_exists($class)) {
            return 'skipped:类不存在 ' . $class;
        }
        if (!is_a($class, StrategyInterface::class, true)) {
            return 'skipped:未实现 StrategyInterface ' . $class;
        }

        try {
            /** @var StrategyInterface $instance */
            $instance = $construct === [] ? new $class() : new $class(...$construct);
        } catch (\Throwable $e) {
            return 'skipped:实例化失败 ' . $e->getMessage();
        }

        $data = [
            'name'              => $instance->getName(),
            'class_name'        => $class,
            'version'           => $instance->getVersion(),
            'params'            => $construct,
            'default_timeframe' => (string) ($options['default_timeframe'] ?? $this->traderValue('timeframe', '1h')),
            'trading_mode'      => (string) ($options['trading_mode'] ?? $this->traderValue('trading_mode', 'spot')),
            'can_short'         => $instance->canShort(),
            'description'       => $instance->getDescription(),
        ];

        $existing = Strategy::query()->where('alias', $alias)->first();

        if ($existing === null) {
            if (!$dryRun) {
                Strategy::create($data + ['alias' => $alias, 'status' => 'enabled']);
            }
            return 'created';
        }

        // 已存在：逐字段比较，只覆盖发生变化的字段（status 保留 DB 现值）
        $changed = [];
        foreach (self::MANAGED_FIELDS as $field) {
            if (!$this->fieldEquals($existing, $field, $data[$field])) {
                $changed[$field] = $data[$field];
            }
        }
        if ($changed === []) {
            return 'unchanged';
        }
        if (!$dryRun) {
            $existing->fill($changed)->save();
        }
        return 'updated';
    }

    // ========================================================================
    //  目录扫描
    // ========================================================================

    /**
     * 扫描策略目录，返回注册表里没有的策略类（alias => class/construct）。
     *
     * @param string                                                                   $dir     策略目录绝对路径
     * @param array<string, array{class:class-string, construct:array<int,mixed>}>    $exclude 已注册条目（按类名/别名去重）
     * @return array<string, array{class:class-string, construct:array<int,mixed>}>
     */
    public function scanDirectory(string $dir, array $exclude = []): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        // 已注册的类名 / 别名集合
        $usedClasses = [];
        $usedAliases = [];
        foreach ($exclude as $exAlias => $exEntry) {
            $usedClasses[ltrim((string) ($exEntry['class'] ?? ''), '\\')] = true;
            $usedAliases[(string) $exAlias] = true;
        }

        $found = [];
        $files = glob($dir . '/*.php');
        if ($files === false) {
            return [];
        }
        foreach ($files as $file) {
            $className = $this->guessClassName($file, $dir);
            if ($className === null || isset($usedClasses[$className])) {
                continue;
            }
            if (!is_a($className, StrategyInterface::class, true)) {
                continue;
            }
            $alias = $this->aliasFromClass($className);
            // 别名冲突（已被注册表占用或本次扫描已见）→ 不覆盖
            if (isset($usedAliases[$alias]) || isset($found[$alias])) {
                continue;
            }
            $found[$alias] = ['class' => $className, 'construct' => []];
        }
        return $found;
    }

    /**
     * 由文件路径推导完整类名（PSR-4：App\ → app/）。
     * 目录不在 app/ 下时返回 null。
     *
     * @return class-string|null
     */
    private function guessClassName(string $file, string $dir): ?string
    {
        $appPath = defined('APP_PATH') ? realpath(APP_PATH) : false;
        $realDir = realpath($dir) ?: $dir;
        if ($appPath === false || strpos($realDir, $appPath) !== 0) {
            return null;
        }
        $rel = trim(substr($realDir, strlen($appPath)), DIRECTORY_SEPARATOR);
        $namespace = 'App' . ($rel === '' ? '' : '\\' . str_replace(DIRECTORY_SEPARATOR, '\\', $rel));
        /** @var class-string $class */
        $class = $namespace . '\\' . basename($file, '.php');
        return $class;
    }

    /**
     * 从类名推导目录扫描用的别名：去掉命名空间，去掉尾部 Strategy 后缀。
     * 例：App\...\EmaCrossStrategy → EmaCross
     */
    private function aliasFromClass(string $class): string
    {
        $short = substr($class, (int) strrpos($class, '\\') + 1);
        if (substr($short, -8) === 'Strategy' && strlen($short) > 8) {
            return substr($short, 0, -8);
        }
        return $short;
    }

    // ========================================================================
    //  内部辅助
    // ========================================================================

    /**
     * 归一化注册表结构，保证每条都有 class / construct 两个 key。
     *
     * @param array<string, mixed> $registry
     * @return array<string, array{class:class-string, construct:array<int,mixed>}>
     */
    private function normalizeRegistry(array $registry): array
    {
        $normalized = [];
        foreach ($registry as $alias => $entry) {
            if (is_string($entry)) {
                $normalized[(string) $alias] = ['class' => $entry, 'construct' => []];
            } elseif (is_array($entry) && isset($entry['class'])) {
                $normalized[(string) $alias] = [
                    'class'     => $entry['class'],
                    'construct' => array_values((array) ($entry['construct'] ?? [])),
                ];
            }
        }
        return $normalized;
    }

    /**
     * 比较 DB 现值与期望值是否一致。
     * params 是 JSON 字段（Eloquent 已 cast 为 array），用规范化 JSON 比较。
     *
     * @param mixed $desired
     */
    private function fieldEquals(Strategy $existing, string $field, $desired): bool
    {
        $current = $existing->{$field};
        if ($field === 'params') {
            return $this->canonicalJson($current) === $this->canonicalJson($desired);
        }
        return $current === $desired;
    }

    /** @param mixed $value */
    private function canonicalJson($value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** 策略目录绝对路径 */
    private function defaultStrategyDir(): string
    {
        $appPath = defined('APP_PATH') ? APP_PATH : dirname(__DIR__, 3);
        return $appPath . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, self::DEFAULT_STRATEGY_DIR);
    }

    /**
     * 读取 trader 配置值；Framework 未启动（如隔离测试）时回退默认值。
     * @return mixed
     */
    private function traderValue(string $key, string $default)
    {
        try {
            if (function_exists('config')) {
                return config('trader.' . $key, $default);
            }
        } catch (\Throwable $e) {
            // Framework 未引导时使用默认值
        }
        return $default;
    }

    /** 取得 trader.php 配置数组（供 getStrategyRegistry 使用） */
    private function resolveTraderConfig(): array
    {
        try {
            if (function_exists('config')) {
                $trader = config('trader');
                if (is_array($trader)) {
                    return $trader;
                }
            }
        } catch (\Throwable $e) {
            // 回退到直接加载配置文件
        }
        $path = (defined('CONFIG_PATH') ? CONFIG_PATH : dirname(__DIR__, 3) . '/config') . '/trader.php';
        return is_file($path) ? (array) require $path : [];
    }
}
