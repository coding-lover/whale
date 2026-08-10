<?php

namespace Sikelan\Http;

use Psr\Http\Message\ResponseInterface;

class Response implements ResponseInterface
{
    protected $statusCode = 200;
    protected $reasonPhrase = 'OK';
    protected $headers = [];
    protected $body = '';
    protected $protocol = '1.1';

    protected static $statusTexts = [
        100 => 'Continue',
        101 => 'Switching Protocols',
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        203 => 'Non-Authoritative Information',
        204 => 'No Content',
        205 => 'Reset Content',
        206 => 'Partial Content',
        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        305 => 'Use Proxy',
        307 => 'Temporary Redirect',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        408 => 'Request Timeout',
        409 => 'Conflict',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
    ];

    public function __construct(int $status = 200, array $headers = [], $body = '')
    {
        $this->statusCode = $status;
        $this->headers = $headers;
        $this->body = $body;
        $this->reasonPhrase = self::$statusTexts[$status] ?? 'Unknown Status';
    }

    public function getStatusCode()
    {
        return $this->statusCode;
    }

    public function withStatus($code, $reasonPhrase = '')
    {
        $new = clone $this;
        $new->statusCode = $code;
        $new->reasonPhrase = $reasonPhrase ?: (self::$statusTexts[$code] ?? 'Unknown Status');
        return $new;
    }

    public function getReasonPhrase()
    {
        return $this->reasonPhrase;
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

    public function withJson($data)
    {
        $new = clone $this;
        $new->headers['Content-Type'] = ['application/json; charset=utf-8'];
        $new->body = json_encode($data, JSON_UNESCAPED_UNICODE);
        return $new;
    }

    public function withHtml($html)
    {
        $new = clone $this;
        $new->headers['Content-Type'] = ['text/html; charset=utf-8'];
        $new->body = $html;
        return $new;
    }

    public function withRedirect($url, int $status = 302)
    {
        $new = clone $this->withStatus($status);
        $new->headers['Location'] = [$url];
        return $new;
    }

    public function send(\Swoole\Http\Response $swooleResponse)
    {
        foreach ($this->headers as $name => $values) {
            foreach ((array)$values as $value) {
                $swooleResponse->header($name, $value);
            }
        }

        $swooleResponse->status($this->statusCode);
        $swooleResponse->end($this->body);
    }
}
