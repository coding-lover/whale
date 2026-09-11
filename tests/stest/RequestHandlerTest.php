<?php

namespace Sikelan\Tests\Stest;

use PHPUnit\Framework\TestCase;
use Sikelan\Core\Container;
use Sikelan\Core\Logger;
use Sikelan\Http\Router;
use Sikelan\Http\Request;
use Sikelan\Http\RequestHandler;

/**
 * RequestHandler 方法注入测试
 *
 * 验证控制器方法参数按类型提示从容器自动解析，
 * 路由参数通过参数名匹配注入（如 /users/{id} → $id）。
 */
class RequestHandlerTest extends TestCase
{
    private Container $container;
    private RequestHandler $handler;

    protected function setUp(): void
    {
        $this->container = new Container();
        $this->container->set(Logger::class, new Logger(new \Sikelan\Core\Config()));

        $router = $this->container->get(Router::class);

        $this->handler = new RequestHandler(
            $this->container,
            $this->container->get(Logger::class),
            $router
        );
    }

    /**
     * 通过反射调用受保护的 resolveMethodArgs
     */
    private function resolveArgs(object $controller, string $method, Request $request, array $params): array
    {
        $ref = new \ReflectionMethod($this->handler, 'resolveMethodArgs');
        $ref->setAccessible(true);
        return $ref->invoke($this->handler, $controller, $method, $request, $params);
    }

    // ========== 方法注入：类型提示从容器解析 ==========

    public function testMethodInjectsServiceByTypeHint()
    {
        $service = new MethodInjectDummyService();
        $this->container->set(MethodInjectDummyService::class, $service);

        $controller = new MethodInjectTestController();
        $request = new Request('GET', '/test');

        $args = $this->resolveArgs($controller, 'withService', $request, []);

        $this->assertCount(2, $args);
        $this->assertSame($request, $args[0]);
        $this->assertSame($service, $args[1]);
    }

    public function testMethodInjectsMultipleServices()
    {
        $svcA = new MethodInjectDummyService();
        $svcB = new MethodInjectAnotherService();
        $this->container->set(MethodInjectDummyService::class, $svcA);
        $this->container->set(MethodInjectAnotherService::class, $svcB);

        $controller = new MethodInjectTestController();
        $request = new Request('GET', '/test');

        $args = $this->resolveArgs($controller, 'withTwoServices', $request, []);

        $this->assertSame($request, $args[0]);
        $this->assertSame($svcA, $args[1]);
        $this->assertSame($svcB, $args[2]);
    }

    // ========== Request 注入 ==========

    public function testInjectsFrameworkRequest()
    {
        $controller = new MethodInjectTestController();
        $request = new Request('POST', '/submit');

        $args = $this->resolveArgs($controller, 'onlyRequest', $request, []);

        $this->assertCount(1, $args);
        $this->assertSame($request, $args[0]);
    }

    public function testInjectsRequestViaPsrInterface()
    {
        $controller = new MethodInjectTestController();
        $request = new Request('GET', '/psr');

        $args = $this->resolveArgs($controller, 'withPsrRequest', $request, []);

        $this->assertCount(1, $args);
        $this->assertSame($request, $args[0]);
    }

    // ========== 路由参数按名注入 ==========

    public function testInjectsRouteParamByName()
    {
        $controller = new MethodInjectTestController();
        $request = new Request('GET', '/users/42');

        $args = $this->resolveArgs($controller, 'withId', $request, ['id' => 42]);

        $this->assertSame($request, $args[0]);
        $this->assertSame(42, $args[1]);
    }

    public function testRouteParamOverridesDefaultValue()
    {
        $controller = new MethodInjectTestController();
        $request = new Request('GET', '/page/5');

        $args = $this->resolveArgs($controller, 'withDefaultPage', $request, ['page' => 5]);

        $this->assertSame(5, $args[1]);
    }

    public function testUsesDefaultValueWhenRouteParamAbsent()
    {
        $controller = new MethodInjectTestController();
        $request = new Request('GET', '/page');

        $args = $this->resolveArgs($controller, 'withDefaultPage', $request, []);

        $this->assertSame(1, $args[1]);
    }

    // ========== 兜底：无类型无默认无匹配 ==========

    public function testFallbackToNullForUnresolvableParam()
    {
        $controller = new MethodInjectTestController();
        $request = new Request('GET', '/x');

        $args = $this->resolveArgs($controller, 'untypedNoDefault', $request, []);

        $this->assertSame($request, $args[0]);
        $this->assertNull($args[1]);
    }
}

/**
 * 测试用服务类
 */
class MethodInjectDummyService
{
}

class MethodInjectAnotherService
{
}

/**
 * 测试用控制器
 */
class MethodInjectTestController
{
    public function onlyRequest(Request $request)
    {
    }

    public function withPsrRequest(\Psr\Http\Message\RequestInterface $request)
    {
    }

    public function withService(Request $request, MethodInjectDummyService $service)
    {
    }

    public function withTwoServices(Request $request, MethodInjectDummyService $a, MethodInjectAnotherService $b)
    {
    }

    public function withId(Request $request, int $id)
    {
    }

    public function withDefaultPage(Request $request, int $page = 1)
    {
    }

    public function untypedNoDefault(Request $request, $something)
    {
    }
}
