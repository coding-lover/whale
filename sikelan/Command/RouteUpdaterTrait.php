<?php

namespace Sikelan\Command;

/**
 * 路由配置文件（config/router.php）读写共享能力（Trait）。
 *
 * RouteCommand、MakeControllerCommand、MakeCrudCommand 都需要操作路由配置，
 * 重复实现容易漂移（例如复数规则不一致、插入位置破坏闭包内 return 等）。
 * 本 Trait 集中以下能力：
 *   - 复数化（deriveResourceName / toPlural）
 *   - 方法名 → RESTful 路由映射（mapMethodToRoute）
 *   - 标准 CRUD 路由批量构建（buildStandardCrudRoutes）
 *   - 路由条目 PHP 代码格式化（formatRouteEntry / formatRouteEntries）
 *   - 路由条目解析与 method+path 去重检测（parseRouteEntries / findRouteConflicts）
 *   - 控制器已有路由的提取 / 移除（类名忽略大小写，兼容单双引号）
 *   - 在顶层 return [ 后安全插入路由块（不破坏闭包内的 return）
 *   - 同步 5 条标准 CRUD 路由（替换语义，冲突时交互确认）
 */
trait RouteUpdaterTrait
{
    /**
     * 路由配置文件绝对路径
     */
    protected function getRouterFile(): string
    {
        return CONFIG_PATH . '/router.php';
    }

    /**
     * 路由配置文件是否存在
     */
    protected function routerFileExists(): bool
    {
        return file_exists($this->getRouterFile());
    }

    /**
     * 读取路由配置文件内容
     */
    protected function readRouterContent(): string
    {
        return (string) file_get_contents($this->getRouterFile());
    }

    /**
     * 写入路由配置文件内容
     */
    protected function writeRouterContent(string $content): void
    {
        file_put_contents($this->getRouterFile(), $content);
    }

    /**
     * 由控制器短名构建完整类名（UserController → App\Controllers\UserController）。
     */
    protected function buildControllerClassName(string $controllerName): string
    {
        return "App\\Controllers\\{$controllerName}";
    }

