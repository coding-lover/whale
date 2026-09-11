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
}
