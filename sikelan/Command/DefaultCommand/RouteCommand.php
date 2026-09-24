<?php

namespace Sikelan\Command\DefaultCommand;

use Sikelan\Command\CommandInterface;
use Sikelan\Command\RouteUpdaterTrait;

class RouteCommand implements CommandInterface
{
    use RouteUpdaterTrait;

    protected string $controllerDir;

    public function __construct()
    {
        $this->controllerDir = APP_PATH . '/Controllers';
    }

    public function commandName(): string
    {
        return 'route';
    }

    public function exec(array $args): ?string
    {
        if (empty($args)) {
            return "\033[31m错误: 请指定控制器名称。\033[0m\n" . $this->help([]);
        }

        // 过滤掉选项参数
        $force = in_array('--force', $args) || in_array('-f', $args);
        $filteredArgs = array_values(array_filter($args, function ($arg) {
            return $arg !== '-f' && $arg !== '--force';
        }));

        if (empty($filteredArgs)) {
            return "\033[31m错误: 请指定控制器名称。\033[0m\n" . $this->help([]);
        }

        $controllerName = $filteredArgs[0];
        $methodFilter = $filteredArgs[1] ?? null;

        if (strpos($controllerName, 'Controller') === false) {
            $controllerName .= 'Controller';
        }

        $filePath = $this->controllerDir . '/' . $controllerName . '.php';
        if (!file_exists($filePath)) {
            return "\033[31m错误: 控制器 '{$controllerName}' 不存在。\033[0m\n路径: {$filePath}";
        }

        if (!$this->routerFileExists()) {
            return "\033[31m错误: 路由配置文件不存在。\033[0m\n路径: {$this->getRouterFile()}";
        }

        $methods = $this->getControllerMethods($filePath, $methodFilter);
        if (empty($methods)) {
            return "\033[33m控制器 '{$controllerName}' 中未找到可生成路由的方法。\033[0m";
        }

        $resourceName = $this->deriveResourceName($controllerName);
        $className = $this->buildControllerClassName($controllerName);

        $newRoutes = $this->buildRouteEntries($className, $resourceName, $methods);
        $oldContent = $this->readRouterContent();

        // 提取该控制器已有的路由
        $oldRoutes = $this->extractControllerRoutes($oldContent, $className);

        // 以 method+path 为 key 检测与其他 handler 的路径冲突
        $conflicts = $this->findRouteConflicts($oldContent, $newRoutes);

        if (!$force) {
            return $this->showDiff($controllerName, $oldRoutes, $newRoutes, $conflicts);
        }

        // 执行更新：用新路由替换旧路由并写盘
        $newContent = $this->replaceControllerRoutes($oldContent, $className, $newRoutes);
        $this->writeRouterContent($newContent);

        $addedCount = count($newRoutes);
        $removedCount = count($oldRoutes);
        return "\033[32m路由更新成功！\033[0m\n" .
            "  控制器: {$controllerName}\n" .
            "  移除旧路由: {$removedCount} 条\n" .
            "  生成新路由: {$addedCount} 条\n" .
            "  配置文件: {$this->getRouterFile()}";
    }

    public function help(array $args): ?string
    {
        return <<<HELP
路由管理命令

用法:
  php sikelan route <控制器名> [方法名] [选项]

参数:
  控制器名    控制器名称 (如 User, Product, Order)
  方法名      指定更新某个方法的路由 (可选)

选项:
  -f, --force  强制执行更新，不显示变更预览

示例:
  php sikelan route User              更新 UserController 所有路由 (预览模式)
  php sikelan route User -f           强制更新 UserController 所有路由
  php sikelan route User index -f     仅更新 UserController 的 index 方法路由
  php sikelan route Product -f        强制更新 ProductController 所有路由
HELP;
    }

    public function desc(): string
    {
        return '更新路由配置';
    }

    /**
     * 通过反射获取控制器的公共方法
     */
    protected function getControllerMethods(string $filePath, ?string $methodFilter = null): array
    {
        $namespace = 'App\\Controllers';
        $className = $namespace . '\\' . basename($filePath, '.php');

        if (!class_exists($className)) {
            return [];
        }

        $reflection = new \ReflectionClass($className);
        $methods = [];
        // 收集当前类 + 所有祖先类名（子类继承父类的 CRUD 方法也能生成路由）
        $ancestorNames = [$className => true];
        $parent = $reflection->getParentClass();
        while ($parent) {
            $ancestorNames[$parent->getName()] = true;
            $parent = $parent->getParentClass();
        }

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            // 只保留当前类及其祖先类（含 Sikelan 命名空间）声明的方法，
            // 排除来自 PHP 内部类（如 abstract Controller）的方法
            $declaringName = $method->getDeclaringClass()->getName();
            if (!isset($ancestorNames[$declaringName]) && strpos($declaringName, 'Sikelan\\') !== 0) {
                continue;
            }

            $methodName = $method->getName();

            // 排除魔术方法和构造函数
            if (strpos($methodName, '__') === 0) {
                continue;
            }

            // 如果指定了方法过滤，只保留匹配的
            if ($methodFilter !== null && $methodName !== $methodFilter) {
                continue;
            }

            $methods[] = $methodName;
        }

        return $methods;
    }

    /**
     * 显示变更预览
     */
    protected function showDiff(string $controllerName, array $oldRoutes, array $newRoutes, array $conflicts = []): string
    {
        $output = "\033[36m=== 路由变更预览 ===\033[0m\n\n";
        $output .= "控制器: \033[33m{$controllerName}\033[0m\n\n";

        if (!empty($oldRoutes)) {
            $output .= "\033[31m将移除的旧路由:\033[0m\n";
            foreach ($oldRoutes as $route) {
                $method = $route['method'] ?? '';
                $path = $route['path'] ?? '';
                $output .= "  \033[31m- {$method} {$path}\033[0m\n";
            }
            $output .= "\n";
        }

        $output .= "\033[32m将生成的新路由:\033[0m\n";
        foreach ($newRoutes as $route) {
            $output .= "  \033[32m+ {$route['method']} {$route['path']} → {$route['handler']}\033[0m\n";
        }

        // 冲突警告：这些 method+path 已被其他 handler 占用，-f 覆盖时会一并移除
        if (!empty($conflicts)) {
            $output .= "\n\033[31m⚠ 路径冲突（以下 method + path 已被其他 handler 占用，-f 覆盖时将移除）:\033[0m\n";
            foreach ($conflicts as $c) {
                $output .= "  \033[31m{$c['key']}  现有: {$c['existing']['handler']} → 覆盖为: {$c['new']['handler']}\033[0m\n";
            }
        }

        $output .= "\n\033[33m使用 -f 或 --force 选项确认更新。\033[0m";
        return $output;
    }
}