<?php

namespace Sikelan\Tests;

use PHPUnit\Framework\TestCase;
use Sikelan\Http\Router;
use Sikelan\Http\Request;

class RouterTest extends TestCase
{
    public function testRouterGet()
    {
        $router = new Router();
        $handler = function () {
            return 'test';
        };
        $router->get('/test', $handler);

        $request = new Request('GET', '/test');
        $route = $router->dispatch($request);

        $this->assertNotNull($route);
        $this->assertEquals($handler, $route['handler']);
    }

    public function testRouterPost()
    {
        $router = new Router();
        $handler = function () {
            return 'post_test';
        };
        $router->post('/api/users', $handler);

        $request = new Request('POST', '/api/users');
        $route = $router->dispatch($request);

        $this->assertNotNull($route);
        $this->assertEquals($handler, $route['handler']);
    }

    public function testRouterParams()
    {
        $router = new Router();
        $router->get('/users/{id}', function () {
        });

        $request = new Request('GET', '/users/123');
        $route = $router->dispatch($request);

        $this->assertNotNull($route);
        $this->assertEquals(['id' => '123'], $route['params']);
    }

    public function testRouterNotFound()
    {
        $router = new Router();
        $router->get('/test', function () {
        });

        $request = new Request('GET', '/not_found');
        $route = $router->dispatch($request);

        $this->assertNull($route);
    }

    public function testRouterMethodNotAllowed()
    {
        $router = new Router();
        $router->get('/test', function () {
        });

        $request = new Request('POST', '/test');
        $route = $router->dispatch($request);

        $this->assertNotNull($route);
        $this->assertEquals(405, $route['status']);
    }

    public function testRouterGroup()
    {
        $router = new Router();
        $router->group('/api', function ($r) {
            $r->get('/users', function () {
                return 'users';
            });
        });

        $request = new Request('GET', '/api/users');
        $route = $router->dispatch($request);

        $this->assertNotNull($route);
    }
}
