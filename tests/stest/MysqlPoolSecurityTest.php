<?php

namespace Sikelan\Tests\Stest;

use PHPUnit\Framework\TestCase;
use Sikelan\Database\MysqlPool;
use Sikelan\Core\Config;

/**
 * MysqlPool 安全特性单元测试
 *
 * 仅覆盖 SQL 标识符转义逻辑（不实际连接 DB），不需要 Swoole 协程环境。
 *
 * 覆盖：
 * - quoteIdentifier() 合法表名 → 反引号包裹
 * - quoteIdentifier() 非法表名 → 抛 InvalidArgumentException
 * - quoteIdentifier() 双反引号转义
 */
class MysqlPoolSecurityTest extends TestCase
{
    /**
     * 构造 MysqlPool 实例（不连接 DB，仅用于调 quoteIdentifier）
     */
    private function makePool(): MysqlPool
    {
        // Config 需要 config 路径但不实际加载（quoteIdentifier 不依赖配置）
        $config = new Config('');
        return new MysqlPool($config);
    }

    // ========== quoteIdentifier 合法输入 ==========

    public function testQuoteIdentifier_WrapsValidNameWithBackticks()
    {
        $pool = $this->makePool();
        $this->assertSame('`users`', $pool->quoteIdentifier('users'));
        $this->assertSame('`user_orders`', $pool->quoteIdentifier('user_orders'));
        $this->assertSame('`_underscore_first`', $pool->quoteIdentifier('_underscore_first'));
        $this->assertSame('`col1`', $pool->quoteIdentifier('col1'));
        $this->assertSame('`ABC123`', $pool->quoteIdentifier('ABC123'));
    }

    public function testQuoteIdentifier_AcceptsMinimumLength()
    {
        $pool = $this->makePool();
        $this->assertSame('`a`', $pool->quoteIdentifier('a'));
        $this->assertSame('`_`', $pool->quoteIdentifier('_'));
    }

    // ========== quoteIdentifier 非法输入（拒绝表名/列名注入）==========

    public function testQuoteIdentifier_RejectsSemicolonDashDash()
    {
        $pool = $this->makePool();
        $this->expectException(\InvalidArgumentException::class);
        $pool->quoteIdentifier('users;--');
    }

    public function testQuoteIdentifier_RejectsSpace()
    {
        $pool = $this->makePool();
        $this->expectException(\InvalidArgumentException::class);
        $pool->quoteIdentifier('users table');
    }

    public function testQuoteIdentifier_RejectsBacktick()
    {
        $pool = $this->makePool();
        $this->expectException(\InvalidArgumentException::class);
        $pool->quoteIdentifier('user`s');
    }

    public function testQuoteIdentifier_RejectsEmptyString()
    {
        $pool = $this->makePool();
        $this->expectException(\InvalidArgumentException::class);
        $pool->quoteIdentifier('');
    }

    public function testQuoteIdentifier_RejectsNumberFirst()
    {
        // MySQL 标识符不能以数字开头
        $pool = $this->makePool();
        $this->expectException(\InvalidArgumentException::class);
        $pool->quoteIdentifier('1table');
    }

    public function testQuoteIdentifier_RejectsHyphen()
    {
        $pool = $this->makePool();
        $this->expectException(\InvalidArgumentException::class);
        $pool->quoteIdentifier('user-orders');
    }

    public function testQuoteIdentifier_RejectsDot()
    {
        // 防止 database.table 形式注入
        $pool = $this->makePool();
        $this->expectException(\InvalidArgumentException::class);
        $pool->quoteIdentifier('other_db.users');
    }

    public function testQuoteIdentifier_RejectsStar()
    {
        $pool = $this->makePool();
        $this->expectException(\InvalidArgumentException::class);
        $pool->quoteIdentifier('*');
    }

    public function testQuoteIdentifier_RejectsParenthesis()
    {
        // 防 IF() 等函数注入
        $pool = $this->makePool();
        $this->expectException(\InvalidArgumentException::class);
        $pool->quoteIdentifier('IF(1=1)');
    }

    public function testQuoteIdentifier_RejectsSqlKeywordsWithSpaces()
    {
        $pool = $this->makePool();
        $this->expectException(\InvalidArgumentException::class);
        $pool->quoteIdentifier('users WHERE 1=1');
    }

    // ========== 异常消息 ==========

    public function testQuoteIdentifier_ExceptionMessageContainsName()
    {
        $pool = $this->makePool();
        try {
            $pool->quoteIdentifier('users;--');
            $this->fail('Expected InvalidArgumentException was not thrown');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('users;--', $e->getMessage());
            $this->assertStringContainsString('Invalid SQL identifier', $e->getMessage());
        }
    }
}
