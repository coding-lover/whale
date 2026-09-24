<?php

namespace Sikelan\Tests\Stest;

use PHPUnit\Framework\TestCase;
use Sikelan\Command\DefaultCommand\MakeControllerCommand;

/**
 * MakeControllerCommand 回归测试
 *
 * 重点覆盖 updateRouter() 的历史 bug：
 *   原实现用 str_replace('return [', ...) 会替换文件中所有 return [，
 *   导致路由被错误插入到闭包内部的 return 数组里，破坏路由配置。
 * 本测试验证修复后：路由只在顶层 return [ 后插入一次，闭包不受影响。
 */
class MakeControllerCommandTest extends TestCase
{
    private string $routerBackup;
    private string $routerFile;

    protected function setUp(): void
    {
        $this->routerFile = CONFIG_PATH . '/router.php';
        $this->routerBackup = file_get_contents($this->routerFile);
    }

    protected function tearDown(): void
    {
        // 恢复原始 router.php
        file_put_contents($this->routerFile, $this->routerBackup);
    }

    public function testUpdateRouterInsertsRoutesOnlyOnceAtTopLevel()
    {
        // 准备一个含闭包的路由文件（闭包内有 return [，用于复现 str_replace bug）
        $routerContent = <<<'PHP'
<?php

use Sikelan\Http\Response;

return [
    [
        'method' => 'GET',
        'path' => '/api/health',
        'handler' => function () {
            return [
                'status' => 'healthy',
            ];
        },
    ],
    [
        'method' => 'GET',
        'path' => '/api/test/{id}',
        'handler' => function ($request, $params) {
            return [
                'id' => $params['id'],
            ];
        },
    ],
];
PHP;
        file_put_contents($this->routerFile, $routerContent);

        $cmd = new MakeControllerCommand();

        // 通过反射调用真实的 protected updateRouter
        $method = new \ReflectionMethod($cmd, 'updateRouter');
        $method->setAccessible(true);
        $method->invoke($cmd, 'ArticleController');

        $result = file_get_contents($this->routerFile);

        // ArticleController 应恰好出现 5 次（5 条 CRUD 路由，只插入一处）
        $this->assertSame(5, substr_count($result, 'ArticleController'));

        // health 闭包的 return 数组应保持原样，未被插入路由
        $this->assertStringContainsString("return [\n                'status' => 'healthy',\n            ];", $result);

        // 路由文件必须语法正确
        exec('php -l ' . escapeshellarg($this->routerFile) . ' 2>&1', $lintOut, $lintCode);
        $this->assertEquals(0, $lintCode, 'router.php 语法错误: ' . implode("\n", $lintOut));

        // 闭包执行结果应保持原样
        $routes = require $this->routerFile;
        $healthRoute = null;
        foreach ($routes as $r) {
            if ($r['path'] === '/api/health') {
                $healthRoute = $r;
                break;
            }
        }
        $this->assertNotNull($healthRoute, 'health 路由应存在');
        $this->assertEquals(['status' => 'healthy'], $healthRoute['handler']());
    }

    public function testGeneratedControllerTemplateHasNoParamsArgument()
    {
        $cmd = new MakeControllerCommand();

        $method = new \ReflectionMethod($cmd, 'generateTemplate');
        $method->setAccessible(true);
        $code = $method->invoke($cmd, 'App\\Controllers', 'ArticleController');

        // 生成的控制器模板不应再出现 $params 参数
        $this->assertStringNotContainsString('$params', $code);
        // show/update/destroy 应使用 $request->getInt('id')
        $this->assertStringContainsString("\$request->getInt('id')", $code);
    }

