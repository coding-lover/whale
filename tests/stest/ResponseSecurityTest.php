<?php

namespace Sikelan\Tests\Stest;

use PHPUnit\Framework\TestCase;
use Sikelan\Http\Response;

/**
 * Response 安全特性单元测试
 *
 * 覆盖：
 * - DEFAULT_JSON_FLAGS 常量
 * - withJson() 默认附加安全 flag（<script> 被转为 \u003Cscript\u003E）
 * - withJson(escapeHtml=true) 递归 HTML 转义字符串字段
 * - withHtml(autoEscape=true) 自动转义
 * - withSecurityHeaders() 注入默认安全头
 * - withSecurityHeaders() 不覆盖已显式设置的头
 */
class ResponseSecurityTest extends TestCase
{
    // ========== DEFAULT_JSON_FLAGS ==========

    public function testDefaultJsonFlagsConstant_IncludesHtmlSafetyBits()
    {
        $this->assertTrue((Response::DEFAULT_JSON_FLAGS & JSON_HEX_TAG) !== 0);
        $this->assertTrue((Response::DEFAULT_JSON_FLAGS & JSON_HEX_APOS) !== 0);
        $this->assertTrue((Response::DEFAULT_JSON_FLAGS & JSON_HEX_QUOT) !== 0);
        $this->assertTrue((Response::DEFAULT_JSON_FLAGS & JSON_HEX_AMP) !== 0);
        $this->assertTrue((Response::DEFAULT_JSON_FLAGS & JSON_UNESCAPED_UNICODE) !== 0);
    }

    // ========== withJson() 安全 flag ==========

    public function testWithJson_EscapesAngleBracketsByDefault()
    {
        // 默认就启用 JSON_HEX_TAG：JSON 字符串里的 <script> 会被转为 \u003Cscript\u003E
        $resp = (new Response())->withJson(['msg' => '<script>alert(1)</script>']);
        $body = $resp->getBody();
        $this->assertStringContainsString('\u003Cscript\u003E', $body);
        $this->assertStringNotContainsString('<script>', $body);
    }

    public function testWithJson_EscapesQuotes()
    {
        $resp = (new Response())->withJson(['msg' => "has ' and \" quotes"]);
        $body = $resp->getBody();
        // 单引号 → \u0027，双引号 → \u0022
        $this->assertStringContainsString('\u0027', $body);
        $this->assertStringContainsString('\u0022', $body);
    }

    public function testWithJson_EscapesAmpersand()
    {
        $resp = (new Response())->withJson(['msg' => 'a & b']);
        $body = $resp->getBody();
        $this->assertStringContainsString('\u0026', $body);
    }

    public function testWithJson_PreservesChineseAsIs()
    {
        $resp = (new Response())->withJson(['msg' => '你好']);
        $body = $resp->getBody();
        $this->assertStringContainsString('你好', $body);
    }

    public function testWithJson_SetsContentType()
    {
        $resp = (new Response())->withJson(['a' => 1]);
        $this->assertTrue($resp->hasHeader('Content-Type'));
        $this->assertStringContainsString('application/json', $resp->getHeaderLine('Content-Type'));
    }

    // ========== withJson(escapeHtml=true) ==========

    public function testWithJson_EscapeHtmlStringsRecursivelyEscapesStringFields()
    {
        $data = ['name' => '<script>', 'count' => 5, 'nested' => ['x' => '<b>']];
        $resp = (new Response())->withJson($data, true);
        $body = $resp->getBody();
        // 字符串字段被 HTML 转义后再 JSON 编码：&lt; 会被再转义为 \u0026lt;
        $this->assertStringContainsString('lt;', $body);
        $this->assertStringNotContainsString('<script>', $body);
    }

