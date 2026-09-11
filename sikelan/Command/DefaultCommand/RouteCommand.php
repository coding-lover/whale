<?php

namespace Sikelan\Command\DefaultCommand;

use Sikelan\Command\CommandInterface;

class RouteCommand implements CommandInterface
{
    protected string $routerFile;
    protected string $controllerDir;

    public function __construct()
    {
        $this->routerFile = CONFIG_PATH . '/router.php';
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

        if (!file_exists($this->routerFile)) {
            return "\033[31m错误: 路由配置文件不存在。\033[0m\n路径: {$this->routerFile}";
        }

        $methods = $this->getControllerMethods($filePath, $methodFilter);
        if (empty($methods)) {
            return "\033[33m控制器 '{$controllerName}' 中未找到可生成路由的方法。\033[0m";
        }

        $resourceName = $this->deriveResourceName($controllerName);
        $className = "App\\Controllers\\{$controllerName}";

        $newRoutes = $this->buildRouteEntries($className, $resourceName, $methods);
        $oldContent = file_get_contents($this->routerFile);

        // 提取该控制器已有的路由
        $oldRoutes = $this->extractControllerRoutes($oldContent, $className);

        if (!$force) {
            return $this->showDiff($controllerName, $oldRoutes, $newRoutes);
        }

        // 执行更新
        $newContent = $this->applyRouteUpdate($oldContent, $className, $newRoutes);
        file_put_contents($this->routerFile, $newContent);

        $addedCount = count($newRoutes);
        $removedCount = count($oldRoutes);
        return "\033[32m路由更新成功！\033[0m\n" .
            "  控制器: {$controllerName}\n" .
            "  移除旧路由: {$removedCount} 条\n" .
            "  生成新路由: {$addedCount} 条\n" .
            "  配置文件: {$this->routerFile}";
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

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $className) {
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
     * 从控制器名派生资源名 (UserController → users)
     */
    protected function deriveResourceName(string $controllerName): string
    {
        $baseName = str_replace('Controller', '', $controllerName);
        $lowerName = strtolower($baseName);

        // 简单复数规则
        $endings = ['s', 'x', 'z', 'ch', 'sh'];
        foreach ($endings as $ending) {
            if (substr($lowerName, -strlen($ending)) === $ending) {
                return $lowerName . 'es';
            }
        }
        if (substr($lowerName, -1) === 'y') {
            return substr($lowerName, 0, -1) . 'ies';
        }
        return $lowerName . 's';
    }

    /**
     * 根据控制器方法生成路由配置条目
     */
    protected function buildRouteEntries(string $className, string $resourceName, array $methods): array
    {
        $entries = [];

        foreach ($methods as $method) {
            $route = $this->mapMethodToRoute($method, $resourceName);
            $route['handler'] = "{$className}@{$method}";
            $entries[] = $route;
        }

        return $entries;
    }

    /**
     * 将方法名映射为 RESTful 路由
     */
    protected function mapMethodToRoute(string $method, string $resourceName): array
    {
        $basePath = "/api/{$resourceName}";

        // 标准 CRUD 映射
        $map = [
            'index'   => ['method' => 'GET',    'path' => $basePath],
            'show'    => ['method' => 'GET',    'path' => $basePath . '/{id}'],
            'store'   => ['method' => 'POST',   'path' => $basePath],
            'update'  => ['method' => 'PUT',    'path' => $basePath . '/{id}'],
            'destroy' => ['method' => 'DELETE', 'path' => $basePath . '/{id}'],
            'create'  => ['method' => 'GET',    'path' => $basePath . '/create'],
            'edit'    => ['method' => 'GET',    'path' => $basePath . '/{id}/edit'],
        ];

        if (isset($map[$method])) {
            return $map[$method];
        }

        // 自定义方法: GET /api/{resource}/{methodName}
        return ['method' => 'GET', 'path' => $basePath . '/' . $method];
    }

    /**
     * 从路由配置文件中提取指定控制器的路由
     */
    protected function extractControllerRoutes(string $content, string $className): array
    {
        $escapedClass = preg_quote($className, '/');
        $pattern = "/\s*\[\s*'method'\s*=>\s*'([^']+)'\s*,\s*'path'\s*=>\s*'([^']+)'\s*,\s*'handler'\s*=>\s*'{$escapedClass}@([^']+)'\s*\]/s";

        $routes = [];
        if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $routes[] = [
                    'method'  => $match[1],
                    'path'    => $match[2],
                    'handler' => $match[0], // 保存原始匹配文本
                ];
            }
        }

        return $routes;
    }

    /**
     * 显示变更预览
     */
    protected function showDiff(string $controllerName, array $oldRoutes, array $newRoutes): string
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

        $output .= "\n\033[33m使用 -f 或 --force 选项确认更新。\033[0m";
        return $output;
    }

    /**
     * 应用路由更新到配置文件
     */
    protected function applyRouteUpdate(string $content, string $className, array $newRoutes): string
    {
        $escapedClass = preg_quote($className, '/');

        // 移除旧路由块
        $pattern = "/\s*\[\s*'method'\s*=>\s*'[^']+'\s*,\s*'path'\s*=>\s*'[^']+'\s*,\s*'handler'\s*=>\s*'{$escapedClass}@[^']+'\s*\],?\s*/s";
        $content = preg_replace($pattern, '', $content);

        // 构造新路由块
        $newBlock = "\n";
        foreach ($newRoutes as $route) {
            $newBlock .= "    [\n";
            $newBlock .= "        'method' => '{$route['method']}',\n";
            $newBlock .= "        'path' => '{$route['path']}',\n";
            $newBlock .= "        'handler' => '{$route['handler']}',\n";
            $newBlock .= "    ],\n";
        }

        // 在 return [ 之后插入新路由
        $content = preg_replace(
            '/return \[/',
            "return [{$newBlock}",
            $content,
            1
        );

        return $content;
    }
}