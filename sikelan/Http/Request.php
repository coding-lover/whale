<?php

namespace Sikelan\Http;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Message\HeadersInterface;

class Request implements RequestInterface
{
    protected $method;
    protected $uri;
    protected $headers = [];
    protected $body;
    protected $protocol = '1.1';
    protected $queryParams = [];
    protected $postParams = [];
    protected $serverParams = [];
    protected $cookies = [];

    public function __construct(string $method, $uri, array $headers = [], $body = null, string $protocol = '1.1')
    {
        $this->method = strtoupper($method);
        $this->uri = is_string($uri) ? new Uri($uri) : $uri;
        $this->headers = $headers;
        $this->body = $body;
        $this->protocol = $protocol;
    }

    public static function createFromSwoole(\Swoole\Http\Request $request)
    {
        $req = new self(
            $request->server['request_method'],
            $request->server['request_uri'],
            $request->header ?? [],
            $request->rawContent()
        );

        $req->queryParams = $request->get ?? [];
        $req->postParams = $request->post ?? [];
        $req->serverParams = $request->server ?? [];
        $req->cookies = $request->cookie ?? [];

        return $req;
    }

    public function getRequestTarget()
    {
        return $this->uri->getPath() . ($this->uri->getQuery() ? '?' . $this->uri->getQuery() : '');
    }

    public function withRequestTarget($requestTarget)
    {
        $new = clone $this;
        $new->uri = new Uri($requestTarget);
        return $new;
    }

    public function getMethod()
    {
        return $this->method;
    }

    public function withMethod($method)
    {
        $new = clone $this;
        $new->method = strtoupper($method);
        return $new;
    }

    public function getUri()
    {
        return $this->uri;
    }

    public function withUri(UriInterface $uri, $preserveHost = false)
    {
        $new = clone $this;
        $new->uri = $uri;
        return $new;
    }

    public function getHeaders()
    {
        return $this->headers;
    }

    public function hasHeader($name)
    {
        $name = strtolower($name);
        foreach (array_keys($this->headers) as $key) {
            if (strtolower($key) === $name) {
                return true;
            }
        }
        return false;
    }

    public function getHeader($name)
    {
        $name = strtolower($name);
        foreach ($this->headers as $key => $value) {
            if (strtolower($key) === $name) {
                return (array)$value;
            }
        }
        return [];
    }

    public function getHeaderLine($name)
    {
        return implode(',', $this->getHeader($name));
    }

    public function withHeader($name, $value)
    {
        $new = clone $this;
        $new->headers[$name] = (array)$value;
        return $new;
    }

    public function withAddedHeader($name, $value)
    {
        $new = clone $this;
        if (!isset($new->headers[$name])) {
            $new->headers[$name] = [];
        }
        $new->headers[$name][] = $value;
        return $new;
    }

    public function withoutHeader($name)
    {
        $new = clone $this;
        foreach (array_keys($new->headers) as $key) {
            if (strtolower($key) === strtolower($name)) {
                unset($new->headers[$key]);
                break;
            }
        }
        return $new;
    }

    public function getBody()
    {
        return $this->body;
    }

    public function withBody(\Psr\Http\Message\StreamInterface $body)
    {
        $new = clone $this;
        $new->body = $body;
        return $new;
    }

    public function getProtocolVersion()
    {
        return $this->protocol;
    }

    public function withProtocolVersion($version)
    {
        $new = clone $this;
        $new->protocol = $version;
        return $new;
    }

    public function getQueryParams()
    {
        return $this->queryParams;
    }

    public function getPostParams()
    {
        return $this->postParams;
    }

    public function getServerParams()
    {
        return $this->serverParams;
    }

    public function getCookies()
    {
        return $this->cookies;
    }

    public function getParam($name, $default = null)
    {
        if (isset($this->queryParams[$name])) {
            return $this->queryParams[$name];
        }
        if (isset($this->postParams[$name])) {
            return $this->postParams[$name];
        }
        return $default;
    }
}
