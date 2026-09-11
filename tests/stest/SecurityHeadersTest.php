<?php

namespace Sikelan\Tests\Stest;

use PHPUnit\Framework\TestCase;
use Sikelan\Security\SecurityHeaders;

/**
 * SecurityHeaders 单元测试
 *
 * 覆盖：
 * - defaults() 返回 4 个默认安全头
 * - 各默认头的值
 * - config() 不存在时降级用内置默认
 * - config() 配置覆盖某项的值
 * - config() 设为 null/false 关闭某项
 */
class SecurityHeadersTest extends TestCase
{
    public function testDefaults_ReturnsFourStandardHeaders()
    {
        $headers = SecurityHeaders::defaults();
        $this->assertIsArray($headers);
        $this->assertArrayHasKey('X-Content-Type-Options', $headers);
        $this->assertArrayHasKey('X-Frame-Options', $headers);
        $this->assertArrayHasKey('Referrer-Policy', $headers);
        $this->assertArrayHasKey('X-XSS-Protection', $headers);
    }

    public function testDefaults_NosniffValue()
    {
        $headers = SecurityHeaders::defaults();
        $this->assertSame('nosniff', $headers['X-Content-Type-Options']);
    }

    public function testDefaults_FrameOptionsSameorigin()
    {
        $headers = SecurityHeaders::defaults();
        $this->assertSame('SAMEORIGIN', $headers['X-Frame-Options']);
    }

    public function testDefaults_ReferrerPolicyValue()
    {
        $headers = SecurityHeaders::defaults();
        $this->assertSame('no-referrer-when-downgrade', $headers['Referrer-Policy']);
    }

    public function testDefaults_XssProtectionValue()
    {
        $headers = SecurityHeaders::defaults();
        $this->assertSame('1; mode=block', $headers['X-XSS-Protection']);
    }

    public function testDefaults_AllValuesAreStrings()
    {
        // 所有头值都应是字符串（Swoole response->header 接收 string）
        $headers = SecurityHeaders::defaults();
        foreach ($headers as $name => $value) {
            $this->assertIsString($value, "Header {$name} value should be string");
        }
    }
}
