<?php

namespace Sikelan\Tests\Stest;

use PHPUnit\Framework\TestCase;
use Sikelan\Security\Hasher;

/**
 * Hasher 单元测试
 *
 * 覆盖：
 * - make() 返回 bcrypt 哈希（$2y$ 前缀）
 * - verify() 正确/错误密码
 * - needsRehash() cost 不变时返回 false，cost 升级时返回 true
 * - 不同明文生成不同哈希
 */
class HasherTest extends TestCase
{
    public function testMake_ReturnsBcryptHash()
    {
        $hash = Hasher::make('mypassword');
        // bcrypt 哈希 $2y$ 开头，60 字符
        $this->assertStringStartsWith('$2y$', $hash);
        $this->assertSame(60, strlen($hash));
    }

    public function testMake_DifferentPlainProducesDifferentHash()
    {
        // bcrypt 每次随机 salt，两次结果不同
        $h1 = Hasher::make('password1');
        $h2 = Hasher::make('password2');
        $this->assertNotEquals($h1, $h2);
    }

    public function testMake_SamePlainDifferentHashDueToRandomSalt()
    {
        $h1 = Hasher::make('samepass');
        $h2 = Hasher::make('samepass');
        $this->assertNotEquals($h1, $h2);
    }

    public function testVerify_CorrectPassword()
    {
        $hash = Hasher::make('mypassword');
        $this->assertTrue(Hasher::verify('mypassword', $hash));
    }

    public function testVerify_WrongPassword()
    {
        $hash = Hasher::make('mypassword');
        $this->assertFalse(Hasher::verify('wrongpass', $hash));
    }

    public function testVerify_EmptyPassword()
    {
        $hash = Hasher::make('mypassword');
        $this->assertFalse(Hasher::verify('', $hash));
    }

    public function testVerify_InvalidHashReturnsFalse()
    {
        // 非法哈希字符串，password_verify 不抛异常只返回 false
        $this->assertFalse(Hasher::verify('any', 'not-a-valid-hash'));
    }

    public function testNeedsRehash_CostUnchangedReturnsFalse()
    {
        $hash = Hasher::make('pass', ['cost' => 5]);
        // cost 相同时不需要重算
        $this->assertFalse(Hasher::needsRehash($hash, ['cost' => 5]));
    }

    public function testNeedsRehash_CostUpgradedReturnsTrue()
    {
        $hash = Hasher::make('pass', ['cost' => 5]);
        // cost 升级到 10 → 需要重算
        $this->assertTrue(Hasher::needsRehash($hash, ['cost' => 10]));
    }

    public function testMake_CustomCost()
    {
        // cost=4 是 bcrypt 最小值，速度快
        $hash = Hasher::make('test', ['cost' => 4]);
        $this->assertStringStartsWith('$2y$04$', $hash);
        $this->assertTrue(Hasher::verify('test', $hash));
    }
}
