<?php

namespace Sikelan\Command\DefaultCommand;

use Sikelan\Command\CommandInterface;
use Sikelan\Command\FileOverwriteGuard;

class MakeTaskCommand implements CommandInterface
{
    use FileOverwriteGuard;

    protected string $taskDir;

    public function __construct()
    {
        $this->taskDir = APP_PATH . '/Tasks';
    }

    public function commandName(): string
    {
        return 'make:task';
    }

    public function exec(array $args): ?string
    {
        if (empty($args)) {
            return "\033[31mError: Task name is required.\033[0m\n" . $this->help([]);
        }

        $taskName = $args[0];
        $force = in_array('--force', $args) || in_array('-f', $args);
        // --no-backup：配合 -f 使用，覆盖前不生成 .bak 备份
        $noBackup = in_array('--no-backup', $args);

        if (strpos($taskName, 'Task') === false) {
            $taskName .= 'Task';
        }

        $namespace = 'App\\Tasks';
        $className = $taskName;
        $filePath = $this->taskDir . '/' . $taskName . '.php';

        // 先渲染模板（纯字符串），门禁需要与磁盘文件逐字节比对
        $template = $this->generateTemplate($namespace, $className);

        // 安全写入门禁：检测手工修改，-f 覆盖前自动备份
        $guard = $this->guardWrite($filePath, $template, $force, !$noBackup);
        if ($guard['status'] === 'rejected') {
            return "\033[33mTask '{$taskName}' already exists.\033[0m\n"
                . "\033[33m{$guard['message']}\033[0m";
        }

        $verb = $guard['status'] === 'created' ? 'created successfully' : $guard['status'];
        $out = "\033[32mTask '{$taskName}' {$verb}!\033[0m\nFile: {$filePath}";
        if ($guard['status'] === 'overwritten' && $guard['backup'] !== null) {
            $out .= "\n\033[33m⚠ 原文件已被手工修改，覆盖前已备份：{$guard['backup']}\033[0m";
        }
        return $out;
    }

    public function help(array $args): ?string
    {
        return <<<HELP
Make Task Command

Usage:
  php sikelan make:task <name> [options]

Arguments:
  name            Task name (e.g., SendEmail, ProcessData)

Options:
  -f, --force       Force overwrite if file exists（检测到手工修改会先备份为 .bak 文件）
      --no-backup   配合 -f 使用：覆盖前不备份（慎用）

Examples:
  php sikelan make:task SendEmail
  php sikelan make:task ProcessDataTask
  php sikelan make:task ImportData -f
HELP;
    }

    public function desc(): string
    {
        return 'Create a new async task class';
    }

    protected function generateTemplate(string $namespace, string $className): string
    {
        $taskBaseName = str_replace('Task', '', $className);

        return <<<PHP
<?php

namespace {$namespace};

use Sikelan\Task\TaskInterface;
use Sikelan\Core\Logger;

class {$className} implements TaskInterface
{
    protected ?Logger \$logger = null;

    public function __construct(?Logger \$logger = null)
    {
        \$this->logger = \$logger;
    }

    /**
     * 执行异步任务
     *
     * @param array \$args 任务参数
     * @return array|null 任务结果
     */
    public function handle(array \$args)
    {
        \$this->logger?->info("Task {$taskBaseName} started", \$args);

        try {
            // TODO: 在这里实现你的任务逻辑
            \$result = [
                'success' => true,
                'message' => 'Task executed successfully',
                'task' => '{$taskBaseName}',
                'timestamp' => date('Y-m-d H:i:s'),
                'args' => \$args,
            ];

            \$this->logger?->info("Task {$taskBaseName} completed", \$result);

            return \$result;
        } catch (\Exception \$e) {
            \$this->logger?->error("Task {$taskBaseName} failed", [
                'error' => \$e->getMessage(),
                'trace' => \$e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'message' => \$e->getMessage(),
                'task' => '{$taskBaseName}',
                'timestamp' => date('Y-m-d H:i:s'),
            ];
        }
    }
}

PHP;
    }
}
