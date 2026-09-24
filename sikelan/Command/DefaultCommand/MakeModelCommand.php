<?php

namespace Sikelan\Command\DefaultCommand;

use Sikelan\Command\CommandInterface;
use Sikelan\Command\FileOverwriteGuard;
use Sikelan\Framework;

/**
 * make:model 命令 —— 根据数据库表自动生成 Eloquent Model
 *
 * 用户只需输入表名，命令会：
 *   1. 查询 information_schema 校验表是否存在（不存在直接提示退出）
 *   2. 读取表的全部列信息（字段名、类型、主键、注释等）
 *   3. 自动推导 Model 参数：
 *      - 类名：表名单数化 + PascalCase（users → User，order_items → OrderItem）
 *      - $table / $primaryKey（非 id 主键才输出）
 *      - $fillable：除主键外的全部列
 *      - $casts：按列类型自动映射（tinyint(1)→boolean、int→integer、decimal→decimal:n 等）
 *      - $timestamps：检测到 created_at + updated_at 时自动开启
 *
 * 用法：
 *   php bin/sikelan make:model <table> [--model=ClassName] [-f|--force]
 */
class MakeModelCommand implements CommandInterface
{
    use FileOverwriteGuard;

    /** @var string Model 文件输出目录 */
    protected string $modelDir;

    public function __construct()
    {
        $this->modelDir = APP_PATH . '/Models';
    }

    public function commandName(): string
    {
        return 'make:model';
    }

    public function desc(): string
    {
        return 'Create an Eloquent model from an existing database table';
    }

    public function help(array $args): ?string
    {
        return <<<HELP
Make Model Command (table-driven)

Usage:
  php bin/sikelan make:model <table> [options]

Arguments:
  table               数据库表名（如 users、order_items）

Options:
  --model=ClassName   指定 Model 类名（默认由表名自动推导，如 users → User）
  -f, --force         文件已存在时强制覆盖（检测到手工修改会先备份为 .bak 文件）
      --no-backup     配合 -f 使用：覆盖前不备份（慎用）

Examples:
  php bin/sikelan make:model users
  php bin/sikelan make:model order_items
  php bin/sikelan make:model t_user --model=Member
  php bin/sikelan make:model users -f
HELP;
    }

    public function exec(array $args): ?string
    {
        // ---- 1. 解析参数 ----
        $parsed = $this->parseArgs($args);
        if ($parsed['table'] === '') {
            return "\033[31mError: Table name is required.\033[0m\n" . $this->help([]);
        }

        $table = $parsed['table'];

        // ---- 2. 查询数据库：表不存在 / 连不上直接退出 ----
        try {
            $columns = $this->inspectTable($table);
        } catch (\Throwable $e) {
            return "\033[31mError: Cannot inspect table '{$table}': " . $e->getMessage() . "\033[0m\n"
                . "请检查 .env 中 DB_HOST / DB_USERNAME / DB_PASSWORD / DB_DATABASE 配置。";
        }

        if ($columns === null) {
            return "\033[31mError: Table '{$table}' does not exist in the current database.\033[0m\n"
                . "请确认表名拼写，或先执行建表迁移。";
        }

        // ---- 3. 推导类名与 Model 参数 ----
        $className = $parsed['model'] !== '' ? $parsed['model'] : $this->tableToClassName($table);
        $filePath = $this->modelDir . '/' . $className . '.php';

        // 快速路径：文件存在且未授权覆盖，直接拒绝（模板依赖表结构，先不查库渲染）
        // 注意：-f 时仍会经 guardWrite 逐字节比对，内容一致则 unchanged，不一致才备份覆盖
        if (file_exists($filePath) && !$parsed['force']) {
            return "\033[33mModel '{$className}' already exists ({$filePath}).\033[0m\n"
                . "Use -f or --force to overwrite（-f 检测到手工修改时会先备份为 .bak 文件）。";
        }

        $meta = $this->buildModelMeta($table, $columns);
        $template = $this->generateTemplate($className, $meta);

        // 安全写入门禁（走到这里一定带 -f 或文件不存在）
        $guard = $this->guardWrite($filePath, $template, $parsed['force'], !$parsed['no_backup']);

        // ---- 4. 输出结果摘要 ----
        $verb = $guard['status'] === 'created' ? 'created successfully' : $guard['status'];
        $lines = [];
        $lines[] = "\033[32mModel '{$className}' {$verb}!\033[0m";
        $lines[] = "File:   {$filePath}";
        $lines[] = "Table:  {$table} (" . count($columns) . " columns)";
        $lines[] = "Fillable: " . implode(', ', $meta['fillable']);
        if (!empty($meta['casts'])) {
            $lines[] = "Casts:   " . implode(', ', array_map(
                fn(string $field, string $cast): string => "{$field}=>{$cast}",
                array_keys($meta['casts']),
                $meta['casts']
            ));
        }
        if ($guard['status'] === 'overwritten' && $guard['backup'] !== null) {
            $lines[] = "\033[33m⚠ 原文件已被手工修改，覆盖前已备份：{$guard['backup']}\033[0m";
        }

        return implode("\n", $lines);
    }

