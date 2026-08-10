<?php

namespace Sikelan\Tests\Stest;

use PHPUnit\Framework\TestCase;
use Sikelan\Http\Router;
use Sikelan\Http\Request;
use Sikelan\Http\Response;
use Sikelan\Http\Uri;

/**
 * HTTP 相关类全覆盖测试
 */
class HttpTest extends TestCase
{
    // ========== Uri 测试 ==========

    public function testUriConstructorWithEmptyString()
    {
        $uri = new Uri('');
        $this->assertEquals('', $uri->getScheme());
        $this->assertEquals('', $uri->getHost());
        $this->assertEquals('', $uri->getPath());
    }

    public function testUriConstructorWithFullUri()
    {
        $uri = new Uri('https://user:pass@example.com:8080/path?query=val#fragment');

        $this->assertEquals('https', $uri->getScheme());
        $this->assertEquals('user:pass', $uri->getUserInfo());
        $this->assertEquals('example.com', $uri->getHost());
        $this->assertEquals(8080, $uri->getPort());
        $this->assertEquals('/path', $uri->getPath());
        $this->assertEquals('query=val', $uri->getQuery());
        $this->assertEquals('fragment', $uri->getFragment());
    }

    public function testUriGetAuthority()
    {
        $uri = new Uri('https://example.com:8080');
        $this->assertEquals('example.com:8080', $uri->getAuthority());

        $uri2 = new Uri('https://user:pass@example.com');
        $this->assertEquals('user:pass@example.com', $uri2->getAuthority());
    }

    public function testUriWithScheme()
    {
        $uri = new Uri('');
        $new = $uri->withScheme('https');

        $this->assertEquals('https', $new->getScheme());
        $this->assertNotSame($uri, $new);
    }

    public function testUriWithUserInfo()
    {
        $uri = new Uri('');
        $new = $uri->withUserInfo('user', 'pass');

        $this->assertEquals('user:pass', $new->getUserInfo());
        $this->assertNotSame($uri, $new);
    }

    public function testUriWithHost()
    {
        $uri = new Uri('');
        $new = $uri->withHost('example.com');

        $this->assertEquals('example.com', $new->getHost());
        $this->assertNotSame($uri, $new);
    }

    public function testUriWithPort()
    {
        $uri = new Uri('');
        $new = $uri->withPort(8080);

        $this->assertEquals(8080, $new->getPort());
        $this->assertNotSame($uri, $new);
    }

    public function testUriWithPath()
    {
        $uri = new Uri('');
        $new = $uri->withPath('/test/path');

        $this->assertEquals('/test/path', $new->getPath());
        $this->assertNotSame($uri, $new);
    }

    public function testUriWithQuery()
    {
        $uri = new Uri('');
        $new = $uri->withQuery('key=value');

        $this->assertEquals('key=value', $new->getQuery());
        $this->assertNotSame($uri, $new);
    }

    public function testUriWithFragment()
    {
        $uri = new Uri('');
        $new = $uri->withFragment('section');

        $this->assertEquals('section', $new->getFragment());
        $this->assertNotSame($uri, $new);
    }

    public function testUriToString()
    {
        $uri = new Uri('https://example.com/path?query=val');
        $str = (string) $uri;

        $this->assertStringContainsString('https', $str);
        $this->assertStringContainsString('example.com', $str);
        $this->assertStringContainsString('/path', $str);
        $this->assertStringContainsString('query=val', $str);
    }

    // ========== Request 测试 ==========

    public function testRequestConstructor()
    {
        $request = new Request('GET', '/test', ['Content-Type' => ['application/json']], 'body');

        $this->assertEquals('GET', $request->getMethod());
        $this->assertInstanceOf(Uri::class, $request->getUri());
        $this->assertEquals('/test', $request->getUri()->getPath());
        $this->assertEquals(['Content-Type' => ['application/json']], $request->getHeaders());
    }

    public function testRequestGetRequestTarget()
    {
        $request = new Request('GET', '/test?key=value');
        $this->assertEquals('/test?key=value', $request->getRequestTarget());
    }

