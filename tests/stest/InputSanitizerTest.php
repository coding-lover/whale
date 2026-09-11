<?php

namespace Sikelan\Tests\Stest;

use PHPUnit\Framework\TestCase;
use Sikelan\Security\InputSanitizer;

/**
 * InputSanitizer 单元测试
 *
 * 覆盖：
 * - clean() 字符串去 null byte / 控制字符；保留 \t \n \r
 * - clean() 递归处理数组；非字符串原样返回
 * - cast() 各类型转换 + 校验失败返回 null
 */
class InputSanitizerTest extends TestCase
{
    // ========== clean() 测试 ==========

    public function testClean_RemovesNullByte()
    {
        // null byte 应被移除（防截断攻击）
        $this->assertSame('abc', InputSanitizer::clean("a\0b\0c"));
    }

    public function testClean_RemovesControlCharsButKeepsWhitespace()
    {
        // \t \n \r 应保留；其他控制字符应移除
        $input = "a\x01b\x07c\t\nd\re\x0Bf\x1Fg\x7F";
        $expected = "abc\t\nd\refg";
        $this->assertSame($expected, InputSanitizer::clean($input));
    }

    public function testClean_PreservesNormalString()
    {
        $this->assertSame('hello 中文 123', InputSanitizer::clean('hello 中文 123'));
    }

    public function testClean_RecursivelyHandlesArray()
    {
        $input = ['a' => "x\0y", 'b' => ['c' => "p\x01q"]];
        $expected = ['a' => 'xy', 'b' => ['c' => 'pq']];
        $this->assertSame($expected, InputSanitizer::clean($input));
    }

    public function testClean_NonStringReturnedAsIs()
    {
        // int / float / bool / null 不应被处理
        $this->assertSame(123, InputSanitizer::clean(123));
        $this->assertSame(1.5, InputSanitizer::clean(1.5));
        $this->assertSame(true, InputSanitizer::clean(true));
        $this->assertNull(InputSanitizer::clean(null));
    }

    // ========== cast() 测试 ==========

    public function testCast_IntValid()
    {
        $this->assertSame(123, InputSanitizer::cast('123', 'int'));
        $this->assertSame(123, InputSanitizer::cast(123, 'int'));
        $this->assertSame(1, InputSanitizer::cast('1.5', 'int')); // is_numeric=true，强转 int 截断
    }

    public function testCast_IntInvalidReturnsNull()
    {
        $this->assertNull(InputSanitizer::cast('abc', 'int'));
        $this->assertNull(InputSanitizer::cast(null, 'int'));
    }

    public function testCast_FloatValid()
    {
        $this->assertSame(1.5, InputSanitizer::cast('1.5', 'float'));
        $this->assertSame(100.0, InputSanitizer::cast('100', 'float'));
    }

    public function testCast_FloatInvalidReturnsNull()
    {
        $this->assertNull(InputSanitizer::cast('not-a-number', 'float'));
    }

    public function testCast_BoolAcceptsTruthyStrings()
    {
        $this->assertTrue(InputSanitizer::cast('1', 'bool'));
        $this->assertTrue(InputSanitizer::cast('true', 'bool'));
        $this->assertTrue(InputSanitizer::cast('TRUE', 'bool'));
        $this->assertTrue(InputSanitizer::cast('yes', 'bool'));
        $this->assertTrue(InputSanitizer::cast('on', 'bool'));
        $this->assertTrue(InputSanitizer::cast(true, 'bool'));
    }

    public function testCast_BoolRejectsFalsyStrings()
    {
        $this->assertFalse(InputSanitizer::cast('0', 'bool'));
        $this->assertFalse(InputSanitizer::cast('false', 'bool'));
        $this->assertFalse(InputSanitizer::cast('off', 'bool'));
        $this->assertFalse(InputSanitizer::cast('', 'bool'));
        $this->assertFalse(InputSanitizer::cast(false, 'bool'));
    }

    public function testCast_StringValid()
    {
        $this->assertSame('123', InputSanitizer::cast(123, 'string'));
        $this->assertSame('hello', InputSanitizer::cast('hello', 'string'));
    }

    public function testCast_StringArrayReturnsNull()
    {
        $this->assertNull(InputSanitizer::cast(['a', 'b'], 'string'));
    }

    public function testCast_EmailValid()
    {
        $this->assertSame('user@example.com', InputSanitizer::cast('user@example.com', 'email'));
        $this->assertSame('user@example.com', InputSanitizer::cast('  user@example.com  ', 'email'));
    }

    public function testCast_EmailInvalidReturnsNull()
    {
        $this->assertNull(InputSanitizer::cast('not-an-email', 'email'));
        $this->assertNull(InputSanitizer::cast('user@', 'email'));
        $this->assertNull(InputSanitizer::cast('@example.com', 'email'));
    }

    public function testCast_UrlValid()
    {
        $this->assertSame('https://example.com', InputSanitizer::cast('https://example.com', 'url'));
        $this->assertSame('http://example.com/path?q=1', InputSanitizer::cast('http://example.com/path?q=1', 'url'));
    }

    public function testCast_UrlInvalidReturnsNull()
    {
        $this->assertNull(InputSanitizer::cast('not-a-url', 'url'));
        $this->assertNull(InputSanitizer::cast('javascript:alert(1)', 'url'));
    }

    public function testCast_AlphaStripsNonLetters()
    {
        $this->assertSame('Hello', InputSanitizer::cast('Hello123!@#', 'alpha'));
        $this->assertSame('', InputSanitizer::cast('123!@#', 'alpha'));
    }

    public function testCast_AlnumStripsNonAlphanumeric()
    {
        $this->assertSame('Hello123', InputSanitizer::cast('Hello123!@#', 'alnum'));
        $this->assertSame('123', InputSanitizer::cast('1 2 3', 'alnum'));
    }

    public function testCast_UnknownTypeReturnsValueAsIs()
    {
        $this->assertSame('hello', InputSanitizer::cast('hello', 'unknown_type'));
    }
}
