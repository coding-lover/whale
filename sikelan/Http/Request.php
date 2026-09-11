<?php

namespace Sikelan\Http;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Message\HeadersInterface;
use Sikelan\Security\InputSanitizer;

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

    /**
     * 路由参数（由 RequestHandler::executeHandler 注入）
     * 例如 GET /api/users/{id} 命中后，此处会有 ['id' => '123']
     */
    private ?array $routeParams = null;

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

    // ========================================================================
    //  路由参数 + 安全便利方法（新增）
    // ========================================================================

    /**
     * 注入路由参数（由 RequestHandler::executeHandler 调用）
     *
     * 路由 /api/users/{id} 命中后，$params = ['id' => '123']
     * 注入后可通过 $request->input('id') / $request->getInt('id') 统一访问。
     *
     * @param array $params 路由参数
     */
    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    /**
     * 获取路由参数
     *
     * @return array
     */
    public function getRouteParams(): array
    {
        return $this->routeParams ?? [];
    }

    /**
     * 统一入口：合并 query + post + 路由参数，先净化，可选类型转换
     *
     * 查找顺序：query → post → route → default
     * 命中后：自动调用 InputSanitizer::clean（去 null byte / 控制字符）
     * 指定 $cast 时进一步调用 InputSanitizer::cast 做类型转换；校验失败返回 null
     *
     * @param string      $key     参数名
     * @param mixed|null  $default 默认值（参数缺失时返回）
     * @param string|null $cast    类型：int|float|bool|string|email|url|alpha|alnum
     * @return mixed
     */
    public function input(string $key, $default = null, ?string $cast = null)
    {
        // ?? 链：query → post → route → default
        $value = $this->queryParams[$key]
            ?? $this->postParams[$key]
            ?? $this->routeParams[$key]
            ?? $default;

        // 未命中且 default 为 null：直接返回 null，不净化
        if ($value === $default && $default === null) {
            return null;
        }

        // 净化（去 null byte / 控制字符）
        $value = InputSanitizer::clean($value);

        // 类型转换
        if ($cast !== null) {
            $casted = InputSanitizer::cast($value, $cast);
            // 校验失败：返回 default 而非 null（更友好）
            return $casted ?? $default;
        }

        return $value;
    }

    /**
     * 获取 int 类型参数
     *
     * @param string $key     参数名
     * @param int    $default 默认值
     */
    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->input($key);
        if ($value === null) {
            return $default;
        }
        $casted = InputSanitizer::cast($value, 'int');
        return $casted ?? $default;
    }

    /**
     * 获取 string 类型参数（数组会返回 default）
     *
     * @param string $key     参数名
     * @param string $default 默认值
     */
    public function getString(string $key, string $default = ''): string
    {
        $value = $this->input($key);
        if ($value === null) {
            return $default;
        }
        $casted = InputSanitizer::cast($value, 'string');
        return $casted ?? $default;
    }

    /**
     * 获取 bool 类型参数
     * 接受字符串 "1"/"true"/"yes"/"on"（大小写不敏感）与原生 bool
     *
     * @param string $key     参数名
     * @param bool   $default 默认值
     */
    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->input($key);
        if ($value === null) {
            return $default;
        }
        $casted = InputSanitizer::cast($value, 'bool');
        return $casted ?? $default;
    }

    /**
     * 获取 float 类型参数
     *
     * @param string $key     参数名
     * @param float  $default 默认值
     */
    public function getFloat(string $key, float $default = 0.0): float
    {
        $value = $this->input($key);
        if ($value === null) {
            return $default;
        }
        $casted = InputSanitizer::cast($value, 'float');
        return $casted ?? $default;
    }

    /**
     * 获取 email 参数（先 sanitize 再 validate，失败返回 null）
     *
     * @param string $key 参数名
     * @return string|null
     */
    public function getEmail(string $key): ?string
    {
        $value = $this->input($key);
        if ($value === null) {
            return null;
        }
        return InputSanitizer::cast($value, 'email');
    }

    /**
     * 获取 URL 参数（先 sanitize 再 validate，失败返回 null）
     *
     * @param string $key 参数名
     * @return string|null
     */
    public function getUrl(string $key): ?string
    {
        $value = $this->input($key);
        if ($value === null) {
            return null;
        }
        return InputSanitizer::cast($value, 'url');
    }

    /**
     * 获取纯字母参数（去除所有非 a-zA-Z 字符）
     *
     * @param string $key     参数名
     * @param string $default 默认值
     */
    public function getAlpha(string $key, string $default = ''): string
    {
        $value = $this->input($key);
        if ($value === null) {
            return $default;
        }
        $casted = InputSanitizer::cast($value, 'alpha');
        return $casted ?? $default;
    }

    /**
     * 获取字母+数字参数（去除所有非 a-zA-Z0-9 字符）
     *
     * @param string $key     参数名
     * @param string $default 默认值
     */
    public function getAlnum(string $key, string $default = ''): string
    {
        $value = $this->input($key);
        if ($value === null) {
            return $default;
        }
        $casted = InputSanitizer::cast($value, 'alnum');
        return $casted ?? $default;
    }

    /**
     * 判断参数是否存在（query + post + route 三源任一命中即 true）
     *
     * @param string $key 参数名
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->queryParams)
            || array_key_exists($key, $this->postParams)
            || ($this->routeParams !== null && array_key_exists($key, $this->routeParams));
    }

    /**
     * 只取指定键的参数（不存在的键 value 为 null）
     *
     * @param array $keys 键名列表
     * @return array<string, mixed>
     */
    public function only(array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->input($key);
        }
        return $result;
    }

    /**
     * 排除指定键后的全部参数
     *
     * @param array $keys 要排除的键名列表
     * @return array<string, mixed>
     */
    public function except(array $keys): array
    {
        $all = $this->all();
        foreach ($keys as $key) {
            unset($all[$key]);
        }
        return $all;
    }

    /**
     * 合并 query + post + route 三源，并对所有值做净化
     *
     * 优先级与 input() 保持一致：query > post > route（同名 key 时 query 覆盖 post）
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        // array_merge 后面的覆盖前面的 → route 放最前，query 放最后
        $merged = array_merge(
            $this->routeParams ?? [],
            $this->postParams,
            $this->queryParams
        );
        return InputSanitizer::clean($merged);
    }
}
