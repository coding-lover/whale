<?php

namespace Sikelan\Tests\trader_test;

use App\Services\Trader\Market\Candle;
use App\Services\Trader\Market\KlinesCsvReader;
use App\Services\Trader\Market\KlinesCsvWriter;
use PHPUnit\Framework\TestCase;

/**
 * KlinesCsvReader —— KlinesCsvWriter 的对称读工具测试。
 *
 * 覆盖：读正常 CSV / 读空文件 / 文件不存在异常 / 列数不足异常 / timestamp 非整数异常 / 自动去重 & 升序。
 */
class KlinesCsvReaderTest extends TestCase
{
    /** @var string 临时目录（setUp 创建, tearDown 清理）*/
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/whale_csv_reader_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        // 递归清理临时目录
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmpDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }
        rmdir($this->tmpDir);
    }

    // ------------------------------------------------------------------------
    //  核心场景
    // ------------------------------------------------------------------------

    /**
     * 正常 CSV：Writer 写出 → Reader 读回，Candle 数组逐根相等。
     */
    public function testReadNormalCsvRoundTrip(): void
    {
        // 造 5 根 K 线
        $nowMs = (int) (microtime(true) * 1000);
        $ts0 = $nowMs - 5_000;
        $source = [
            [$ts0,     100.0, 105.0, 99.0,  102.0, 10.5],
            [$ts0 + 1000, 102.0, 106.0, 101.0, 104.0, 11.2],
            [$ts0 + 2000, 104.0, 108.0, 103.5, 107.0,  9.8],
            [$ts0 + 3000, 107.0, 110.0, 104.5, 105.0, 15.0],
            [$ts0 + 4000, 105.0, 109.5, 104.0, 108.0, 12.3],
        ];

        [$path, $written] = (new KlinesCsvWriter())
            ->write($this->tmpDir, 'binance', 'BTC/USDT', '1m', $source);
        $this->assertCount(5, $source);
        $this->assertSame($path, $this->tmpDir . '/binance/BTC-USDT_1m.csv');

        // Reader 读回
        $candles = (new KlinesCsvReader())->read($path);

        // 逐根校验
        $this->assertCount(5, $candles);
        foreach ($candles as $i => $c) {
            $this->assertInstanceOf(Candle::class, $c);
            $this->assertSame($source[$i][0], $c->getTimestamp());
            $this->assertEquals($source[$i][1], $c->getOpen());
            $this->assertEquals($source[$i][2], $c->getHigh());
            $this->assertEquals($source[$i][3], $c->getLow());
            $this->assertEquals($source[$i][4], $c->getClose());
            $this->assertEquals($source[$i][5], $c->getVolume());
        }
    }

    /**
     * Reader 自动按 timestamp 升序 + 去重：手工打乱/重复的 CSV 行会被纠正。
     */
    public function testReadAutoSortAndDedupe(): void
    {
        // 构造 CSV 内容：顺序打乱 + 有重复 ts（保留最后一行）
        $csv = "timestamp,open,high,low,close,volume\n"
            . "3000,103,105,102,104,8\n"          // 第 3 根（ts=3000）
            . "1000,101,103,100,102,5\n"          // 第 1 根（ts=1000）
            . "2000,102,104,101,103,6\n"          // 第 2 根（ts=2000）
            . "1000,101,103,100,102.5,5.5\n"      // ts=1000 重复 → 应覆盖上面的（保留这行）
            . "5000,106,108,105,107,10\n"         // 第 5 根
            . "4000,105,107,104,106,9\n";         // 第 4 根

        $path = $this->tmpDir . '/binance/BTC-USDT_1h.csv';
        mkdir(dirname($path), 0755, true);
        file_put_contents($path, $csv);

        $candles = (new KlinesCsvReader())->read($path);

        // 应该 5 根（ts=1000 的重复行被覆盖后只保留一份）
        $this->assertCount(5, $candles);
        $this->assertSame(1000, $candles[0]->getTimestamp());
        $this->assertSame(2000, $candles[1]->getTimestamp());
        $this->assertSame(3000, $candles[2]->getTimestamp());
        $this->assertSame(4000, $candles[3]->getTimestamp());
        $this->assertSame(5000, $candles[4]->getTimestamp());
        // 验证 ts=1000 的那行确实是"后写入"的 close=102.5（覆盖了原始 102）
        $this->assertEquals(102.5, $candles[0]->getClose());
    }

    /**
     * 空 CSV（只有表头）→ 返回空数组，不报错。
     */
    public function testReadCsvWithOnlyHeaderReturnsEmptyArray(): void
    {
        $path = $this->tmpDir . '/empty.csv';
        file_put_contents($path, "timestamp,open,high,low,close,volume\n");
        $candles = (new KlinesCsvReader())->read($path);
        $this->assertSame([], $candles);
    }

    // ------------------------------------------------------------------------
    //  异常场景
    // ------------------------------------------------------------------------

    /** 文件不存在 → RuntimeException。 */
    public function testReadNonexistentFileThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        (new KlinesCsvReader())->read($this->tmpDir . '/no_such_file.csv');
    }

    /** 行列数不足 6 → InvalidArgumentException。 */
    public function testReadCsvWithInsufficientColumnsThrows(): void
    {
        $path = $this->tmpDir . '/bad.csv';
        file_put_contents($path, "timestamp,open,high\n1000,100,101\n");
        $this->expectException(\InvalidArgumentException::class);
        (new KlinesCsvReader())->read($path);
    }

    /** timestamp 非整数 → InvalidArgumentException。 */
    public function testReadCsvWithNonIntegerTimestampThrows(): void
    {
        $path = $this->tmpDir . '/bad.csv';
        file_put_contents($path, "timestamp,open,high,low,close,volume\nabc,100,101,99,100.5,5\n");
        $this->expectException(\InvalidArgumentException::class);
        (new KlinesCsvReader())->read($path);
    }
}
