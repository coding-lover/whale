<?php

namespace Sikelan\Tests\Stest;

use PHPUnit\Framework\TestCase;
use Sikelan\Security\HtmlEncoder;

/**
 * HtmlEncoder 单元测试
 *
 * 覆盖：
 * - encode() 基本转义（< > " ' &）
 * - encode() 非字符串强转
 * - encode() 自定义 flags
 * - encodeJsonStrings() 递归处理数组/对象/标量
 */
class HtmlEncoderTest extends TestCase
{
    public function testEncode_EscapesHtmlSpecialChars()
    {
        $input = '<script>alert("XSS");</script>';
        $expected = '&lt;script&gt;alert(&quot;XSS&quot;);&lt;/script&gt;';
        $this->assertSame($expected, HtmlEncoder::encode($input));
    }

    public function testEncode_EscapesSingleQuote()
    {
        // ENT_QUOTES 默认会转义单引号
        $this->assertSame('O&#039;Brien', HtmlEncoder::encode("O'Brien"));
    }

    public function testEncode_EscapesAmpersand()
    {
        $this->assertSame('a &amp; b', HtmlEncoder::encode('a & b'));
    }

    public function testEncode_PreservesChinese()
    {
        $this->assertSame('你好世界', HtmlEncoder::encode('你好世界'));
    }

    public function testEncode_NonStringIsCastedToString()
    {
        $this->assertSame('123', HtmlEncoder::encode(123));
        $this->assertSame('1.5', HtmlEncoder::encode(1.5));
        $this->assertSame('1', HtmlEncoder::encode(true));
    }

    public function testEncode_CustomFlagsWithoutQuotes()
    {
        // 显式用 ENT_COMPAT（不转义单引号）
        $this->assertSame("O'Brien", HtmlEncoder::encode("O'Brien", ENT_COMPAT | ENT_SUBSTITUTE));
    }

    public function testEncodeJsonStrings_PreservesArrayStructure()
    {
        $input = ['name' => '<script>', 'count' => 5, 'nested' => ['x' => '<b>']];
        $result = HtmlEncoder::encodeJsonStrings($input);
        $this->assertSame('&lt;script&gt;', $result['name']);
        $this->assertSame(5, $result['count']);
        $this->assertSame('&lt;b&gt;', $result['nested']['x']);
    }

    public function testEncodeJsonStrings_HandlesObject()
    {
        $obj = new \stdClass();
        $obj->name = '<script>';
        $obj->count = 5;
        $result = HtmlEncoder::encodeJsonStrings($obj);
        $this->assertInstanceOf(\stdClass::class, $result);
        $this->assertSame('&lt;script&gt;', $result->name);
        $this->assertSame(5, $result->count);
    }

    public function testEncodeJsonStrings_ScalarsReturnedAsIs()
    {
        $this->assertSame(123, HtmlEncoder::encodeJsonStrings(123));
        $this->assertSame(1.5, HtmlEncoder::encodeJsonStrings(1.5));
        $this->assertTrue(HtmlEncoder::encodeJsonStrings(true));
        $this->assertNull(HtmlEncoder::encodeJsonStrings(null));
    }

    public function testEncodeJsonStrings_StringEscaped()
    {
        $this->assertSame('&lt;script&gt;', HtmlEncoder::encodeJsonStrings('<script>'));
    }

    public function testEncodeJsonStrings_NumericArrayPreserved()
    {
        $input = ['<a>', '<b>', '<c>'];
        $result = HtmlEncoder::encodeJsonStrings($input);
        $this->assertSame(['&lt;a&gt;', '&lt;b&gt;', '&lt;c&gt;'], $result);
        $this->assertSame([0, 1, 2], array_keys($result));
    }
}
