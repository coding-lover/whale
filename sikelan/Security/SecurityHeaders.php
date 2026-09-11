<?php

namespace Sikelan\Security;

/**
 * 默认安全响应头
 *
 * 职责：提供一组默认的安全响应头（X-Content-Type-Options / X-Frame-Options /
 * Referrer-Policy / X-XSS-Protection），允许通过 config/security.php 覆盖或关闭某项。
 *
 * 设计参考：Symfony https://github.com/shieldon/opossum / Laravel 中间件默认安全头
 *
 * 用法：
 *   $headers = SecurityHeaders::defaults();
 *   foreach ($headers as $name => $value) {
 *       $response->header($name, $value);
 *   }
 *
 * 协程安全：纯静态方法、无可变静态状态。
 */
class SecurityHeaders
{
    /**
     * 框架内置默认安全响应头
     *
     * 不可在此处读取 config（避免循环依赖）——配置覆盖在 defaults() 中合并。
     */
    private const DEFAULT_HEADERS = [
        // 防 MIME 嗅探：浏览器会严格按 Content-Type 解析响应
        'X-Content-Type-Options' => 'nosniff',
        // 防点击劫持：仅允许同源 iframe 嵌入
        'X-Frame-Options' => 'SAMEORIGIN',
        // 控制 Referrer 头泄露：跨协议降级时不带 Referrer
        'Referrer-Policy' => 'no-referrer-when-downgrade',
        // 启用浏览器内置 XSS 过滤器（旧浏览器）
        'X-XSS-Protection' => '1; mode=block',
    ];

    /**
     * 获取默认安全响应头（合并 config/security.php 的覆盖）
     *
     * 用户可通过 config/security.php 的 'headers' 项覆盖：
     *   - 设为字符串：覆盖默认值
     *   - 设为 null 或 false：关闭该项（不输出该头）
     *
     * @return array<string, string> 头名 => 头值
     */
    public static function defaults(): array
    {
        // config() 可能在测试/极简环境不存在，安全降级用内置默认
        $config = [];
        if (function_exists('config')) {
            try {
                $config = config('security.headers', []) ?? [];
            } catch (\Throwable $e) {
                // 配置读取异常：用内置默认
                $config = [];
            }
        }
        // 配置覆盖默认值（配置优先级高于内置默认）
        $merged = array_merge(self::DEFAULT_HEADERS, $config);
        // 过滤掉值为 null/false 的项（让用户能通过配置关闭某项）
        return array_filter(
            $merged,
            static fn($v) => $v !== null && $v !== false
        );
    }
}