    /**
     * 控制器删除重建场景：旧路由以小写类名 test1Controller 写入（PHP 类名大小写不敏感），
     * 重建 Test1Controller 后应整体替换，不残留旧路由（历史重复路由 bug 回归）。
     */
    public function testUpdateRouterReplacesStaleCaseVariantRoutes()
    {
        $routerContent = <<<'PHP'
<?php

return [
    [
        'method' => 'GET',
        'path' => '/api/test1s',
        'handler' => 'App\Controllers\test1Controller@index',
    ],
    [
        'method' => 'GET',
        'path' => '/api/users',
        'handler' => 'App\Controllers\UserController@index',
    ],
];
PHP;
        file_put_contents($this->routerFile, $routerContent);

        $cmd = new MakeControllerCommand();
        $method = new \ReflectionMethod($cmd, 'updateRouter');
        $method->setAccessible(true);
        // 大小写不同的同一控制器，无 method+path 冲突 → 静默替换
        $method->invoke($cmd, 'Test1Controller');

        $result = file_get_contents($this->routerFile);

        // 旧的小写/复数路径路由应被替换，不残留
        $this->assertStringNotContainsString('test1s', $result);
        // 新路由恰好 5 条，且以数字结尾的资源名不加 s（/api/test1）
        $this->assertSame(5, substr_count($result, 'Test1Controller'));
        $this->assertStringContainsString("'path' => '/api/test1',", $result);
        // 其他控制器的路由不受影响
        $this->assertStringContainsString('UserController@index', $result);

        exec('php -l ' . escapeshellarg($this->routerFile) . ' 2>&1', $lintOut, $lintCode);
        $this->assertEquals(0, $lintCode, 'router.php 语法错误: ' . implode("\n", $lintOut));
    }

    /**
     * method+path 去重 key 检测：同 key 但 handler 不同 → 识别为冲突。
     */
    public function testFindRouteConflictsDetectsForeignHandlerOnSamePath()
    {
        $content = <<<'PHP'
<?php

return [
    [
        'method' => 'GET',
        'path' => '/api/articles',
        'handler' => 'App\Controllers\PostController@index',
    ],
    [
        'method' => 'GET',
        'path' => '/api/users',
        'handler' => 'App\Controllers\UserController@index',
    ],
];
PHP;
        $cmd = new MakeControllerCommand();
        $method = new \ReflectionMethod($cmd, 'findRouteConflicts');
        $method->setAccessible(true);

        $newRoutes = [
            ['method' => 'GET', 'path' => '/api/articles', 'handler' => 'App\\Controllers\\ArticleController@index'],
            ['method' => 'GET', 'path' => '/api/users', 'handler' => 'App\\Controllers\\UserController@index'],
        ];
        $conflicts = $method->invoke($cmd, $content, $newRoutes);

        // 仅 /api/articles 冲突（PostController 占用）；/api/users 是自身旧路由，不算冲突
        $this->assertCount(1, $conflicts);
        $this->assertSame('GET /api/articles', $conflicts[0]['key']);
        $this->assertSame('App\Controllers\PostController@index', $conflicts[0]['existing']['handler']);
    }

    /**
     * -y（autoYes）：检测到冲突时跳过交互确认，直接覆盖移除冲突条目。
     */
    public function testUpdateRouterWithAutoYesOverwritesForeignConflicts()
    {
        $routerContent = <<<'PHP'
<?php

return [
    [
        'method' => 'GET',
        'path' => '/api/articles',
        'handler' => 'App\Controllers\PostController@index',
    ],
];
PHP;
        file_put_contents($this->routerFile, $routerContent);

        $cmd = new MakeControllerCommand();
        $method = new \ReflectionMethod($cmd, 'updateRouter');
        $method->setAccessible(true);
        $method->invoke($cmd, 'ArticleController', true);

        $result = file_get_contents($this->routerFile);

        // 冲突的 PostController 条目应被覆盖移除，不再重复注册 GET /api/articles
        $this->assertStringNotContainsString('PostController', $result);
        $this->assertSame(5, substr_count($result, 'ArticleController'));

        // 核心：所有路由的 method+path key 必须唯一（index/store 共用 /api/articles 属正常）
        $routes = require $this->routerFile;
        $keys = [];
        foreach ($routes as $r) {
            $keys[] = strtoupper($r['method']) . ' ' . $r['path'];
        }
        $this->assertSame(count($keys), count(array_unique($keys)), '存在重复注册的路由: ' . implode(', ', $keys));

        exec('php -l ' . escapeshellarg($this->routerFile) . ' 2>&1', $lintOut, $lintCode);
        $this->assertEquals(0, $lintCode, 'router.php 语法错误: ' . implode("\n", $lintOut));
    }
}
