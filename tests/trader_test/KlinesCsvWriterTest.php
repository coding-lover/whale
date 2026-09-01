<?php

namespace Sikelan\Tests\trader_test;

use App\Services\Trader\Market\KlinesCsvWriter;
use PHPUnit\Framework\TestCase;

/**
 * KlinesCsvWriter 单元测试（纯 IO/格式校验，不需要交易所服务）。
 *
 * 归属：KlinesCsvWriter 在 app/Services/Trader/Market → 按项目约定"应用层 app/ 放 tests/stest/ 的
 * 『stest 用于框架核心』？—— 不，再看约定：
 *   项目规则 §2：tests/stest 框架核心 sikelan/；tests/atest 应用层 app/；
 *   但 KlinesCsvWriter 是 Trader 子模块……再看规则 §4 目录归属 §2 细文：
 *   『tests/trader_test/ → App\Services\Trader + App\Services\Exchanges』
 *   → 本文件迁到 tests/trader_test/，命名空间 Sikelan\Tests\trader_test。
 * —— 因此把本用例放入 tests/trader_test/。
 * （本声明写在类上方：IDE/CI 不会报错，只是提醒未来维护者。）
 *
 * 实际文件位置：tests/trader_test/  （我们在 Write 时迁过去）
 *
 * @package Sikelan\Tests\trader_test
 */