    /**
     * 简单复数化（User → users, category → categories, box → boxes）。
     *
     * 以数字结尾的名称（如 test1）不做复数化，避免出现 test1s 这类不自然的路径。
     */
    protected function toPlural(string $word): string
    {
        // 以数字结尾 → 原样返回（test1 → test1，不加 s）
        if (preg_match('/\d$/', $word)) {
            return $word;
        }

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

    /**
     * 从控制器名派生资源名（UserController → users）。
     */
    protected function deriveResourceName(string $controllerName): string
    {
        $baseName = str_replace('Controller', '', $controllerName);
        return $this->toPlural(strtolower($baseName));
    }

    /**
     * 将方法名映射为 RESTful 路由条目（不含 handler，由调用方拼接）。
     *
     * 标准 CRUD 方法走预定义映射；自定义方法统一为 GET /api/{resource}/{method}。
     *
     * @return array{method:string, path:string}
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
     * 根据给定的方法列表批量构建路由条目（泛型版，方法列表由调用方决定）。
     *
     * @return array<int, array{method:string, path:string, handler:string}>
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
     * 构建标准 5 条 CRUD 路由条目（index/show/store/update/destroy）。
     *
     * @return array<int, array{method:string, path:string, handler:string}>
     */
    protected function buildStandardCrudRoutes(string $className, string $resourceName): array
    {
        return $this->buildRouteEntries($className, $resourceName, ['index', 'show', 'store', 'update', 'destroy']);
    }

    /**
     * 将单条路由条目格式化为 PHP 数组代码块（4 空格缩进）。
     */
    protected function formatRouteEntry(array $route): string
    {
        return "    [\n"
            . "        'method' => '{$route['method']}',\n"
            . "        'path' => '{$route['path']}',\n"
            . "        'handler' => '{$route['handler']}',\n"
            . "    ],\n";
    }

    /**
     * 将多条路由条目格式化为连续的 PHP 数组代码块。
     */
    protected function formatRouteEntries(array $routes): string
    {
        $block = '';
        foreach ($routes as $route) {
            $block .= $this->formatRouteEntry($route);
        }
        return $block;
    }

    /**
     * 路由去重 key：METHOD + path（HTTP 方法忽略大小写，路径精确匹配）。
     */
    protected function routeKey(string $method, string $path): string
    {
        return strtoupper($method) . ' ' . $path;
    }

    /**
     * 从路由配置内容中解析全部字符串 handler 的路由条目。
     *
     * 兼容单引号/双引号写法；闭包 handler 无法字符串化，不在解析范围内。
     *
     * @return array<int, array{method:string, path:string, handler:string}>
     */
    protected function parseRouteEntries(string $content): array
    {
        $pattern = '/\[\s*[\'"]method[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]\s*,\s*[\'"]path[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]\s*,\s*[\'"]handler[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]\s*,?\s*\]/s';

        $entries = [];
        if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $entries[] = [
                    'method'  => $m[1],
                    'path'    => $m[2],
                    'handler' => $m[3],
                ];
            }
        }
        return $entries;
    }

    /**
     * 以 method+path 为 key 检测新路由与现存路由的重复/冲突。
     *
     * 同 key 且 handler 不同 → 冲突（不覆盖会导致启动时
     * "Cannot register two routes matching" 异常）。
     * handler 比较忽略大小写（PHP 类名/方法名大小写不敏感，
     * 如 test1Controller@index 与 Test1Controller@index 是同一条路由）。
     *
     * @param array<int, array{method:string, path:string, handler:string}> $newRoutes
     * @return array<int, array{key:string, existing:array, new:array}>
     */
    protected function findRouteConflicts(string $content, array $newRoutes): array
    {
        // 先读取出来保存为数组，再以 method + path 组成 key 索引
        $byKey = [];
        foreach ($this->parseRouteEntries($content) as $entry) {
            $byKey[$this->routeKey($entry['method'], $entry['path'])][] = $entry;
        }

        $conflicts = [];
        foreach ($newRoutes as $new) {
            $key = $this->routeKey($new['method'], $new['path']);
            foreach ($byKey[$key] ?? [] as $existing) {
                // handler 相同（忽略大小写）→ 该控制器自身旧路由，替换流程会清理
                if (strcasecmp($existing['handler'], $new['handler']) === 0) {
                    continue;
                }
                $conflicts[] = ['key' => $key, 'existing' => $existing, 'new' => $new];
            }
        }
        return $conflicts;
    }

    /**
     * 交互确认是否覆盖冲突路由。
     *
     * 注意：此处直接 echo/fgets 是 CLI 交互所必需（命令返回值机制无法承载交互输入）。
     * 非交互环境（管道/CI/测试）自动取消，调用方可用 -y/--yes 跳过确认。
     */
    protected function confirmRouteOverwrite(array $conflicts): bool
    {
        $count = count($conflicts);
        echo "\n\033[33m⚠ 检测到 {$count} 条重复/冲突路由（method + path 相同，handler 不同）：\033[0m\n";
        foreach ($conflicts as $c) {
            echo "  \033[31m{$c['key']}\033[0m  现有: {$c['existing']['handler']}"
                . "  将覆盖为: \033[32m{$c['new']['handler']}\033[0m\n";
        }

        // 非交互环境不阻塞，自动取消
        if (!defined('STDIN') || !is_resource(STDIN) || !stream_isatty(STDIN)) {
            echo "\033[33m非交互环境，已自动取消。可使用 -y/--yes 跳过确认强制覆盖。\033[0m\n";
            return false;
        }

        echo "\033[33m是否覆盖以上路由？(y/N): \033[0m";
        $line = fgets(STDIN);
        if ($line === false) {
            return false;
        }
        $answer = strtolower(trim($line));
        return $answer === 'y' || $answer === 'yes';
    }

    /**
     * 从路由配置中提取指定控制器的所有路由（用于变更预览）。
     *
     * 类名匹配忽略大小写（PHP 类名大小写不敏感）；
     * 兼容单引号/双引号写法。
     *
     * @return array<int, array{method:string, path:string, handler:string}>
     */
    protected function extractControllerRoutes(string $content, string $className): array
    {
        $escapedClass = preg_quote($className, '/');
        // 注意：
        //   1. handler 值后有可选逗号（'handler' => '...',），必须用 ,? 兼容
        //   2. 开头用 [ \t]* 而非 \s*，避免吞掉 return [ 后的换行符
        //   3. i 标志使类名匹配忽略大小写（捕获 test1Controller 这类历史变体）
        $pattern = '/[ \t]*\[\s*[\'"]method[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]\s*,\s*[\'"]path[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]\s*,\s*[\'"]handler[\'"]\s*=>\s*[\'"]' . $escapedClass . '@([^\'"]+)[\'"]\s*,?\s*\]/si';

        $routes = [];
        if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $routes[] = [
                    'method'  => $match[1],
                    'path'    => $match[2],
                    'handler' => "{$className}@{$match[3]}",
                ];
            }
        }
        return $routes;
    }

    /**
     * 移除路由配置中指定控制器的所有路由条目（含尾随逗号与空白）。
     *
     * 类名匹配忽略大小写（PHP 类名大小写不敏感，可清理 test1Controller 这类历史变体）。
     */
    protected function removeControllerRoutes(string $content, string $className): string
    {
        $escapedClass = preg_quote($className, '/');
        // handler 值后有可选逗号（'handler' => '...',），必须用 ,? 兼容；
        // 开头用 [ \t]* 而非 \s*，避免吞掉 return [ 后的换行符；
        // 尾部用 \h*\R? 仅消费可选水平空白和一个换行，保留下一条路由的缩进；
        // i 标志使类名匹配忽略大小写
        $pattern = '/[ \t]*\[\s*[\'"]method[\'"]\s*=>\s*[\'"][^\'"]+[\'"]\s*,\s*[\'"]path[\'"]\s*=>\s*[\'"][^\'"]+[\'"]\s*,\s*[\'"]handler[\'"]\s*=>\s*[\'"]' . $escapedClass . '@[^\'"]+[\'"]\s*,?\s*\],?\h*\R?/si';
        return (string) preg_replace($pattern, '', $content);
    }

    /**
     * 按去重 key（method+path）移除路由条目，无论 handler 是谁。
     *
     * 用于覆盖语义：确认覆盖后，同 key 的其他 handler 条目也必须移除，
     * 否则依然会重复注册路由。
     *
     * @param array<int, string> $keys routeKey 列表
     */
    protected function removeRoutesByKeys(string $content, array $keys): string
    {
        if (empty($keys)) {
            return $content;
        }

        $keySet = array_flip($keys);
        $pattern = '/[ \t]*\[\s*[\'"]method[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]\s*,\s*[\'"]path[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]\s*,\s*[\'"]handler[\'"]\s*=>\s*[\'"][^\'"]+[\'"]\s*,?\s*\],?\h*\R?/si';

        return (string) preg_replace_callback($pattern, function (array $m) use ($keySet) {
            // 命中去重 key 的条目删除，其余原样保留
            $key = $this->routeKey($m[1], $m[2]);
            return isset($keySet[$key]) ? '' : $m[0];
        }, $content);
    }

    /**
     * 在顶层第一个 `return [` 之后插入路由代码块。
     *
     * 关键：只能匹配顶层的 return [，不能用 str_replace 全局替换，
     * 否则闭包内的 return [ 也会被插入路由，破坏配置。
     */
    protected function insertRoutesAfterReturn(string $content, string $routesBlock): string
    {
        $pos = strpos($content, 'return [');
        if ($pos === false) {
            return $content;
        }

        // 定位到 `return [` 之后的换行符位置，从下一行开始插入
        $insertAfter = strpos($content, "\n", $pos);
        if ($insertAfter === false) {
            $insertAfter = strlen($content);
        } else {
            $insertAfter++; // 跳过换行符
        }

        return substr($content, 0, $insertAfter)
            . $routesBlock
            . substr($content, $insertAfter);
    }

    /**
     * 为指定控制器同步 5 条标准 CRUD 路由（完整流程，替换语义）。
     *
     * 流程：读路由文件 → 以 method+path 为 key 检测重复/冲突 →
     *       有冲突时交互确认（或 -y 跳过确认）→ 移除旧路由 → 写入新路由 → 写盘。
     *
     * 采用替换而非"存在则跳过"的原因：
     *   - 避免控制器删除重建后残留旧路由、新旧并存导致重复
     *   - 保证路由始终与当前控制器模板一致
     *
     * @param string $controllerName 控制器名（如 UserController）
     * @param bool   $autoYes        true=跳过交互确认直接覆盖冲突
     * @return string|null null=已同步；string=提示信息（如已取消覆盖）
     */
    protected function syncStandardCrudRoutes(string $controllerName, bool $autoYes = false): ?string
    {
        if (!$this->routerFileExists()) {
            return null;
        }

        $resourceName = $this->deriveResourceName($controllerName);
        $className = $this->buildControllerClassName($controllerName);

        $content = $this->readRouterContent();
        $newRoutes = $this->buildStandardCrudRoutes($className, $resourceName);

        // 以 method+path 为 key 检测重复/冲突路由：有则提示用户确认后覆盖
        $conflicts = $this->findRouteConflicts($content, $newRoutes);
        if (!empty($conflicts) && !$autoYes && !$this->confirmRouteOverwrite($conflicts)) {
            return '已取消覆盖，路由配置未修改。';
        }

        $newContent = $this->replaceControllerRoutes($content, $className, $newRoutes);
        $this->writeRouterContent($newContent);

        return null;
    }

    /**
     * 用新路由替换指定控制器在路由配置中的全部旧路由。
     *
     * 封装：移除该控制器旧路由（忽略类名大小写）→ 按去重 key 移除
     * 其他 handler 占用的同名路由（覆盖语义）→ 插入新路由块。
     * （不负责读盘/写盘，由调用方控制，因为 RouteCommand 需要先读内容做 diff 预览。）
     */
    protected function replaceControllerRoutes(string $content, string $className, array $newRoutes): string
    {
        // 1. 移除该控制器的全部旧路由（含大小写变体、已改名的旧路径）
        $content = $this->removeControllerRoutes($content, $className);

        // 2. 按去重 key（method+path）移除其他 handler 占用的同名条目
        $keys = [];
        foreach ($newRoutes as $r) {
            $keys[] = $this->routeKey($r['method'], $r['path']);
        }
        $content = $this->removeRoutesByKeys($content, $keys);

        // 3. 插入新路由块
        return $this->insertRoutesAfterReturn($content, $this->formatRouteEntries($newRoutes));
    }
}
