<?php

namespace Sikelan\Command\DefaultCommand;

use Sikelan\Command\CommandInterface;

class MakeModelCommand implements CommandInterface
{
    protected string $modelDir;

    public function __construct()
    {
        $this->modelDir = APP_PATH . '/Models';
    }

    public function commandName(): string
    {
        return 'make:model';
    }

    public function exec(array $args): ?string
    {
        if (empty($args)) {
            return "\033[31mError: Model name is required.\033[0m\n" . $this->help([]);
        }

        $modelName = $args[0];
        $force = in_array('--force', $args) || in_array('-f', $args);

        if (strpos($modelName, 'Model') === false) {
            $modelName .= 'Model';
        }

        $namespace = 'App\\Models';
        $className = $modelName;
        $filePath = $this->modelDir . '/' . $modelName . '.php';

        if (file_exists($filePath) && !$force) {
            return "\033[33mModel '{$modelName}' already exists.\033[0m\nUse --force or -f to overwrite.";
        }

        $template = $this->generateTemplate($namespace, $className);

        if (!is_dir($this->modelDir)) {
            mkdir($this->modelDir, 0755, true);
        }

        file_put_contents($filePath, $template);

        return "\033[32mModel '{$modelName}' created successfully!\033[0m\nFile: {$filePath}";
    }

    public function help(array $args): ?string
    {
        return <<<HELP
Make Model Command

Usage:
  php sikelan make:model <name> [options]

Arguments:
  name            Model name (e.g., User, Product, Order)

Options:
  -f, --force     Force overwrite if file exists

Examples:
  php sikelan make:model User
  php sikelan make:model ProductModel
  php sikelan make:model Order -f
HELP;
    }

    public function desc(): string
    {
        return 'Create a new model class';
    }

    protected function generateTemplate(string $namespace, string $className): string
    {
        $tableName = $this->toSnakeCase(str_replace('Model', '', $className));
        $tableName = $this->toPlural($tableName);

        return <<<PHP
<?php

namespace {$namespace};

class {$className}
{
    protected string \$table = '{$tableName}';
    protected string \$primaryKey = 'id';

    public function getTable(): string
    {
        return \$this->table;
    }

    public function getPrimaryKey(): string
    {
        return \$this->primaryKey;
    }

    public function find(int \$id): ?array
    {
        \$db = \Sikelan\Framework::getInstance()->getDb();
        \$result = \$db->select(
            "SELECT * FROM {\$this->table} WHERE {\$this->primaryKey} = ?",
            [\$id]
        );

        return \$result[0] ?? null;
    }

    public function all(int \$page = 1, int \$perPage = 20): array
    {
        \$db = \Sikelan\Framework::getInstance()->getDb();
        \$offset = (\$page - 1) * \$perPage;

        \$list = \$db->select(
            "SELECT * FROM {\$this->table} ORDER BY {\$this->primaryKey} DESC LIMIT ? OFFSET ?",
            [\$perPage, \$offset]
        );

        \$count = \$db->select("SELECT COUNT(*) as total FROM {\$this->table}");

        return [
            'list' => \$list,
            'total' => (int)(\$count[0]['total'] ?? 0),
            'page' => \$page,
            'per_page' => \$perPage,
        ];
    }

    public function create(array \$data): int
    {
        \$db = \Sikelan\Framework::getInstance()->getDb();
        \$columns = implode(', ', array_keys(\$data));
        \$placeholders = implode(', ', array_fill(0, count(\$data), '?'));

        \$db->query(
            "INSERT INTO {\$this->table} ({\$columns}) VALUES ({\$placeholders})",
            array_values(\$data)
        );

        return (int)\$db->lastInsertId();
    }

    public function update(int \$id, array \$data): bool
    {
        \$db = \Sikelan\Framework::getInstance()->getDb();
        \$set = [];
        foreach (array_keys(\$data) as \$column) {
            \$set[] = "{\$column} = ?";
        }

        \$db->query(
            "UPDATE {\$this->table} SET " . implode(', ', \$set) . " WHERE {\$this->primaryKey} = ?",
            array_merge(array_values(\$data), [\$id])
        );

        return true;
    }

    public function delete(int \$id): bool
    {
        \$db = \Sikelan\Framework::getInstance()->getDb();
        \$db->query(
            "DELETE FROM {\$this->table} WHERE {\$this->primaryKey} = ?",
            [\$id]
        );

        return true;
    }
}

PHP;
    }

    protected function toSnakeCase(string $input): string
    {
        return strtolower(preg_replace('/[A-Z]/', '_$0', lcfirst($input)));
    }

    protected function toPlural(string $word): string
    {
        $endings = ['s', 'x', 'z', 'ch', 'sh'];
        foreach ($endings as $ending) {
            if (substr($word, -strlen($ending)) === $ending) {
                return $word . 'es';
            }
        }
        if (substr($word, -1) === 'y') {
            return substr($word, 0, -1) . 'ies';
        }
        return $word . 's';
    }
}
