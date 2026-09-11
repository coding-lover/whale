<?php

namespace Sikelan\Security;

/**
 * 对称加解密（AES-256-GCM）
 *
 * 职责：基于 APP_KEY 做对称加解密；带 GCM 认证标签，可同时保证机密性与完整性。
 *
 * 设计参考：Laravel Cryptographer（AES-256-CBC + MAC）—— 这里升级用 GCM 更现代
 * （单步完成加密 + 认证，不依赖 hash_hmac 二次校验）。
 *
 * 实现要点：
 * - 用 openssl_encrypt / openssl_decrypt + 'aes-256-gcm' 算法（PHP 7.1+ openssl 内置，
 *   不依赖 sodium 扩展）
 * - GCM 标准：nonce=12 字节（96-bit），tag=16 字节（128-bit）
 * - payload 格式：base64(nonce(12) + tag(16) + cipher)
 * - 解密失败返回 null（不抛异常，避免吞异常；调用方用 ?? 兜底）
 *
 * 协程安全：纯静态方法、无可变静态状态。
 */
class Crypto
{
    /** GCM 标准 96-bit nonce 长度 */
    private const NONCE_LEN = 12;

    /** GCM 标准 128-bit 认证标签长度 */
    private const TAG_LEN = 16;

    /** APP_KEY 期望的字节长度（AES-256 = 32 字节） */
    private const KEY_LEN = 32;

    /**
     * 加密
     *
     * @param string      $plain 明文
     * @param string|null $key   32 字节密钥；null 时从 APP_KEY 读取
     * @return string base64(nonce + tag + cipher)
     * @throws \RuntimeException APP_KEY 未配置 / 格式错 / openssl 加密失败
     * @throws \Random\RandomException random_bytes 失败（极罕见）
     */
    public static function encrypt(string $plain, ?string $key = null): string
    {
        $key = $key ?? self::getKey();
        // 随机生成 nonce（每次加密不同，保证语义安全）
        $nonce = random_bytes(self::NONCE_LEN);
        $tag = '';
        // OPENSSL_RAW_DATA：返回原始二进制而非 base64
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($cipher === false) {
            // 加密失败必须抛异常带上下文（遵循 global-style §8）
            throw new \RuntimeException(
                'AES-256-GCM encrypt failed: ' . (openssl_error_string() ?: 'unknown')
            );
        }
        // 拼接 nonce + tag + cipher 后 base64，便于存储/传输
        return base64_encode($nonce . $tag . $cipher);
    }

    /**
     * 解密
     *
     * @param string      $payload base64(nonce + tag + cipher)
     * @param string|null $key     32 字节密钥；null 时从 APP_KEY 读取
     * @return string|null 解密成功返回明文；payload 非法或被篡改返回 null
     * @throws \RuntimeException APP_KEY 未配置 / 格式错
     */
    public static function decrypt(string $payload, ?string $key = null): ?string
    {
        $key = $key ?? self::getKey();
        // base64 严格解码：非 base64 字符直接失败
        $decoded = base64_decode($payload, true);
        if ($decoded === false) {
            return null;
        }
        // 长度校验：至少要能切出 nonce + tag
        if (strlen($decoded) < self::NONCE_LEN + self::TAG_LEN) {
            return null;
        }
        // 切片：nonce(12) + tag(16) + cipher(剩余)
        $nonce = substr($decoded, 0, self::NONCE_LEN);
        $tag = substr($decoded, self::NONCE_LEN, self::TAG_LEN);
        $cipher = substr($decoded, self::NONCE_LEN + self::TAG_LEN);
        // GCM 解密会校验 tag：tag 不匹配（数据被篡改）返回 false
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
        return $plain === false ? null : $plain;
    }

    /**
     * 获取 APP_KEY（base64 解码为 32 字节）
     *
     * @return string 32 字节二进制密钥
     * @throws \RuntimeException APP_KEY 未配置 / 格式错
     */
    public static function getKey(): string
    {
        $key = env('APP_KEY', '');
        if ($key === '') {
            throw new \RuntimeException(
                'APP_KEY not set. Run `php bin/sikelan security:key` to generate.'
            );
        }
        $decoded = base64_decode($key, true);
        if ($decoded === false || strlen($decoded) !== self::KEY_LEN) {
            throw new \RuntimeException(
                'APP_KEY format invalid. Expected base64 of 32 bytes (got '
                . (strlen($decoded) ?: 0) . ' bytes after decode).'
            );
        }
        return $decoded;
    }

    /**
     * 生成新的 APP_KEY（32 字节随机数的 base64 表示）
     */
    public static function generateKey(): string
    {
        return base64_encode(random_bytes(self::KEY_LEN));
    }
}