class KlinesCsvWriterTest extends TestCase
{
    /** @var string 临时目录，tearDown 会清空 */
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $base = sys_get_temp_dir() . '/sikelan_klines_' . uniqid((string) mt_rand(), true);
        mkdir($base, 0755, true);
        $this->tmpDir = $base;
    }

    protected function tearDown(): void
    {
        // 递归删除临时目录
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmpDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $fileinfo) {
            if ($fileinfo->isDir()) {
                rmdir((string) $fileinfo->getRealPath());
            } else {
                unlink((string) $fileinfo->getRealPath());
            }
        }
        rmdir($this->tmpDir);
        parent::tearDown();
    }

    // ---- buildFilename 安全文件名 ----

    public function testBuildFilenameReplacesSlashesAndColonsForSpot(): void
    {
        $w = new KlinesCsvWriter();
        $this->assertSame('BTC-USDT_1h.csv', $w->buildFilename('BTC/USDT', '1h'));
    }

    public function testBuildFilenameForSwapRemovesColon(): void
    {
        $w = new KlinesCsvWriter();
        $this->assertSame('BTC-USDT-SWAP_5m.csv', $w->buildFilename('BTC/USDT:SWAP', '5m'));
    }

    public function testBuildFilenameForExplicitFuturesDate(): void
    {
        $w = new KlinesCsvWriter();
        $this->assertSame('BTC-USDT-FUT-250627_1d.csv', $w->buildFilename('BTC/USDT:FUT-250627', '1d'));
    }

    // ---- 主 write()：基本写入 + 校验 ----

    public function testWriteProducesExpectedCsvHeaderAndRows(): void
    {
        $w = new KlinesCsvWriter();
        $rows = [
            [1_700_000_000_000, 100.0, 101.5, 99.0, 100.5, 50.25],
            [1_700_003_600_000, 100.5, 102.0, 100.1, 101.8, 60.00],
        ];
        [$path, $written] = $w->write($this->tmpDir, 'binance', 'BTC/USDT', '1h', $rows);

        // 路径 = <baseDir>/<exchange>/<safeSymbol>_<interval>.csv
        $this->assertSame($this->tmpDir . '/binance/BTC-USDT_1h.csv', $path);
        $this->assertSame(2, $written);
        $this->assertFileExists($path);

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        $this->assertIsArray($lines);
        $this->assertCount(3, $lines); // header + 2 数据行

        // 表头必须严格 6 列
        $this->assertSame(
            ['timestamp', 'open', 'high', 'low', 'close', 'volume'],
            str_getcsv($lines[0])
        );

        // 第二行解析：数值精度不得损失为科学计数
        $row = str_getcsv($lines[1]);
        $this->assertSame('1700000000000', $row[0]);
        $this->assertEqualsWithDelta(100.0, (float) $row[1], 1e-10);
        $this->assertEqualsWithDelta(101.5, (float) $row[2], 1e-10);
        $this->assertEqualsWithDelta(99.0,  (float) $row[3], 1e-10);
        $this->assertEqualsWithDelta(100.5, (float) $row[4], 1e-10);
        $this->assertEqualsWithDelta(50.25, (float) $row[5], 1e-10);
    }

    // ---- 时间去重 & 升序 ----

    public function testWriteDeduplicatesTimestampAndSortsAscending(): void
    {
        $w = new KlinesCsvWriter();
        // 故意乱序 + 重复 ts=2（最后写入的 row 覆盖）
        $rows = [
            [3000, 3, 3, 3, 3, 3],
            [1000, 1, 1, 1, 1, 1],
            [2000, 2, 2, 2, 2, 2], // 被下一行覆盖
            [2000, 9, 9, 9, 9, 9], // ts=2 的最终值（9）
        ];
        [$path, $written] = $w->write($this->tmpDir, 'okx', 'BTC/USDT:SWAP', '4h', $rows);
        $this->assertSame(3, $written);
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        $this->assertIsArray($lines);

        // 解析数据部分 3 行的 ts
        $ts = [];
        foreach (array_slice($lines, 1) as $line) {
            $ts[] = (int) str_getcsv($line)[0];
        }
        $sorted = $ts;
        sort($sorted, SORT_NUMERIC);
        $this->assertSame($sorted, $ts, '时间戳应升序');
        $this->assertSame([1000, 2000, 3000], $ts, '去重：2000 只保留 1 行');
        // ts=2000 对应 open=9（最后一次覆盖）
        $middleOpen = (float) str_getcsv($lines[2])[1];
        $this->assertEqualsWithDelta(9.0, $middleOpen, 1e-10);
    }

    // ---- 错误输入抛 InvalidArgumentException ----

    public function testWriteRejectsRowTooShort(): void
    {
        $w = new KlinesCsvWriter();
        $this->expectException(\InvalidArgumentException::class);
        $w->write($this->tmpDir, 'binance', 'X', '1m', [[1, 2, 3]]);   // 仅 3 列
    }

    public function testWriteRejectsNonIntegerTimestamp(): void
    {
        $w = new KlinesCsvWriter();
        $this->expectException(\InvalidArgumentException::class);
        $w->write($this->tmpDir, 'binance', 'X', '1m', [['not_a_number', 1, 2, 3, 4, 5]]);
    }

    public function testWriteRejectsNonNumericPrice(): void
    {
        $w = new KlinesCsvWriter();
        $this->expectException(\InvalidArgumentException::class);
        $w->write($this->tmpDir, 'binance', 'X', '1m', [[1000, 'oops', 2, 3, 4, 5]]);
    }

    public function testWriteRejectsEmptyExchangeSymbolOrInterval(): void
    {
        $w = new KlinesCsvWriter();
        $this->expectException(\InvalidArgumentException::class);
        $w->write($this->tmpDir, '', 'BTC/USDT', '1h', []);
    }

    // ---- 输出目录自动递归创建 ----

    public function testWriteCreatesExchangeSubdirectory(): void
    {
        $w = new KlinesCsvWriter();
        $deep = $this->tmpDir . '/a/b/c';
        [$path] = $w->write($deep, 'okx', 'ETH/BTC', '1w', [[1_000, 1, 2, 0.5, 1.5, 0.1]]);
        $this->assertFileExists($path);
        $this->assertStringStartsWith($deep . '/okx/', $path);
    }

    // ---- 整数价（不带小数点）和大浮点（反科学计数法）----

    public function testNumberFormattingAvoidsScientificNotation(): void
    {
        $w = new KlinesCsvWriter();
        $w->write($this->tmpDir, 'binance', 'X/USDT', '1m', [[
            1_700_000_000_000,
            0.00000012,   // 很小；CSV 常见 bug：写成 1.2E-7
            0.00001,
            0.00000009,
            123456789.5,   // 很大；常见 bug：1.234567895E+8（虽然大整数 OK 但我们也防）
            5,
        ]]);
        $lines = file($this->tmpDir . '/binance/X-USDT_1m.csv', FILE_IGNORE_NEW_LINES);
        $this->assertIsArray($lines);
        $row = str_getcsv($lines[1]);

        // 不允许出现 'E' 或 'e'（科学计数法）
        foreach (array_slice($row, 1) as $col) {
            $this->assertDoesNotMatchRegularExpression('/[eE]/', (string) $col);
        }
    }
}