    public function testRequestWithRequestTarget()
    {
        $request = new Request('GET', '/test');
        $new = $request->withRequestTarget('/new-target');

        $this->assertEquals('/new-target', $new->getRequestTarget());
        $this->assertNotSame($request, $new);
    }

    public function testRequestWithMethod()
    {
        $request = new Request('GET', '/test');
        $new = $request->withMethod('POST');

        $this->assertEquals('POST', $new->getMethod());
        $this->assertNotSame($request, $new);
    }

    public function testRequestWithUri()
    {
        $request = new Request('GET', '/test');
        $newUri = new Uri('/new');
        $new = $request->withUri($newUri);

        $this->assertEquals('/new', $new->getUri()->getPath());
        $this->assertNotSame($request, $new);
    }

    public function testRequestHasHeader()
    {
        $request = new Request('GET', '/', ['Content-Type' => 'application/json']);

        $this->assertTrue($request->hasHeader('Content-Type'));
        $this->assertTrue($request->hasHeader('content-type')); // 大小写不敏感
        $this->assertFalse($request->hasHeader('X-Custom'));
    }

    public function testRequestGetHeader()
    {
        $request = new Request('GET', '/', ['Content-Type' => 'application/json']);

        $this->assertEquals(['application/json'], $request->getHeader('Content-Type'));
        $this->assertEquals([], $request->getHeader('X-Non-Existent'));
    }

    public function testRequestGetHeaderLine()
    {
        $request = new Request('GET', '/', ['Accept' => ['text/html', 'application/json']]);

        $this->assertEquals('text/html,application/json', $request->getHeaderLine('Accept'));
    }

    public function testRequestWithHeader()
    {
        $request = new Request('GET', '/');
        $new = $request->withHeader('X-Custom', 'value');

        $this->assertTrue($new->hasHeader('X-Custom'));
        $this->assertNotSame($request, $new);
    }

    public function testRequestWithAddedHeader()
    {
        $request = new Request('GET', '/', ['Accept' => ['text/html']]);
        $new = $request->withAddedHeader('Accept', 'application/json');

        $this->assertEquals('text/html,application/json', $new->getHeaderLine('Accept'));
    }

    public function testRequestWithoutHeader()
    {
        $request = new Request('GET', '/', ['Content-Type' => 'application/json']);
        $new = $request->withoutHeader('Content-Type');

        $this->assertFalse($new->hasHeader('Content-Type'));
    }

    public function testRequestGetProtocolVersion()
    {
        $request = new Request('GET', '/');
        $this->assertEquals('1.1', $request->getProtocolVersion());
    }

    public function testRequestWithProtocolVersion()
    {
        $request = new Request('GET', '/');
        $new = $request->withProtocolVersion('2.0');

        $this->assertEquals('2.0', $new->getProtocolVersion());
    }

    public function testRequestGetQueryParams()
    {
        $request = new Request('GET', '/');
        // queryParams 默认是空数组，除非通过 createFromSwoole 设置
        $this->assertEquals([], $request->getQueryParams());
    }

    public function testRequestGetPostParams()
    {
        $request = new Request('POST', '/');
        $method = new \ReflectionMethod($request, 'getPostParams');
        $method->setAccessible(true);
        // 由于 Request 构造函数不直接设置 postParams，我们需要测试它的默认值

        $this->assertEquals([], $request->getPostParams());
    }

    public function testRequestGetCookies()
    {
        $request = new Request('GET', '/');
        $this->assertEquals([], $request->getCookies());
    }

    public function testRequestGetParam()
    {
        $request = new Request('GET', '/');
        // 通过反射设置 queryParams
        $refl = new \ReflectionClass($request);
        $prop = $refl->getProperty('queryParams');
        $prop->setAccessible(true);
        $prop->setValue($request, ['key' => 'query_value']);

        $this->assertEquals('query_value', $request->getParam('key'));
        $this->assertEquals('default', $request->getParam('nonexistent', 'default'));
    }

