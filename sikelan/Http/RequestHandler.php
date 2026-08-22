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

        // 闭包回调
        if ($handler instanceof \Closure) {
            return $handler($request, $route['params'] ?? []);
        }

        // 控制器方法
        if (is_string($handler)) {
            list($controllerClass, $method) = explode('@', $handler);

            // 通过容器获取控制器实例
            $controller = $this->container->get($controllerClass);

            // 获取路由参数（以数组形式传递给控制器方法）
            $params = $route['params'] ?? [];

            return $controller->$method($request, $params);
        }

        return null;
    }

    /**
     * 发送响应
     *
     * @param SwooleResponse $response Swoole 响应对象
     * @param mixed $data 响应数据（数组、Response 对象或字符串）
     */
    protected function sendResponse(SwooleResponse $response, $data): void
    {
        // Response 对象：通过 send() 方法写入 Swoole 响应
        if ($data instanceof Response) {
            $data->send($response);
            return;
        }

        // 数组或普通对象：JSON 编码
        if (is_array($data)) {
            $response->header('Content-Type', 'application/json');
            $response->end(json_encode($data, JSON_UNESCAPED_UNICODE));
            return;
        }

        // 其他类型：转为字符串
        $response->header('Content-Type', 'text/html; charset=utf-8');
        $response->end((string)$data);
    }

    /**
     * 发送 404 响应
     */
    protected function sendNotFound(SwooleResponse $response): void
    {
        $response->status(404);
        $response->header('Content-Type', 'application/json');
        $response->end(json_encode([
            'code' => 404,
            'message' => 'Not Found',
            'data' => null
        ], JSON_UNESCAPED_UNICODE));
    }

    /**
     * 处理异常
     */
    protected function handleException(SwooleResponse $response, \Throwable $e): void
    {
        $this->logger->error("Request handler error: {$e->getMessage()}", [
            'trace' => $e->getTraceAsString()
        ]);

        $response->status(500);
        $response->header('Content-Type', 'application/json');
        $response->end(json_encode([
            'code' => 500,
            'message' => 'Internal Server Error',
            'data' => null
        ], JSON_UNESCAPED_UNICODE));
    }
}
