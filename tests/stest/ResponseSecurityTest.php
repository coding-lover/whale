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

    // ========== ret() / err() 统一响应格式 ==========

    /**
     * 成功响应：code=0 / message='' / data=data / HTTP 状态码恒为 200
     */
    public function testRet_ReturnsUnifiedSuccessPayload()
    {
        $resp = (new Response())->ret(['id' => 1, 'name' => 'foo']);
        $body = json_decode($resp->getBody(), true);

        $this->assertSame(200, $resp->getStatusCode());
        $this->assertSame(0, $body['code']);
        $this->assertSame('', $body['message']);
        $this->assertSame(['id' => 1, 'name' => 'foo'], $body['data']);
    }

    /**
     * ret() 的 extra 字段（如 pagination）与三基础字段平级共存
     */
    public function testRet_MergesExtraIntoPayload()
    {
        $resp = (new Response())->ret(
            [['id' => 1], ['id' => 2]],
            ['pagination' => ['page' => 1, 'total' => 2]]
        );
        $body = json_decode($resp->getBody(), true);

        $this->assertSame(0, $body['code']);
        $this->assertSame([['id' => 1], ['id' => 2]], $body['data']);
        $this->assertSame(['page' => 1, 'total' => 2], $body['pagination']);
    }

    /**
     * ret() 的 extra 中含保留键 code/message/data 时，基础三字段不被覆盖（用 + 合并）
     */
    public function testRet_ExtraCannotOverrideReservedKeys()
    {
        $resp = (new Response())->ret(['a' => 1], ['code' => 999, 'message' => 'hack', 'data' => 'leak']);
        $body = json_decode($resp->getBody(), true);

        // 基础三字段保持不变
        $this->assertSame(0, $body['code']);
        $this->assertSame('', $body['message']);
        $this->assertSame(['a' => 1], $body['data']);
    }

    /**
     * ret() 默认空 data 时输出 data 为空数组
     */
    public function testRet_DefaultEmptyData()
    {
        $resp = (new Response())->ret();
        $body = json_decode($resp->getBody(), true);

        $this->assertSame(0, $body['code']);
        $this->assertSame([], $body['data']);
        $this->assertSame('', $body['message']);
    }

    /**
     * 失败响应：code≠0 / message=异常信息 / data=null / HTTP 状态码恒为 200
     */
    public function testErr_ReturnsUnifiedErrorPayload()
    {
        $resp = (new Response())->err('Not Found', Response::CODE_NOT_FOUND);
        $body = json_decode($resp->getBody(), true);

        $this->assertSame(200, $resp->getStatusCode());
        $this->assertSame(Response::CODE_NOT_FOUND, $body['code']);
        $this->assertSame('Not Found', $body['message']);
        $this->assertNull($body['data']);
    }

    /**
     * err() 默认 code=CODE_ERROR（1）
     */
    public function testErr_DefaultCodeIsError()
    {
        $resp = (new Response())->err('Something went wrong');
        $body = json_decode($resp->getBody(), true);

        $this->assertSame(200, $resp->getStatusCode());
        $this->assertSame(Response::CODE_ERROR, $body['code']);
        $this->assertSame('Something went wrong', $body['message']);
    }

    /**
     * err() 的 extra（如 errors 详情）与三基础字段平级共存
     */
    public function testErr_MergesExtraIntoPayload()
    {
        $resp = (new Response())->err(
            'Validation failed',
            Response::CODE_VALIDATION,
            ['errors' => ['name' => ['required']]]
        );
        $body = json_decode($resp->getBody(), true);

        $this->assertSame(Response::CODE_VALIDATION, $body['code']);
        $this->assertSame('Validation failed', $body['message']);
        $this->assertNull($body['data']);
        $this->assertSame(['name' => ['required']], $body['errors']);
    }

    /**
     * 业务状态码常量：CODE_OK=0、CODE_NOT_FOUND/CODE_VALIDATION 等非 0
     */
    public function testCodeConstants_AreSemanticallyCorrect()
    {
        $this->assertSame(0, Response::CODE_OK);
        $this->assertNotEquals(0, Response::CODE_ERROR);
        $this->assertNotEquals(0, Response::CODE_BAD_REQUEST);
        $this->assertNotEquals(0, Response::CODE_UNAUTHORIZED);
        $this->assertNotEquals(0, Response::CODE_FORBIDDEN);
        $this->assertNotEquals(0, Response::CODE_NOT_FOUND);
        $this->assertNotEquals(0, Response::CODE_VALIDATION);
        $this->assertNotEquals(0, Response::CODE_SERVER_ERROR);
    }

    /**
     * ret()/err() 也走 DEFAULT_JSON_FLAGS：JSON 字符串中的 <script> 被转义防 XSS
     */
    public function testRet_EscapesAngleBracketsInJson()
    {
        $resp = (new Response())->ret(['msg' => '<script>alert(1)</script>']);
        $body = $resp->getBody();

        $this->assertStringContainsString('\u003Cscript\u003E', $body);
        $this->assertStringNotContainsString('<script>', $body);
    }

    /**
     * err() 设置 Content-Type: application/json
     */
    public function testRetAndErr_SetJsonContentType()
    {
        $ok = (new Response())->ret([]);
        $err = (new Response())->err('oops');
        $this->assertStringContainsString('application/json', $ok->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('application/json', $err->getHeaderLine('Content-Type'));
    }
}