    /**
     * 解析命令行参数
     *
     * @param array $args 原始参数数组
     * @return array{table:string, model:string, force:bool, no_backup:bool}
     */
    protected function parseArgs(array $args): array
    {
        $table = '';
        $model = '';
        $force = false;
        $noBackup = false;

        foreach ($args as $arg) {
            if ($arg === '-f' || $arg === '--force') {
                $force = true;
            } elseif ($arg === '--no-backup') {
                $noBackup = true;
            } elseif (strpos($arg, '--model=') === 0) {
                $model = substr($arg, 8);
            } elseif ($arg === '--model') {
                // --model 后接值的情况由下一轮循环处理（这里置标记，简单起见暂不支持空格形式）
                continue;
            } elseif (strpos($arg, '-') !== 0 && $table === '') {
                $table = $arg;
            }
        }

        return [
            'table' => $table,
            'model' => $model,
            'force' => $force,
            'no_backup' => $noBackup,
        ];
    }

    /**
     * 查询表结构
     *
     * 通过 information_schema 读取列信息（参数化查询，兼容 MySQL 5.7/8.0）。
     *
     * @param string $table 表名
     * @return array<int, array<string, string>>|null 列信息数组；表不存在返回 null
     * @throws \RuntimeException 数据库连接/查询失败
     */
    protected function inspectTable(string $table): ?array
    {
        $connection = Framework::getInstance()->getEloquent()->connection();
        $database = $connection->getDatabaseName();

        // 先判断表是否存在
        $exists = $connection->select(
            'SELECT 1 AS found FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? LIMIT 1',
            [$database, $table]
        );
        if (empty($exists)) {
            return null;
        }

        // 读取全部列信息（按列顺序）
        $rows = $connection->select(
            'SELECT COLUMN_NAME, COLUMN_TYPE, DATA_TYPE, IS_NULLABLE, COLUMN_KEY, EXTRA, COLUMN_COMMENT
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
             ORDER BY ORDINAL_POSITION',
            [$database, $table]
        );

        // 转数组：information_schema 返回大写下划线列名，数组键不受命名规范约束
        return array_map(static function (\stdClass $row): array {
            $r = (array) $row;
            return [
                'name' => $r['COLUMN_NAME'],
                'column_type' => $r['COLUMN_TYPE'],
                'data_type' => $r['DATA_TYPE'],
                'nullable' => $r['IS_NULLABLE'] === 'YES',
                'key' => $r['COLUMN_KEY'],
                'extra' => $r['EXTRA'],
                'comment' => $r['COLUMN_COMMENT'],
            ];
        }, $rows);
    }

    /**
     * 表名 → Model 类名（snake_case 复数 → PascalCase 单数）
     *
     * 例：users → User，order_items → OrderItem，categories → Category
     *
     * @param string $table 表名
     * @return string PascalCase 类名
     */
    public function tableToClassName(string $table): string
    {
        $parts = explode('_', $table);
        // 只对最后一段做单数化（order_items → order + item）
        $last = array_pop($parts);
        $parts[] = $this->singularize($last);

        return implode('', array_map(static fn(string $p): string => ucfirst($p), $parts));
    }

    /**
     * 英文单词单数化（覆盖常见规则与不规则名词）
     *
     * @param string $word 复数单词
     * @return string 单数单词
     */
    protected function singularize(string $word): string
    {
        // 不规则名词
        $irregular = [
            'people' => 'person',
            'men' => 'man',
            'women' => 'woman',
            'children' => 'child',
            'mice' => 'mouse',
            'geese' => 'goose',
            'feet' => 'foot',
            'teeth' => 'tooth',
        ];
        if (isset($irregular[$word])) {
            return $irregular[$word];
        }

        // categories → category（辅音 + ies → y）
        if (preg_match('/.ies$/', $word) && strlen($word) > 3) {
            return substr($word, 0, -3) . 'y';
        }
        // buses/boxes/watches/dishes → 去 es
        if (preg_match('/(ses|xes|zes|ches|shes)$/', $word)) {
            return substr($word, 0, -2);
        }
        // 普通复数：users → user（ss 结尾不变，如 class）
        if (substr($word, -1) === 's' && substr($word, -2) !== 'ss') {
            return substr($word, 0, -1);
        }

        return $word;
    }

