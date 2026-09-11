<?php

namespace Sikelan\Http;

use Sikelan\Core\Container;
use Sikelan\Core\Logger;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;

/**
 * HTTP 请求处理器
 *
 * 负责处理 HTTP 请求的解析、路由分发和响应返回，
 * 与框架主类解耦，专注于请求处理逻辑
 */
class RequestHandler
{
    protected Container $container;

    protected Logger $logger;

    protected Router $router;

    public function __construct(Container $container, Logger $logger, Router $router)
    {
        $this->container = $container;
        $this->logger = $logger;
        $this->router = $router;
    }

    /**
     * 处理 HTTP 请求
     *
     * @param SwooleRequest $request Swoole 请求对象
     * @param SwooleResponse $response Swoole 响应对象
     */
    public function handle(SwooleRequest $request, SwooleResponse $response): void
    {
        try {
            // 将 Swoole 请求转换为框架请求对象
            $frameworkRequest = Request::createFromSwoole($request);

            // 路由匹配
            $route = $this->router->dispatch($frameworkRequest);

            if ($route === null) {
                $this->sendNotFound($response);
                return;
            }

            // 执行路由处理器
            $result = $this->executeHandler($route, $frameworkRequest);

            // 发送响应
            $this->sendResponse($response, $result);
        } catch (\Throwable $e) {
            $this->handleException($response, $e);
        }
    }

    /**
     * 执行路由处理器
     *
     * @param array $route 路由信息
     * @param Request $request 请求对象
     * @return mixed
     */
    protected function executeHandler(array $route, Request $request)
    {
        $handler = $route['handler'];
        $params = $route['params'] ?? [];

        // 把路由参数注入 Request，便于统一通过 input()/getInt() 等便利方法访问
        // 这样控制器既能继续用 $params（兼容），也能用 $request->input('id') 等新方式
        if (method_exists($request, 'setRouteParams')) {
            $request->setRouteParams($params);
        }

        // 闭包回调
        if ($handler instanceof \Closure) {
            return $handler($request, $params);
        }

        // 控制器方法
        if (is_string($handler)) {
            list($controllerClass, $method) = explode('@', $handler);

            // 通过容器获取控制器实例（构造函数注入在此生效）
            $controller = $this->container->get($controllerClass);

            // 方法注入：反射解析方法参数，按类型提示从容器解析
            $args = $this->resolveMethodArgs($controller, $method, $request, $params);

            return $controller->$method(...$args);
        }

        return null;
    }

    /**
     * 解析控制器方法参数（支持方法注入）
     *
     * 解析优先级：
     *  1. 类型为 Request / RequestInterface → 注入框架 Request 对象
     *  2. 类型为类/接口（非内置）→ 从容器自动解析
     *  3. 参数名匹配路由参数 → 注入对应的路由参数值（如 /users/{id} → $id）
     *  4. 有默认值 → 使用默认值
     *  5. 兜底 null
     *
     * 注意：路由参数统一通过 $request->getRouteParams() / $request->input($key) 访问，
     * 不再支持旧签名 `$params` 参数名注入整个路由参数数组。
     *
     * @param object  $controller 控制器实例
     * @param string  $method     方法名
     * @param Request $request    框架请求对象
     * @param array   $params     路由参数
     * @return array  按参数顺序排列的实参数组
     */
    protected function resolveMethodArgs(object $controller, string $method, Request $request, array $params): array
    {
        $reflection = new \ReflectionMethod($controller, $method);
        $args = [];

        foreach ($reflection->getParameters() as $param) {
            $type = $param->getType();
            $name = $param->getName();

            // 1 & 2：非内置类型（类/接口）
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $typeName = $type->getName();

                // Request 相关类型 → 注入框架 Request
                if ($typeName === Request::class || $typeName === \Psr\Http\Message\RequestInterface::class) {
                    $args[] = $request;
                    continue;
                }

                // 其他类/接口 → 从容器解析（容器自动装配）
                $args[] = $this->container->get($typeName);
                continue;
            }

            // 3：参数名匹配路由参数
            if (array_key_exists($name, $params)) {
                $args[] = $params[$name];
                continue;
            }

            // 4：默认值
            if ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
                continue;
            }

            // 5：兜底 null
            $args[] = null;
        }

        return $args;
    }

    /**
     * 发送响应
     *
     * 安全处理：
     * - 数组响应自动包装成带安全响应头的 JSON Response（DEFAULT_JSON_FLAGS 防 XSS）
     * - Response 对象若未显式设置安全头，自动合并默认头
     * - 字符串响应也注入安全头（避免任何响应绕过安全头）
     *
     * @param SwooleResponse $response Swoole 响应对象
     * @param mixed $data 响应数据（数组、Response 对象或字符串）
     */
    protected function sendResponse(SwooleResponse $response, $data): void
    {
        // Response 对象：通过 send() 方法写入 Swoole 响应
        if ($data instanceof Response) {
            // 若未显式设置安全响应头，自动合并默认头（开发者显式设的头不会被覆盖）
            if (!$data->hasHeader('X-Content-Type-Options')) {
                $data = $data->withSecurityHeaders();
            }
            $data->send($response);
            return;
        }

        // 数组或普通对象：JSON 编码（自动包成带安全头的 Response）
        if (is_array($data)) {
            $resp = (new Response())->withJson($data)->withSecurityHeaders();
            $resp->send($response);
            return;
        }

        // 其他类型：转为字符串（也注入安全头）
        // 直接调 Swoole 接口写入（避免 withBody 强制 StreamInterface 类型）
        $securityHeaders = \Sikelan\Security\SecurityHeaders::defaults();
        foreach ($securityHeaders as $name => $value) {
            $response->header($name, (string) $value);
        }
        $response->header('Content-Type', 'text/html; charset=utf-8');
        $response->end((string)$data);
    }

    /**
     * 发送 404 响应
     */
    protected function sendNotFound(SwooleResponse $response): void
    {
        $resp = (new Response(404))->withJson([
            'code' => 404,
            'message' => 'Not Found',
            'data' => null,
        ])->withSecurityHeaders();
        $resp->send($response);
    }

    /**
     * 处理异常
     */
    protected function handleException(SwooleResponse $response, \Throwable $e): void
    {
        $this->logger->error("Request handler error: {$e->getMessage()}", [
            'trace' => $e->getTraceAsString()
        ]);

        $resp = (new Response(500))->withJson([
            'code' => 500,
            'message' => 'Internal Server Error',
            'data' => null,
        ])->withSecurityHeaders();
        $resp->send($response);
    }
}
