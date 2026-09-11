<?php

namespace Sikelan\Tests\trader_test;

use App\Services\Trader\BacktestServiceProvider;
use App\Services\Trader\Market\ArrayDataProvider;
use App\Services\Trader\Market\KlinesCsvWriter;
use PHPUnit\Framework\TestCase;

/**
 * BacktestServiceProvider::loadDataProvider 极简入口测试。
 *
 * 覆盖：文件存在直接加载 / 文件不存在无 downloadOptions 抛异常且含下载指引 / 文件不存在
 * + downloadOptions 时自动调 download（用 dry-run 避免真下交易所数据）。
 */
class LoadDataProviderTest extends TestCase
{
    /** @var string 临时目录（用于造假 CSV）*/
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/whale_ldp_' . bin2hex(random_bytes(4));
        // 临时 override RUNTIME_PATH 常量所在目录 —— 用 putenv 让 loadDataProvider 走到 fallback path
        // 不过更简单的方式：直接造一个真实的 CSV 用 loadDataProvider 的 data_dir fallback 来测
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->cleanDir($this->tmpDir);
    }

    private function cleanDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getRealPath()) : unlink($f->getRealPath());
        }
        rmdir($dir);
    }

    // ------------------------------------------------------------------------
    //  1. 文件存在 → 直接加载返回 ArrayDataProvider
    // ------------------------------------------------------------------------

    /** 用 runtime/trader/data/binance/BTC-USDT_1h.csv 这个真实存在的文件。 */
    public function testLoadFromExistingCsvReturnsProvider(): void
    {
        $csvPath = RUNTIME_PATH . '/trader/data/binance/BTC-USDT_1h.csv';
        if (!is_file($csvPath)) {
            $this->markTestSkipped("真实 CSV 不存在 {$csvPath}，跳过");
        }

        $dp = BacktestServiceProvider::loadDataProvider('binance', 'BTC/USDT', '1h');

        $this->assertInstanceOf(ArrayDataProvider::class, $dp);

        // 有数据
        $candles = $dp->getCandles(\App\Services\Exchanges\TradingSymbol::parse('BTC/USDT'), '1h');
        $this->assertNotEmpty($candles);
        $this->assertGreaterThan(0, count($candles));
    }

    // ------------------------------------------------------------------------
    //  2. 文件不存在 + 无 downloadOptions → 抛异常 + 含清晰提示
    // ------------------------------------------------------------------------

    public function testMissingFileThrowsWithManualDownloadHint(): void
    {
        // BTC/USDT:SWAP 1w 在 sample 里肯定不存在（我们没下过 1w）
        try {
            BacktestServiceProvider::loadDataProvider('binance', 'BTC/USDT:SWAP', '1w');
            $this->fail('期望 RuntimeException，未抛');
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            // 关键断言：
            $this->assertStringContainsString('CSV 不存在', $msg);
            $this->assertStringContainsString('binance', $msg);
            $this->assertStringContainsString('BTC/USDT:SWAP', $msg);
            $this->assertStringContainsString('1w', $msg);
            $this->assertStringContainsString('trader:download-klines', $msg);
            $this->assertStringContainsString('downloadOptions', $msg);
        }
    }

    // ------------------------------------------------------------------------
    //  3. 文件不存在 + downloadOptions → 走自动下载分支（不带 dry-run）
    //     这个场景里我们用一个不存在的 pair + days 7，预期走到 downloadKlines
    //     方法（会输出"CSV 不存在，自动下载 ..."的日志），然后因为 pair 确实不存在
    //     下载失败，抛异常时消息里应该包含"自动下载 K 线失败"字样。
    // ------------------------------------------------------------------------

    public function testNonDryRunDownloadOptionsTriggersAutoDownloadBranch(): void
    {
        // 用一个肯定不存在的 pair（THIS_PAIR_DOES_NOT_EXIST_ABC/XYZ），但带了 days 选项
        // 让它走到 downloadKlines 分支
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/自动下载/');

        BacktestServiceProvider::loadDataProvider(
            'binance',
            'THIS_PAIR_DOES_NOT_EXIST_ABC/XYZ',
            '1h',
            ['days' => 1]
        );
    }

    // ------------------------------------------------------------------------
    //  4. 文件路径拼装正确性：给定 exchange/symbol/timeframe 能算出正确的 CSV path
    //     （这个是 loadDataProvider 内部逻辑的纯函数，抽出来便于测试）
    // ------------------------------------------------------------------------

    /** 用已有的文件反查：确认 buildFilename 和 dataDir 路径拼出来确实指向实际存在文件。 */
    public function testFilePathCompositionMatchesActualFile(): void
    {
        $csvPath = RUNTIME_PATH . '/trader/data/binance/BTC-USDT_1h.csv';
        if (!is_file($csvPath)) {
            $this->markTestSkipped("真实 CSV 不存在，跳过");
        }

        $filename = (new KlinesCsvWriter())->buildFilename('BTC/USDT', '1h');
        $this->assertSame('BTC-USDT_1h.csv', $filename);

        $expected = RUNTIME_PATH . '/trader/data/binance/' . $filename;
        $this->assertSame($csvPath, $expected);
        $this->assertTrue(is_file($expected));
    }

    // ------------------------------------------------------------------------
    //  5. loadDataProviderBatch：多交易对全部 CSV 存在 → 成功塞到同一个 DataProvider
    // ------------------------------------------------------------------------

    public function testBatchAllExistingPairsReturnSingleProviderWithMultipleSymbols(): void
    {
        // runtime/trader/data/binance/ 下 1h 的 CSV：BTC-USDT / ETH-USDT / BTC-USDT-SWAP
        $csvBase = RUNTIME_PATH . '/trader/data/binance/';
        foreach (['BTC-USDT_1h.csv', 'ETH-USDT_1h.csv', 'BTC-USDT-SWAP_1h.csv'] as $f) {
            if (!is_file($csvBase . $f)) {
                $this->markTestSkipped("批量测试需要 3 个真实 CSV，缺少 {$f}");
            }
        }

        $dp = BacktestServiceProvider::loadDataProviderBatch('binance', '1h', [
            ['symbol' => 'BTC/USDT'],
            ['symbol' => 'ETH/USDT'],
            ['symbol' => 'BTC/USDT:SWAP'],
        ]);

        $this->assertInstanceOf(ArrayDataProvider::class, $dp);

        // 确认三个 symbol 都在同一个 provider 里
        $symbols = $dp->getAvailableSymbols();
        $this->assertCount(3, $symbols);

        // 每个都能读到非空数据
        foreach (['BTC/USDT', 'ETH/USDT', 'BTC/USDT:SWAP'] as $s) {
            $candles = $dp->getCandles(\App\Services\Exchanges\TradingSymbol::parse($s), '1h');
            $this->assertNotEmpty($candles, "{$s} 应该有数据");
        }
    }

    // ------------------------------------------------------------------------
    //  6. loadDataProviderBatch：部分 pair 不存在 → 报批量错误（含完成数 + 每个 pair 错误）
    // ------------------------------------------------------------------------

    public function testBatchPartialMissingThrowsWithCompletionSummary(): void
    {
        // BTC/USDT 存在，THIS_PAIR_DOES_NOT_EXIST 不存在
        try {
            BacktestServiceProvider::loadDataProviderBatch('binance', '1h', [
                ['symbol' => 'BTC/USDT'],
                ['symbol' => 'THIS_PAIR_DOES_NOT_EXIST/NOTHING'],
            ]);
            $this->fail('期望 RuntimeException，未抛');
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            // 必须包含批量错误特征
            $this->assertStringContainsString('[loadDataProviderBatch]', $msg);
            // 必须包含"完成 X/N"
            $this->assertStringContainsString('完成 1/2', $msg);
            // 必须包含失败 pair 名称
            $this->assertStringContainsString('THIS_PAIR_DOES_NOT_EXIST/NOTHING', $msg);
        }
    }

    // ------------------------------------------------------------------------
    //  7. loadDataProviderBatch：pairs 配置里空 symbol → 跳过 + 计入错误
    // ------------------------------------------------------------------------

    public function testBatchWithEmptySymbolSkipsAndReports(): void
    {
        try {
            BacktestServiceProvider::loadDataProviderBatch('binance', '1h', [
                ['symbol' => 'BTC/USDT'],     // 存在
                ['symbol' => ''],              // 空 → 配置错误
                ['bogus'  => 'xxx'],           // 无 symbol 键
            ]);
            $this->fail('期望 RuntimeException，未抛');
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            $this->assertStringContainsString('[loadDataProviderBatch]', $msg);
            $this->assertStringContainsString('pairs[1]', $msg); // 空 symbol
            $this->assertStringContainsString('symbol 必须是非空字符串', $msg);
        }
    }

    // ------------------------------------------------------------------------
    //  8. loadDataProviderBatch：pair 级 download 覆盖全局
    //     用 dry-run 让不存在的 pair 走"提示分支"而不真去下
    // ------------------------------------------------------------------------

    public function testBatchPairLevelDownloadOverrideGlobal(): void
    {
        try {
            BacktestServiceProvider::loadDataProviderBatch('binance', '1h', [
                ['symbol' => 'BTC/USDT'],                                          // 存在 → ok
                ['symbol' => 'THIS_NEVER_EXISTS/XYZ', 'download' => ['dry_run' => true]],  // pair 级 dry-run
            ]);
            $this->fail('期望 RuntimeException');
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            // 应该是"CSV 不存在"（因为 dry-run 禁止自动下载），而不是下载失败
            $this->assertStringContainsString('CSV 不存在', $msg);
        }
    }
}