    public function testRequestImplementsRequestInterface()
    {
        $request = new Request('GET', '/');
        $this->assertInstanceOf(\Psr\Http\Message\RequestInterface::class, $request);
    }

    // ========== Response 测试 ==========

    public function testResponseConstructorDefault()
    {
        $response = new Response();

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('OK', $response->getReasonPhrase());
    }

    public function testResponseConstructorWithStatus()
    {
        $response = new Response(404);

        $this->assertEquals(404, $response->getStatusCode());
        $this->assertEquals('Not Found', $response->getReasonPhrase());
    }

    public function testResponseConstructorWithAllParams()
    {
        $response = new Response(201, ['X-Custom' => 'value'], 'Created body');

        $this->assertEquals(201, $response->getStatusCode());
        $this->assertEquals('Created', $response->getReasonPhrase());
        $this->assertTrue($response->hasHeader('X-Custom'));
        $this->assertEquals('Created body', $response->getBody());
    }

    public function testResponseGetStatusCode()
    {
        $response = new Response(500);
        $this->assertEquals(500, $response->getStatusCode());
    }

    public function testResponseWithStatus()
    {
        $response = new Response();
        $new = $response->withStatus(302, 'Found');

        $this->assertEquals(302, $new->getStatusCode());
        $this->assertEquals('Found', $new->getReasonPhrase());
        $this->assertNotSame($response, $new);
    }

    public function testResponseWithStatusUnknownCode()
    {
        $response = new Response();
        $new = $response->withStatus(599);

        $this->assertEquals(599, $new->getStatusCode());
        $this->assertEquals('Unknown Status', $new->getReasonPhrase());
    }

    public function testResponseGetReasonPhrase()
    {
        $response = new Response(200);
        $this->assertEquals('OK', $response->getReasonPhrase());
    }

    public function testResponseGetHeaders()
    {
        $response = new Response(200, ['Content-Type' => ['text/html']]);
        $this->assertEquals(['Content-Type' => ['text/html']], $response->getHeaders());
    }

    public function testResponseHasHeader()
    {
        $response = new Response(200, ['X-Custom' => 'value']);

        $this->assertTrue($response->hasHeader('X-Custom'));
        $this->assertFalse($response->hasHeader('X-Other'));
    }

    public function testResponseGetHeader()
    {
        $response = new Response(200, ['X-Custom' => 'value']);

        $this->assertEquals(['value'], $response->getHeader('X-Custom'));
        $this->assertEquals([], $response->getHeader('X-Non-Existent'));
    }

    public function testResponseGetHeaderLine()
    {
        $response = new Response(200, ['Accept' => ['text/html', 'application/json']]);

        $this->assertEquals('text/html,application/json', $response->getHeaderLine('Accept'));
    }

    public function testResponseWithHeader()
    {
        $response = new Response();
        $new = $response->withHeader('X-Custom', 'value');

        $this->assertTrue($new->hasHeader('X-Custom'));
        $this->assertNotSame($response, $new);
    }

    public function testResponseWithAddedHeader()
    {
        $response = new Response(200, ['X-Custom' => ['value1']]);
        $new = $response->withAddedHeader('X-Custom', 'value2');

        $this->assertEquals(['value1', 'value2'], $new->getHeader('X-Custom'));
    }

    public function testResponseWithoutHeader()
    {
        $response = new Response(200, ['X-Custom' => 'value']);
        $new = $response->withoutHeader('X-Custom');

        $this->assertFalse($new->hasHeader('X-Custom'));
    }

    public function testResponseGetProtocolVersion()
    {
        $response = new Response();
        $this->assertEquals('1.1', $response->getProtocolVersion());
    }

    public function testResponseWithProtocolVersion()
    {
        $response = new Response();
        $new = $response->withProtocolVersion('2.0');

        $this->assertEquals('2.0', $new->getProtocolVersion());
    }

