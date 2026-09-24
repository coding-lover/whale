<?php

namespace Sikelan\Http;

use Psr\Http\Message\ResponseInterface;
use Sikelan\Security\HtmlEncoder;
use Sikelan\Security\SecurityHeaders;

class Response implements ResponseInterface
{
    /**
     * 默认安全 JSON flags：
     * - JSON_UNESCAPED_UNICODE：保留中文可读
     * - JSON_HEX_TAG：把 < > 转为 \u003C \u003E，防 <script> 注入到 JSON 字符串
     * - JSON_HEX_APOS / JSON_HEX_QUOT / JSON_HEX_AMP：转义 ' " & 防 XSS 上下文逃逸
     */
    public const DEFAULT_JSON_FLAGS = JSON_UNESCAPED_UNICODE
        | JSON_HEX_TAG
        | JSON_HEX_APOS
        | JSON_HEX_QUOT
        | JSON_HEX_AMP;

    /**
     * 业务状态码常量（HTTP 状态码恒为 200，业务码用此区分）
     *
     * 编号约定：1XXX 段（与 HTTP 状态码区分，避免业务码 404 与 HTTP 404 混淆）。
     * 调用方也可自定义 code，只要保证非 0 即为失败。
     *
     * 用法：
     *   return (new Response())->ret($data);
     *   return (new Response())->err('Not Found', Response::CODE_NOT_FOUND);
     */
    public const CODE_OK            = 0;     // 成功
    public const CODE_ERROR         = 1;     // 通用失败（默认）
    public const CODE_BAD_REQUEST   = 1400;  // 参数错误
    public const CODE_UNAUTHORIZED  = 1401;  // 未认证
    public const CODE_FORBIDDEN      = 1403;  // 禁止访问
    public const CODE_NOT_FOUND     = 1404;   // 资源不存在
    public const CODE_VALIDATION    = 1422;  // 验证失败
    public const CODE_SERVER_ERROR  = 1500;  // 服务器内部错误

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

    /**
     * 输出 JSON 响应
     *
     * 安全特性：
     * - 默认附加 DEFAULT_JSON_FLAGS（JSON_HEX_TAG 等）：防止 JSON 字符串中的 <script>
     *   被浏览器误解析为 HTML 标签（防 XSS）。前端 JSON.parse 后仍是原字符，不影响业务逻辑。
     * - 可选 $escapeHtmlStrings = true 时：递归对所有字符串字段做 htmlspecialchars，
     *   适合把 JSON 直接渲染到前端 HTML 场景的彻底防 XSS（默认 false，保持数据透传）。
     *
     * @param mixed $data               要 JSON 编码的数据
     * @param bool  $escapeHtmlStrings  是否递归 HTML 转义字符串字段（默认 false）
     */
    public function withJson($data, bool $escapeHtmlStrings = false)
    {
        $new = clone $this;
        $new->headers['Content-Type'] = ['application/json; charset=utf-8'];
        if ($escapeHtmlStrings) {
            $data = HtmlEncoder::encodeJsonStrings($data);
        }
        $new->body = json_encode($data, self::DEFAULT_JSON_FLAGS);
        return $new;
    }

    /**
     * 输出 HTML 响应
     *
     * @param string $html        HTML 内容
     * @param bool   $autoEscape  是否自动 HTML 转义（默认 false；显式传 true 时输出纯文本安全转义）
     */
    public function withHtml($html, bool $autoEscape = false)
    {
        $new = clone $this;
        $new->headers['Content-Type'] = ['text/html; charset=utf-8'];
        $new->body = $autoEscape ? HtmlEncoder::encode($html) : $html;
        return $new;
    }

    /**
     * 注入默认安全响应头（X-Content-Type-Options / X-Frame-Options 等）
     *
     * 头列表来自 SecurityHeaders::defaults()，可通过 config/security.php 覆盖或关闭某项。
     * 已存在的头不会被覆盖（用户显式设置的优先）。
     *
     * @param array $overrides 临时覆盖某项头（key => value，value 为 null/false 时关闭该项）
     */
    public function withSecurityHeaders(array $overrides = []): self
    {
        $new = clone $this;
        $defaults = SecurityHeaders::defaults();
        // 合并顺序：defaults < overrides < 已有头（已有头优先级最高）
        $new->headers = array_merge($defaults, $overrides, $new->headers);
        return $new;
    }

    public function withRedirect($url, int $status = 302)
    {
        $new = $this->withStatus($status);
        $new->headers['Location'] = [$url];
        return $new;
    }

    /**
     * 统一成功响应（HTTP 状态码恒为 200）
     *
     * 输出结构：{"code":0,"message":"","data":<data>, ...extra}
     *
     * @param array $data  业务数据，置于 data 字段下
     * @param array $extra 顶层附加字段（如 pagination、count）；不可包含 code/message/data 保留键
     *                     ——即使包含也会被基础三字段覆盖（用 + 合并，前者优先）
     */
    public function ret(array $data = [], array $extra = []): self
    {
        $payload = ['code' => self::CODE_OK, 'message' => '', 'data' => $data] + $extra;
        return (new self(200))->withJson($payload);
    }

    /**
     * 统一失败响应（HTTP 状态码恒为 200）
     *
     * 输出结构：{"code":<code>,"message":<message>,"data":null, ...extra}
     *
     * @param string $message 异常信息
     * @param int    $code    业务状态码（非 0），默认 CODE_ERROR；推荐用 CODE_* 常量
     * @param array  $extra   顶层附加字段（如 errors、trace）
     */
    public function err(string $message, int $code = self::CODE_ERROR, array $extra = []): self
    {
        $payload = ['code' => $code, 'message' => $message, 'data' => null] + $extra;
        return (new self(200))->withJson($payload);
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