    public function testWithJson_EscapeHtmlStringsPreservesNonStringFields()
    {
        $data = ['count' => 5, 'price' => 12.5, 'flag' => true, 'nil' => null];
        $resp = (new Response())->withJson($data, true);
        $body = $resp->getBody();
        // 数值和布尔不应被转义
        $decoded = json_decode($body, true);
        $this->assertSame(5, $decoded['count']);
        $this->assertSame(12.5, $decoded['price']);
        $this->assertTrue($decoded['flag']);
        $this->assertNull($decoded['nil']);
    }

    // ========== withHtml(autoEscape) ==========

    public function testWithHtml_DefaultNotEscape()
    {
        $resp = (new Response())->withHtml('<h1>hello</h1>');
        $this->assertSame('<h1>hello</h1>', $resp->getBody());
    }

    public function testWithHtml_AutoEscapeEscapesHtml()
    {
        $resp = (new Response())->withHtml('<script>alert(1)</script>', true);
        $this->assertSame('&lt;script&gt;alert(1)&lt;/script&gt;', $resp->getBody());
    }

    public function testWithHtml_SetsContentType()
    {
        $resp = (new Response())->withHtml('hi');
        $this->assertStringContainsString('text/html', $resp->getHeaderLine('Content-Type'));
    }

    // ========== withSecurityHeaders() ==========

    public function testWithSecurityHeaders_AddsDefaults()
    {
        $resp = (new Response())->withSecurityHeaders();
        $this->assertTrue($resp->hasHeader('X-Content-Type-Options'));
        $this->assertTrue($resp->hasHeader('X-Frame-Options'));
        $this->assertTrue($resp->hasHeader('Referrer-Policy'));
        $this->assertTrue($resp->hasHeader('X-XSS-Protection'));
    }

    public function testWithSecurityHeaders_DefaultValues()
    {
        $resp = (new Response())->withSecurityHeaders();
        $this->assertSame('nosniff', $resp->getHeaderLine('X-Content-Type-Options'));
        $this->assertSame('SAMEORIGIN', $resp->getHeaderLine('X-Frame-Options'));
        $this->assertSame('no-referrer-when-downgrade', $resp->getHeaderLine('Referrer-Policy'));
        $this->assertSame('1; mode=block', $resp->getHeaderLine('X-XSS-Protection'));
    }

    public function testWithSecurityHeaders_DoesNotOverrideExplicitlySetHeader()
    {
        // 用户显式设置了 X-Frame-Options → 不应被默认值覆盖
        $resp = (new Response())
            ->withHeader('X-Frame-Options', 'DENY')
            ->withSecurityHeaders();
        $this->assertSame('DENY', $resp->getHeaderLine('X-Frame-Options'));
        // 其他默认头仍应被注入
        $this->assertSame('nosniff', $resp->getHeaderLine('X-Content-Type-Options'));
    }

    public function testWithSecurityHeaders_OverridesParameter()
    {
        // 通过 $overrides 参数临时改某项的值
        $resp = (new Response())->withSecurityHeaders(['X-Frame-Options' => 'DENY']);
        $this->assertSame('DENY', $resp->getHeaderLine('X-Frame-Options'));
    }

    public function testWithSecurityHeaders_ReturnsNewInstance()
    {
        $original = new Response();
        $new = $original->withSecurityHeaders();
        $this->assertNotSame($original, $new);
    }

    // ========== 链式调用 ==========

    public function testChain_WithJsonThenWithSecurityHeaders()
    {
        $resp = (new Response())
            ->withJson(['msg' => 'hello'])
            ->withSecurityHeaders();
        $this->assertTrue($resp->hasHeader('Content-Type'));
        $this->assertTrue($resp->hasHeader('X-Content-Type-Options'));
        $this->assertStringContainsString('hello', $resp->getBody());
    }

    public function testChain_WithStatusThenWithJsonThenWithSecurityHeaders()
    {
        $resp = (new Response(422))
            ->withJson(['errors' => ['name' => 'required']])
            ->withSecurityHeaders();
        $this->assertSame(422, $resp->getStatusCode());
        $this->assertTrue($resp->hasHeader('X-Content-Type-Options'));
        $this->assertStringContainsString('required', $resp->getBody());
    }
}