    /**
     * 从列信息推导 Model 参数
     *
     * @param string $table 表名
     * @param array  $columns inspectTable() 返回的列信息
     * @return array{
     *     table:string, primaryKey:string|null, incrementing:bool, keyType:string,
     *     fillable:array<int,string>, casts:array<string,string>, timestamps:bool
     * }
     */
    public function buildModelMeta(string $table, array $columns): array
    {
        $primaryKey = null;
        $primaryExtra = '';
        $primaryDataType = '';
        $fillable = [];
        $casts = [];
        $hasCreatedAt = false;
        $hasUpdatedAt = false;

        foreach ($columns as $col) {
            $name = $col['name'];

            if ($name === 'created_at') {
                $hasCreatedAt = true;
            }
            if ($name === 'updated_at') {
                $hasUpdatedAt = true;
            }

            if ($col['key'] === 'PRI') {
                $primaryKey = $name;
                $primaryExtra = $col['extra'];
                $primaryDataType = $col['data_type'];
                continue; // 主键不进 fillable
            }

            $fillable[] = $name;

            $cast = $this->resolveCast($col['data_type'], $col['column_type']);
            if ($cast !== null) {
                $casts[$name] = $cast;
            }
        }

        // 非自增字符串主键（如 UUID）→ keyType=string + incrementing=false
        $incrementing = true;
        $keyType = 'int';
        if ($primaryKey !== null && strpos($primaryExtra, 'auto_increment') === false) {
            if (in_array($primaryDataType, ['char', 'varchar'], true)) {
                $keyType = 'string';
                $incrementing = false;
            }
        }

        return [
            'table' => $table,
            'primaryKey' => $primaryKey,
            'incrementing' => $incrementing,
            'keyType' => $keyType,
            'fillable' => $fillable,
            'casts' => $casts,
            'timestamps' => $hasCreatedAt && $hasUpdatedAt,
        ];
    }

    /**
     * MySQL 数据类型 → Eloquent $casts 映射
     *
     * @param string $dataType   information_schema 的 DATA_TYPE（如 int、decimal）
     * @param string $columnType 完整列类型（如 tinyint(1)、decimal(10,2)）
     * @return string|null cast 值；无需转换返回 null
     */
    protected function resolveCast(string $dataType, string $columnType): ?string
    {
        // tinyint(1) 是 MySQL 的布尔约定
        if ($dataType === 'tinyint' && $columnType === 'tinyint(1)') {
            return 'boolean';
        }

        switch ($dataType) {
            case 'tinyint':
            case 'smallint':
            case 'mediumint':
            case 'int':
            case 'bigint':
                return 'integer';
            case 'decimal':
            case 'numeric':
                // decimal(10,2) → decimal:2
                if (preg_match('/\((\d+),(\d+)\)/', $columnType, $m)) {
                    return 'decimal:' . $m[2];
                }
                return 'decimal:2';
            case 'float':
            case 'double':
                return 'float';
            case 'json':
                return 'array';
            case 'date':
                return 'date';
            case 'datetime':
            case 'timestamp':
                return 'datetime';
            default:
                return null;
        }
    }

    /**
     * 生成 Model PHP 文件内容
     *
     * @param string $className 类名
     * @param array  $meta      buildModelMeta() 返回的参数
     * @return string PHP 代码
     */
    public function generateTemplate(string $className, array $meta): string
    {
        $lines = [];
        $lines[] = '<?php';
        $lines[] = '';
        $lines[] = 'namespace App\\Models;';
        $lines[] = '';
        $lines[] = '/**';
        $lines[] = " * 表 `{$meta['table']}` 的 Eloquent 模型（由 make:model 自动生成）";
        $lines[] = ' */';
        $lines[] = "class {$className} extends Model";
        $lines[] = '{';
        $lines[] = "    protected \$table = '{$meta['table']}';";
        $lines[] = '';

        // 非 id 主键才显式声明
        if ($meta['primaryKey'] !== null && $meta['primaryKey'] !== 'id') {
            $lines[] = "    protected \$primaryKey = '{$meta['primaryKey']}';";
            $lines[] = '';
        }
        // 字符串主键（UUID 等）
        if ($meta['keyType'] === 'string') {
            $lines[] = '    protected $keyType = \'string\';';
            $lines[] = '';
            $lines[] = '    public $incrementing = false;';
            $lines[] = '';
        }

        // fillable
        $lines[] = '    protected $fillable = [';
        foreach ($meta['fillable'] as $field) {
            $lines[] = "        '{$field}',";
        }
        $lines[] = '    ];';

        // casts
        if (!empty($meta['casts'])) {
            $lines[] = '';
            $lines[] = '    protected $casts = [';
            foreach ($meta['casts'] as $field => $cast) {
                $lines[] = "        '{$field}' => '{$cast}',";
            }
            $lines[] = '    ];';
        }

        // 时间戳（基类默认 false，检测到双时间列时显式开启）
        if ($meta['timestamps']) {
            $lines[] = '';
            $lines[] = '    public $timestamps = true;';
        }

        $lines[] = '}';
        $lines[] = '';

        return implode("\n", $lines);
    }
}
