<?php

namespace Sikelan\Command\DefaultCommand;

use Sikelan\Command\CommandInterface;

class MakeTaskCommand implements CommandInterface
{
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

        if (strpos($taskName, 'Task') === false) {
            $taskName .= 'Task';
        }

        $namespace = 'App\\Tasks';
        $className = $taskName;
        $filePath = $this->taskDir . '/' . $taskName . '.php';

        if (file_exists($filePath) && !$force) {
            return "\033[33mTask '{$taskName}' already exists.\033[0m\nUse --force or -f to overwrite.";
        }

        $template = $this->generateTemplate($namespace, $className);

        if (!is_dir($this->taskDir)) {
            mkdir($this->taskDir, 0755, true);
        }

        file_put_contents($filePath, $template);

        return "\033[32mTask '{$taskName}' created successfully!\033[0m\nFile: {$filePath}";
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
  -f, --force     Force overwrite if file exists

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
