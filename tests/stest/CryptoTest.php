<?php

namespace Sikelan\Tests\Stest;

use PHPUnit\Framework\TestCase;
use Sikelan\Security\Crypto;

/**
 * Crypto 单元测试
 *
 * 注意：AES 加解密依赖 APP_KEY。测试中显式传 key 参数，避免依赖 .env 配置。
 *
 * 覆盖：
 * - encrypt() 返回 base64 字符串且每次不同（随机 nonce）
 * - decrypt() 加解密对称性
 * - decrypt() 非法 payload 返回 null
 * - getKey() APP_KEY 缺失/格式错抛 RuntimeException
 * - generateKey() 返回 base64，解码后 32 字节
 */
class CryptoTest extends TestCase
{
    // 测试用固定 key：32 字节的 base64
    private const TEST_KEY_RAW = '0123456789abcdef0123456789abcdef';
    private const TEST_KEY_BASE64 = 'MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=';

    public function testEncrypt_ReturnsBase64String()
    {
        $cipher = Crypto::encrypt('hello', self::TEST_KEY_RAW);
        $this->assertIsString($cipher);
        // base64 解码成功
        $decoded = base64_decode($cipher, true);
        $this->assertNotFalse($decoded);
    }

    public function testEncrypt_DifferentCipherEachTime()
    {
        // 随机 nonce → 两次密文不同
        $c1 = Crypto::encrypt('hello', self::TEST_KEY_RAW);
        $c2 = Crypto::encrypt('hello', self::TEST_KEY_RAW);
        $this->assertNotEquals($c1, $c2);
    }

    public function testEncrypt_CiphertextLengthMatchesNoncePlusTagPlusPlain()
    {
        $plain = 'hello'; // 5 字节
        $cipher = Crypto::encrypt($plain, self::TEST_KEY_RAW);
        $decoded = base64_decode($cipher, true);
        // 12 (nonce) + 16 (tag) + 5 (plain) = 33
        $this->assertSame(33, strlen($decoded));
    }

    public function testDecrypt_RoundTrip()
    {
        $cipher = Crypto::encrypt('hello world', self::TEST_KEY_RAW);
        $plain = Crypto::decrypt($cipher, self::TEST_KEY_RAW);
        $this->assertSame('hello world', $plain);
    }

    public function testDecrypt_RoundTripWithChineseAndSpecialChars()
    {
        $plain = '你好世界 <script>alert(1)</script> &special="quotes"';
        $cipher = Crypto::encrypt($plain, self::TEST_KEY_RAW);
        $decrypted = Crypto::decrypt($cipher, self::TEST_KEY_RAW);
        $this->assertSame($plain, $decrypted);
    }

    public function testDecrypt_EmptyStringRoundTrip()
    {
        $cipher = Crypto::encrypt('', self::TEST_KEY_RAW);
        $this->assertSame('', Crypto::decrypt($cipher, self::TEST_KEY_RAW));
    }

    public function testDecrypt_InvalidBase64ReturnsNull()
    {
        $this->assertNull(Crypto::decrypt('!!!not-base64!!!', self::TEST_KEY_RAW));
    }

    public function testDecrypt_TooShortPayloadReturnsNull()
    {
        // 长度小于 12 (nonce) + 16 (tag) = 28
        $this->assertNull(Crypto::decrypt(base64_encode('short'), self::TEST_KEY_RAW));
    }

    public function testDecrypt_TamperedPayloadReturnsNull()
    {
        $cipher = Crypto::encrypt('hello', self::TEST_KEY_RAW);
        // 篡改最后 1 字节
        $decoded = base64_decode($cipher, true);
        $tampered = substr($decoded, 0, -1) . chr(ord($decoded[-1]) ^ 0xFF);
        $tamperedB64 = base64_encode($tampered);
        // GCM 校验失败 → 返回 null
        $this->assertNull(Crypto::decrypt($tamperedB64, self::TEST_KEY_RAW));
    }

    public function testDecrypt_WrongKeyReturnsNull()
    {
        $cipher = Crypto::encrypt('hello', self::TEST_KEY_RAW);
        $wrongKey = str_repeat('x', 32);
        $this->assertNull(Crypto::decrypt($cipher, $wrongKey));
    }

    public function testGetKey_MissingAppKeyThrows()
    {
        // 清空 APP_KEY 环境变量
        $original = getenv('APP_KEY');
        putenv('APP_KEY=');
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/APP_KEY not set/');
            Crypto::getKey();
        } finally {
            // 恢复环境变量
            if ($original !== false) {
                putenv("APP_KEY={$original}");
            }
        }
    }

    public function testGetKey_InvalidFormatThrows()
    {
        $original = getenv('APP_KEY');
        // 不是 32 字节的 base64
        putenv('APP_KEY=not-base64-32-bytes');
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/APP_KEY format invalid/');
            Crypto::getKey();
        } finally {
            if ($original !== false) {
                putenv("APP_KEY={$original}");
            } else {
                putenv('APP_KEY');
            }
        }
    }

    public function testGetKey_ValidBase64Returns32Bytes()
    {
        $original = getenv('APP_KEY');
        putenv('APP_KEY=' . self::TEST_KEY_BASE64);
        try {
            $key = Crypto::getKey();
            $this->assertSame(32, strlen($key));
        } finally {
            if ($original !== false) {
                putenv("APP_KEY={$original}");
            } else {
                putenv('APP_KEY');
            }
        }
    }

    public function testGenerateKey_ReturnsValidBase64Of32Bytes()
    {
        $key = Crypto::generateKey();
        $decoded = base64_decode($key, true);
        $this->assertNotFalse($decoded);
        $this->assertSame(32, strlen($decoded));
    }

    public function testGenerateKey_DifferentEachTime()
    {
        $k1 = Crypto::generateKey();
        $k2 = Crypto::generateKey();
        $this->assertNotEquals($k1, $k2);
    }
}
