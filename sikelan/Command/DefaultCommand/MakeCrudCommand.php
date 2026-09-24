<?php

namespace Sikelan\Command\DefaultCommand;

use Sikelan\Command\CommandInterface;
use Sikelan\Command\RouteUpdaterTrait;

/**
 * make:crud 命令 —— 一键生成 Model + ResourceController + 路由
 *
 * 封装 make:model + make:controller --model，让用户一条命令完成 CRUD 骨架：
 *
 *   php bin/sikelan make:crud users
 *
 * 等价于：
 *   php bin/sikelan make:model users
 *   php bin/sikelan make:controller User --model=User
 *
 * 生成内容：
 *   1. app/Models/User.php              （从 users 表结构推导 fillable/casts/timestamps）
 *   2. app/Controllers/UserController.php  （继承 ResourceController，绑定 User 模型）
 *   3. config/router.php 自动追加 RESTful 路由
 */
class MakeCrudCommand implements CommandInterface
{
    use RouteUpdaterTrait;
    public function commandName(): string
    {
        return 'make:crud';
    }

    public function desc(): string
    {
        return 'Generate Model + CRUD Controller + routes from a database table';
    }

    public function help(array $args): ?string
    {
        return <<<HELP
Make CRUD Command (table-driven, one-shot)

Usage:
  php sikelan make:crud <table> [options]

Arguments:
  table               数据库表名（如 users、order_items）

Options:
  --model=ClassName   指定 Model 类名（默认由表名推导，users → User）
  -f, --force         文件已存在时强制覆盖

Examples:
  php sikelan make:crud users
  php sikelan make:crud order_items
  php sikelan make:crud t_user --model=Member -f

Output:
  1. app/Models/<Model>.php              Eloquent Model（fillable/casts 自动推导）
  2. app/Controllers/<Model>Controller.php  ResourceController 子类（完整 CRUD）
  3. config/router.php                   自动追加 5 条 RESTful 路由
HELP;
    }

    public function exec(array $args): ?string
    {
        if (empty($args)) {
            return "\033[31mError: Table name is required.\033[0m\n" . $this->help([]);
        }

        $force = in_array('--force', $args) || in_array('-f', $args);
        $modelArg = '';
        $table = '';

        foreach ($args as $arg) {
            if (strpos($arg, '--model=') === 0) {
                $modelArg = substr($arg, 8);
            } elseif (strpos($arg, '-') !== 0 && $table === '') {
                $table = $arg;
            }
        }

        if ($table === '') {
            return "\033[31mError: Table name is required.\033[0m\n" . $this->help([]);
        }

        // ---- 1. 生成 Model（复用 MakeModelCommand） ----
        $modelCmd = new MakeModelCommand();
        $modelArgs = [$table];
        if ($modelArg !== '') {
            $modelArgs[] = "--model={$modelArg}";
        }
        if ($force) {
            $modelArgs[] = '--force';
        }
        $modelResult = $modelCmd->exec($modelArgs);

        // Model 生成失败（表不存在/连不上库）直接返回错误，不继续生成 Controller
        if (strpos($modelResult, "\033[31mError:") !== false) {
            return $modelResult;
        }

        // 从结果中提取生成的 Model 类名
        $className = $this->extractClassName($modelResult, $table, $modelArg);

        // ---- 2. 生成 ResourceController（绑定 Model） ----
        $controllerCmd = new MakeControllerCommand();
        $controllerArgs = [$className, "--model={$className}"];
        if ($force) {
            $controllerArgs[] = '--force';
        }
        $controllerResult = $controllerCmd->exec($controllerArgs);

        // ---- 3. 汇总输出 ----
        $lines = [];
        $lines[] = "\033[32m✓ CRUD scaffold for table '{$table}' generated!\033[0m";
        $lines[] = '';
        $lines[] = "--- Model ---";
        $lines[] = $modelResult;
        $lines[] = '';
        $lines[] = "--- Controller ---";
        $lines[] = $controllerResult;
        $lines[] = '';
        $lines[] = "\033[36mNext steps:\033[0m";
        $lines[] = "  1. 编辑 app/Models/{$className}.php 确认 \$fillable / \$casts";
        $lines[] = "  2. 编辑 app/Controllers/{$className}Controller.php 设置 \$rules / \$filterable";
        $lines[] = "  3. 启动服务后访问 RESTful 接口：";
        $plural = $this->toPlural(strtolower($className));
        $lines[] = "     GET    /api/{$plural}        列表";
        $lines[] = "     GET    /api/{$plural}/{id}   详情";
        $lines[] = "     POST   /api/{$plural}        创建";
        $lines[] = "     PUT    /api/{$plural}/{id}   更新";
        $lines[] = "     DELETE /api/{$plural}/{id}   删除";

        return implode("\n", $lines);
    }

    /**
     * 从 make:model 输出中提取类名；失败则按表名推导
     */
    private function extractClassName(string $output, string $table, string $modelArg): string
    {
        if ($modelArg !== '') {
            return $modelArg;
        }
        // 尝试从 "Model 'User' created" 中提取
        if (preg_match("/Model '([^']+)' created/", $output, $m)) {
            return $m[1];
        }
        // 兜底：用 MakeModelCommand 的推导逻辑
        $cmd = new MakeModelCommand();
        return $cmd->tableToClassName($table);
    }
}
