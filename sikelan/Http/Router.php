<?php

namespace Sikelan\Http;

use FastRoute\RouteCollector;
use FastRoute\Dispatcher;

use function FastRoute\simpleDispatcher;

class Router
{
    protected $dispatcher;
    protected $routes = [];

    public function __construct()
    {
    }

    public function get($path, $handler)
    {
        $this->routes[] = ['GET', $path, $handler];
        return $this;
    }

    public function post($path, $handler)
    {
        $this->routes[] = ['POST', $path, $handler];
        return $this;
    }

    public function put($path, $handler)
    {
        $this->routes[] = ['PUT', $path, $handler];
        return $this;
    }

    public function delete($path, $handler)
    {
        $this->routes[] = ['DELETE', $path, $handler];
        return $this;
    }

    public function any($path, $handler)
    {
        $this->routes[] = ['GET', $path, $handler];
        $this->routes[] = ['POST', $path, $handler];
        $this->routes[] = ['PUT', $path, $handler];
        $this->routes[] = ['DELETE', $path, $handler];
        return $this;
    }

    public function group($prefix, callable $callback)
    {
        $router = new self();
        $callback($router);

        foreach ($router->routes as $route) {
            list($method, $path, $handler) = $route;
            $this->routes[] = [$method, $prefix . $path, $handler];
        }

        return $this;
    }

    public function dispatch(Request $request)
    {
        if (!$this->dispatcher) {
            $this->buildDispatcher();
        }

        $httpMethod = $request->getMethod();
        $uri = $request->getUri()->getPath();

        $routeInfo = $this->dispatcher->dispatch($httpMethod, $uri);

        switch ($routeInfo[0]) {
            case Dispatcher::NOT_FOUND:
                return null;
            case Dispatcher::METHOD_NOT_ALLOWED:
                return ['status' => 405, 'message' => 'Method not allowed'];
            case Dispatcher::FOUND:
                return [
                    'handler' => $routeInfo[1],
                    'params' => $routeInfo[2]
                ];
            default:
                return null;
        }
    }

    protected function buildDispatcher()
    {
        $this->dispatcher = simpleDispatcher(function (RouteCollector $r) {
            foreach ($this->routes as $route) {
                list($method, $path, $handler) = $route;
                $r->addRoute($method, $path, $handler);
            }
        });
    }

    public function getRoutes()
    {
        return $this->routes;
    }

    public function loadFromConfig(array $routes)
    {
        foreach ($routes as $route) {
            $method = $route['method'] ?? 'GET';
            $path = $route['path'] ?? '/';
            $handler = $route['handler'] ?? null;

            if ($handler === null) {
                continue;
            }

            $methods = is_array($method) ? $method : [$method];

            foreach ($methods as $m) {
                $this->routes[] = [$m, $path, $handler];
            }
        }

        return $this;
    }

    public function loadFromFile(string $configFile)
    {
        if (!file_exists($configFile)) {
            return $this;
        }

        $routes = require $configFile;

        if (is_array($routes)) {
            $this->loadFromConfig($routes);
        }

        return $this;
    }
}