    public function testResponseWithJson()
    {
        $response = new Response();
        $new = $response->withJson(['key' => 'value']);

        $this->assertTrue($new->hasHeader('Content-Type'));
        $this->assertStringContainsString('key', $new->getBody());
        $this->assertStringContainsString('value', $new->getBody());
    }

    public function testResponseWithHtml()
    {
        $response = new Response();
        $new = $response->withHtml('<h1>Hello</h1>');

        $this->assertTrue($new->hasHeader('Content-Type'));
        $this->assertEquals('<h1>Hello</h1>', $new->getBody());
    }

    public function testResponseWithRedirect()
    {
        $response = new Response();
        $new = $response->withRedirect('/new-location', 301);

        $this->assertEquals(301, $new->getStatusCode());
        $this->assertTrue($new->hasHeader('Location'));
        $this->assertEquals(['/new-location'], $new->getHeader('Location'));
    }

    public function testResponseImplementsResponseInterface()
    {
        $response = new Response();
        $this->assertInstanceOf(\Psr\Http\Message\ResponseInterface::class, $response);
    }

    // ========== Router 测试 ==========

    public function testRouterGet()
    {
        $router = new Router();
        $result = $router->get('/test', function () {
        });

        $this->assertSame($router, $result);
        $this->assertCount(1, $router->getRoutes());
    }

    public function testRouterPost()
    {
        $router = new Router();
        $router->post('/test', function () {
        });

        $routes = $router->getRoutes();
        $this->assertEquals('POST', $routes[0][0]);
    }

    public function testRouterPut()
    {
        $router = new Router();
        $router->put('/test', function () {
        });

        $routes = $router->getRoutes();
        $this->assertEquals('PUT', $routes[0][0]);
    }

    public function testRouterDelete()
    {
        $router = new Router();
        $router->delete('/test', function () {
        });

        $routes = $router->getRoutes();
        $this->assertEquals('DELETE', $routes[0][0]);
    }

    public function testRouterAny()
    {
        $router = new Router();
        $router->any('/test', function () {
        });

        $routes = $router->getRoutes();
        $this->assertCount(4, $routes); // GET, POST, PUT, DELETE
    }

    public function testRouterGroup()
    {
        $router = new Router();
        $router->group('/api', function ($r) {
            $r->get('/users', function () {
            });
            $r->post('/users', function () {
            });
        });

        $routes = $router->getRoutes();
        $this->assertCount(2, $routes);
        $this->assertEquals('/api/users', $routes[0][1]);
    }

    public function testRouterGroupNested()
    {
        $router = new Router();
        $router->group('/api', function ($r) {
            $r->group('/v1', function ($r2) {
                $r2->get('/users', function () {
                });
            });
        });

        $routes = $router->getRoutes();
        $this->assertEquals('/api/v1/users', $routes[0][1]);
    }

    public function testRouterDispatchNotFound()
    {
        $router = new Router();
        $router->get('/test', function () {
        });

        $request = new Request('GET', '/nonexistent');
        $result = $router->dispatch($request);

        $this->assertNull($result);
    }

    public function testRouterDispatchMethodNotAllowed()
    {
        $router = new Router();
        $router->get('/test', function () {
        });

        $request = new Request('POST', '/test');
        $result = $router->dispatch($request);

        $this->assertEquals(['status' => 405, 'message' => 'Method not allowed'], $result);
    }

    public function testRouterDispatchFound()
    {
        $router = new Router();
        $router->get('/test/{id}', function ($id) {
            return 'user_' . $id;
        });

        $request = new Request('GET', '/test/123');
        $result = $router->dispatch($request);

        $this->assertArrayHasKey('handler', $result);
        $this->assertArrayHasKey('params', $result);
        $this->assertEquals(123, $result['params']['id']);
    }

    public function testRouterDispatchWithQueryParams()
    {
        $router = new Router();
        $router->get('/test', function () {
        });

        $request = new Request('GET', '/test?key=value');
        $result = $router->dispatch($request);

        $this->assertArrayHasKey('handler', $result);
    }
}
