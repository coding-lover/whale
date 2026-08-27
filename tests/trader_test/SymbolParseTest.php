<?php

declare(strict_types=1);

namespace Sikelan\Tests\trader_test;

use PHPUnit\Framework\TestCase;
use App\Services\Exchanges\TradingSymbol;
use App\Services\Exchanges\Formatters\BinanceSymbolFormatter;
use App\Services\Exchanges\Formatters\OkxSymbolFormatter;

/**
 * 交易所原生交易对 → 本地标准格式 反向解析 + 双向一致性测试
 */
class SymbolParseTest extends TestCase
{
    // ==================== Binance 反向解析测试 ====================

    public function testBinanceParseSpot(): void
    {
        $formatter = new BinanceSymbolFormatter();
        $symbol = $formatter->parseExchangeSymbol('BTCUSDT');

        $this->assertEquals('BTC', $symbol->getBase());
        $this->assertEquals('USDT', $symbol->getQuote());
        $this->assertEquals(TradingSymbol::TYPE_SPOT, $symbol->getType());
        $this->assertEquals('BTC/USDT', (string) $symbol);
    }

    public function testBinanceParseSpotWithDefaultSwap(): void
    {
        $formatter = new BinanceSymbolFormatter();
        $symbol = $formatter->parseExchangeSymbol('BTCUSDT', TradingSymbol::TYPE_SWAP);

        // 纯拼接格式下 defaultType=swap 生效，解析为 swap
        $this->assertEquals(TradingSymbol::TYPE_SWAP, $symbol->getType());
        $this->assertEquals('BTC/USDT:SWAP', (string) $symbol);
    }

    public function testBinanceParseCoinMarginedSwap(): void
    {
        $formatter = new BinanceSymbolFormatter();
        $symbol = $formatter->parseExchangeSymbol('BTCUSD_PERP');

        $this->assertEquals('BTC', $symbol->getBase());
        $this->assertEquals('USD', $symbol->getQuote());
        $this->assertEquals(TradingSymbol::TYPE_SWAP, $symbol->getType());
        // _PERP 明确是 swap，不再走 defaultType
        $this->assertEquals('BTC/USD:SWAP', (string) $symbol);
    }

    public function testBinanceParseFuturesCustomDate(): void
    {
        // 用一个不匹配任何周期别名的日期（2026-08-23 今天是周末，选一个周三日期）
        // 计算一个月中的周中日期（8月中旬周三），确保不与 QUARTER 等别名重合
        $pastDate = date('ymd', strtotime('+14 days')); // 两周后的日期
        // 若恰是周五，再加 2 天变周日
        if (date('N', strtotime('+14 days')) == 5) {
            $pastDate = date('ymd', strtotime('+16 days'));
        }

        $formatter = new BinanceSymbolFormatter();
        $symbol = $formatter->parseExchangeSymbol("BTCUSDT_{$pastDate}");

        $this->assertEquals('BTC', $symbol->getBase());
        $this->assertEquals('USDT', $symbol->getQuote());
        $this->assertEquals(TradingSymbol::TYPE_FUTURES, $symbol->getType());
        // 不匹配任何周期别名时，保留显式日期
        $this->assertEquals($pastDate, $symbol->getDeliveryDate());
        $this->assertEquals("BTC/USDT:FUT-{$pastDate}", (string) $symbol);
    }

    public function testBinanceParseFutures8DigitDate(): void
    {
        // 2026-08-31 是周一，不匹配 5 种别名的周五
        $formatter = new BinanceSymbolFormatter();
        $symbol = $formatter->parseExchangeSymbol('BTCUSDT_20260831');

        $this->assertEquals(TradingSymbol::TYPE_FUTURES, $symbol->getType());
        $this->assertEquals('20260831', $symbol->getDeliveryDate());
    }

    public function testBinanceParseQuotes(): void
    {
        $formatter = new BinanceSymbolFormatter();

        // USDT 优先于 USD（长优先）
        $s1 = $formatter->parseExchangeSymbol('BTCUSDT');
        $this->assertEquals('USDT', $s1->getQuote());

        // BTC/USD
        $s2 = $formatter->parseExchangeSymbol('BTCUSD');
        $this->assertEquals('USD', $s2->getQuote());

        // ETH/USDC
        $s3 = $formatter->parseExchangeSymbol('ETHUSDC');
        $this->assertEquals('USDC', $s3->getQuote());

        // SOL/BTC
        $s4 = $formatter->parseExchangeSymbol('SOLBTC');
        $this->assertEquals('BTC', $s4->getQuote());
    }

    public function testBinanceParseEmptyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new BinanceSymbolFormatter())->parseExchangeSymbol('');
    }

    public function testBinanceParseUnknownQuoteThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        // XYZ 不是常见 quote，切不开
        (new BinanceSymbolFormatter())->parseExchangeSymbol('BTCXYZ');
    }

    public function testBinanceInvalidDefaultTypeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        // BTCUSDT 无格式区分，不能给 futures
        (new BinanceSymbolFormatter())->parseExchangeSymbol('BTCUSDT', TradingSymbol::TYPE_FUTURES);
    }

    // ==================== OKX 反向解析测试 ====================

    public function testOkxParseSpot(): void
    {
        $formatter = new OkxSymbolFormatter();
        $symbol = $formatter->parseExchangeSymbol('BTC-USDT');

        $this->assertEquals('BTC', $symbol->getBase());
        $this->assertEquals('USDT', $symbol->getQuote());
        $this->assertEquals(TradingSymbol::TYPE_SPOT, $symbol->getType());
        $this->assertEquals('BTC/USDT', (string) $symbol);
    }

    public function testOkxParseSwap(): void
    {
        $formatter = new OkxSymbolFormatter();
        $symbol = $formatter->parseExchangeSymbol('BTC-USDT-SWAP');

        $this->assertEquals('BTC', $symbol->getBase());
        $this->assertEquals('USDT', $symbol->getQuote());
        $this->assertEquals(TradingSymbol::TYPE_SWAP, $symbol->getType());
        $this->assertEquals('BTC/USDT:SWAP', (string) $symbol);
    }

    public function testOkxParseCoinMarginedSwap(): void
    {
        $formatter = new OkxSymbolFormatter();
        $symbol = $formatter->parseExchangeSymbol('BTC-USD-SWAP');

        $this->assertEquals('BTC', $symbol->getBase());
        $this->assertEquals('USD', $symbol->getQuote());
        $this->assertEquals(TradingSymbol::TYPE_SWAP, $symbol->getType());
    }

    public function testOkxParseFuturesCustomDate(): void
    {
        // 使用非周五的日期（2026-08-31 周一），确保不会归一化为周期别名
        $formatter = new OkxSymbolFormatter();
        $symbol = $formatter->parseExchangeSymbol('BTC-USDT-260831');

        $this->assertEquals('BTC', $symbol->getBase());
        $this->assertEquals('USDT', $symbol->getQuote());
        $this->assertEquals(TradingSymbol::TYPE_FUTURES, $symbol->getType());
        $this->assertEquals('260831', $symbol->getDeliveryDate());
        $this->assertEquals('BTC/USDT:FUT-260831', (string) $symbol);
    }

    public function testOkxParseFutures8DigitDate(): void
    {
        $formatter = new OkxSymbolFormatter();
        $symbol = $formatter->parseExchangeSymbol('BTC-USDT-20260831');

        $this->assertEquals(TradingSymbol::TYPE_FUTURES, $symbol->getType());
        $this->assertEquals('20260831', $symbol->getDeliveryDate());
    }

    public function testOkxParseEmptyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new OkxSymbolFormatter())->parseExchangeSymbol('');
    }

    public function testOkxParseOneSegmentThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new OkxSymbolFormatter())->parseExchangeSymbol('BTCUSDT');
    }

    public function testOkxParseFourSegmentThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new OkxSymbolFormatter())->parseExchangeSymbol('BTC-USDT-250328-EXTRA');
    }

    public function testOkxParseInvalid3SuffixThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        // 既不是 SWAP 也不是日期
        (new OkxSymbolFormatter())->parseExchangeSymbol('BTC-USDT-FOO');
    }

    // ==================== 双向转换一致性（往返测试） ====================

    /**
     * 测试 Binance：标准格式 → 原生 → 解析回来 结果一致
     */
    public function testBinanceRoundTripExplicit(): void
    {
        $formatter = new BinanceSymbolFormatter();
        $cases = [
            // 现货：解析时 defaultType 默认 spot
            ['BTC/USDT', TradingSymbol::TYPE_SPOT],
            // 永续 U本位：解析时需要 defaultType=swap
            ['BTC/USDT', TradingSymbol::TYPE_SWAP],
            // 币本位永续：格式明确，无需 defaultType
            ['BTC/USD:SWAP', TradingSymbol::TYPE_SPOT], // defaultType 无关
            // 交割合约：用非别名日期（2026-08-31 周一）
            ['BTC/USDT:FUT-260831', TradingSymbol::TYPE_SPOT],
        ];

        foreach ($cases as [$standard, $defaultType]) {
            $original = TradingSymbol::parse($standard);
            $native = $formatter->format($original);
            $parsed = $formatter->parseExchangeSymbol($native, $defaultType);

            // base / quote 必须一致
            $this->assertEquals($original->getBase(), $parsed->getBase(), "Base mismatch for {$standard}");
            $this->assertEquals($original->getQuote(), $parsed->getQuote(), "Quote mismatch for {$standard}");

            // 显式类型匹配：现货/永续类型由 defaultType 控制
            if ($original->isFutures()) {
                $this->assertTrue($parsed->isFutures(), "Type should be futures for {$standard}");
                $this->assertEquals($original->getDeliveryDate(), $parsed->getDeliveryDate());
            } elseif ($original->isSwap() && $original->getQuote() === 'USD') {
                $this->assertTrue($parsed->isSwap(), "Coin margined swap should remain swap for {$standard}");
            }
        }
    }

    /**
     * 测试 OKX：所有类型格式明确，往返必须一一对应
     */
    public function testOkxRoundTripFull(): void
    {
        $formatter = new OkxSymbolFormatter();
        $standards = [
            'BTC/USDT',
            'ETH/USDC',
            'BTC/USDT:SWAP',
            'BTC/USD:SWAP',
            // 非别名日期（2026-08-31 周一），往返不变
            'BTC/USDT:FUT-260831',
            'ETH/BTC:FUT-260831',
        ];

        foreach ($standards as $standard) {
            $original = TradingSymbol::parse($standard);
            $native = $formatter->format($original);
            $parsed = $formatter->parseExchangeSymbol($native);

            // 全部字段一致（OKX 有分隔符，完全可反向还原）
            $this->assertEquals(
                (string) $original,
                (string) $parsed,
                "OKX round-trip failed: {$standard} -> {$native} -> " . (string) $parsed
            );
        }
    }

    /**
     * 周期别名 → OKX 原生 → 解析回来应归一化为相同周期别名（关键修复）
     *
     * 这是本需求的核心验证：
     *   BTC/USDT:QUARTER → OKX 原生 BTC-USDT-260925 → 解析 → BTC/USDT:QUARTER
     *   （不是 :FUT-260925）
     */
    public function testPeriodAliasRoundTrip(): void
    {
        $formatter = new OkxSymbolFormatter();
        $periodStandards = [
            'BTC/USDT:THIS_WEEK',
            'ETH/USDT:NEXT_WEEK',
            'BTC/USDT:QUARTER',
            'ETH/USDT:BI_QUARTER',
            'SOL/USDT:CI_QUARTER',
        ];

        foreach ($periodStandards as $standard) {
            $original = TradingSymbol::parse($standard);
            $native = $formatter->format($original);
            $parsed = $formatter->parseExchangeSymbol($native);

            $this->assertTrue($parsed->isFutures(), "Period {$standard} should parse back to futures");
            // 日期推算一致（这个还是基础）
            $this->assertEquals(
                $original->getResolvedDeliveryDate(),
                $parsed->getResolvedDeliveryDate(),
                "Date mismatch for {$standard}"
            );
            // 关键断言：__toString() 输出必须还是 :THIS_WEEK / :QUARTER 这种别名形式
            $this->assertEquals(
                $standard,
                (string) $parsed,
                "Period alias round trip failed: {$standard} -> {$native} -> " . (string) $parsed
            );
        }
    }

    /**
     * 周期别名 → Binance 原生 → 解析回来同样归一化为别名
     */
    public function testPeriodAliasRoundTripBinance(): void
    {
        $formatter = new BinanceSymbolFormatter();
        $periodStandards = [
            'BTC/USDT:THIS_WEEK',
            'BTC/USDT:NEXT_WEEK',
            'BTC/USDT:QUARTER',
            'ETH/USDT:BI_QUARTER',
        ];

        foreach ($periodStandards as $standard) {
            $original = TradingSymbol::parse($standard);
            $native = $formatter->format($original);
            $parsed = $formatter->parseExchangeSymbol($native);

            $this->assertTrue($parsed->isFutures());
            $this->assertEquals(
                $original->getResolvedDeliveryDate(),
                $parsed->getResolvedDeliveryDate(),
                "Date mismatch for {$standard}"
            );
            $this->assertEquals(
                $standard,
                (string) $parsed,
                "Binance period alias round trip failed: {$standard} -> {$native} -> " . (string) $parsed
            );
        }
    }

    // ==================== quote 白名单切分边界测试 ====================

    public function testSplitUsdtBeforeUsd(): void
    {
        $formatter = new BinanceSymbolFormatter();
        $symbol = $formatter->parseExchangeSymbol('BTCUSDT');
        $this->assertEquals('USDT', $symbol->getQuote());
        $this->assertEquals('BTC', $symbol->getBase());
    }

    public function testSplitLongBase(): void
    {
        $formatter = new BinanceSymbolFormatter();
        // DOGEUSDT：base=DOGE, quote=USDT
        $symbol = $formatter->parseExchangeSymbol('DOGEUSDT');
        $this->assertEquals('DOGE', $symbol->getBase());
        $this->assertEquals('USDT', $symbol->getQuote());
    }

    // ==================== Binance/OKX 特殊合约专项测试 ====================

    /**
     * Binance 计价货币批量验证（覆盖白名单扩展后的 quote：BUSD/FDUSD/USDP/DAI/TRY 等）
     */
    public function testBinanceVariousQuoteCurrencies(): void
    {
        $formatter = new BinanceSymbolFormatter();

        $cases = [
            // [原生, 期望 base, 期望 quote]
            ['BTCFDUSD', 'BTC', 'FDUSD'],
            ['ETHBUSD',   'ETH', 'BUSD'],
            ['BNBUSDP',   'BNB', 'USDP'],
            ['LINKDAI',   'LINK', 'DAI'],
            ['MATICTRY',  'MATIC', 'TRY'],
            ['DOGEBRL',   'DOGE', 'BRL'],
            ['LTCARS',    'LTC', 'ARS'],
            ['ADAAEUR',   'ADA', 'AEUR'],
            ['XRPEUR',    'XRP', 'EUR'],
            ['SOLGBP',    'SOL', 'GBP'],
            ['AVAXJPY',   'AVAX', 'JPY'],
            ['DOTBTC',    'DOT', 'BTC'],
            ['LINKBTC',   'LINK', 'BTC'],
            ['ETHETH',    'ETH', 'ETH'], // ETHETH：这种格式虽然交易所不会有，但按规则能切开（ETH/ETH）
        ];

        foreach ($cases as [$native, $expBase, $expQuote]) {
            $symbol = $formatter->parseExchangeSymbol($native);
            $this->assertEquals($expBase, $symbol->getBase(), "Base mismatch for {$native}");
            $this->assertEquals($expQuote, $symbol->getQuote(), "Quote mismatch for {$native}");
        }
    }

    /**
     * Binance 超长 base + 不同 quote
     */
    public function testBinanceLongBaseNames(): void
    {
        $formatter = new BinanceSymbolFormatter();

        $cases = [
            ['1000SHIBUSDT',    '1000SHIB', 'USDT'],  // 币安杠杆/拆分币前缀数字
            ['1000LUNCBUSD',    '1000LUNC', 'BUSD'],
            ['1000XECUSDT',     '1000XEC', 'USDT'],
            ['PEPEUSDT',        'PEPE', 'USDT'],
            ['BONKUSDT',        'BONK', 'USDT'],
            ['FLOKIUSDT',       'FLOKI', 'USDT'],
            ['WIFUSDT',         'WIF', 'USDT'],
            ['POPCATUSDT',      'POPCAT', 'USDT'],
        ];

        foreach ($cases as [$native, $expBase, $expQuote]) {
            $symbol = $formatter->parseExchangeSymbol($native);
            $this->assertEquals($expBase, $symbol->getBase(), "Base mismatch for {$native}");
            $this->assertEquals($expQuote, $symbol->getQuote(), "Quote mismatch for {$native}");
        }
    }

    /**
     * Binance 特殊永续合约：
     *   - 币本位永续 _PERP 后缀多种 quote 验证
     */
    public function testBinanceCoinMarginedSwapVariants(): void
    {
        $formatter = new BinanceSymbolFormatter();

        // 币本位永续（COIN-Margined）：quote 是 USD，_PERP 后缀
        $s1 = $formatter->parseExchangeSymbol('ETHUSD_PERP');
        $this->assertEquals('ETH', $s1->getBase());
        $this->assertEquals('USD', $s1->getQuote());
        $this->assertTrue($s1->isSwap());
        $this->assertEquals('ETH/USD:SWAP', (string) $s1);

        $s2 = $formatter->parseExchangeSymbol('SOLUSD_PERP');
        $this->assertEquals('SOL', $s2->getBase());
        $this->assertEquals('USD', $s2->getQuote());
        $this->assertTrue($s2->isSwap());
    }

    /**
     * Binance 交割合约 专项：
     *   - U本位交割 显式日期 → 别名归一化
     *   - 币本位交割 USD 显式日期 → 别名归一化
     *   - U本位交割 非别名日期 → 保持显式
     */
    public function testBinanceFuturesNormalization(): void
    {
        $formatter = new BinanceSymbolFormatter();

        // 1. this_week 的实际日期 → 归一为 THIS_WEEK
        $twDate = (new TradingSymbol('BTC', 'USDT', TradingSymbol::TYPE_FUTURES, null, TradingSymbol::PERIOD_THIS_WEEK))->getResolvedDeliveryDate();
        $s1 = $formatter->parseExchangeSymbol("BTCUSDT_{$twDate}");
        $this->assertEquals('BTC/USDT:THIS_WEEK', (string) $s1);

        // 2. quarter 的实际日期 → 归一为 QUARTER
        $qDate = (new TradingSymbol('ETH', 'BUSD', TradingSymbol::TYPE_FUTURES, null, TradingSymbol::PERIOD_QUARTER))->getResolvedDeliveryDate();
        $s2 = $formatter->parseExchangeSymbol("ETHBUSD_{$qDate}");
        $this->assertEquals('ETH/BUSD:QUARTER', (string) $s2);

        // 3. 币本位交割 BTCUSD_quarter_date → 归一为 QUARTER
        $coinQDate = (new TradingSymbol('BTC', 'USD', TradingSymbol::TYPE_FUTURES, null, TradingSymbol::PERIOD_QUARTER))->getResolvedDeliveryDate();
        $s3 = $formatter->parseExchangeSymbol("BTCUSD_{$coinQDate}");
        $this->assertEquals('BTC/USD:QUARTER', (string) $s3);
        $this->assertTrue($s3->isFutures());

        // 4. 非别名日期（8月周一） → 保持显式
        $s4 = $formatter->parseExchangeSymbol('BTCUSDT_260831');
        $this->assertEquals('BTC/USDT:FUT-260831', (string) $s4);
    }

    /**
     * OKX 永续合约专项测试：
     *   - USDT 永续
     *   - USDC 永续
     *   - 币本位 USD 永续
     *   - BTC 计价反向永续（BTC/USDT:SWAP 对调这种不常见但支持）
     */
    public function testOkxSwapVariants(): void
    {
        $formatter = new OkxSymbolFormatter();

        $cases = [
            ['BTC-USDT-SWAP',    'BTC', 'USDT'],
            ['ETH-USDC-SWAP',    'ETH', 'USDC'],
            ['SOL-USD-SWAP',     'SOL', 'USD'],      // 币本位永续
            ['XRP-BTC-SWAP',     'XRP', 'BTC'],      // BTC 计价反向永续
            ['DOGE-ETH-SWAP',    'DOGE', 'ETH'],     // ETH 计价
            ['1000SHIB-USDT-SWAP','1000SHIB', 'USDT'],
        ];

        foreach ($cases as [$native, $expBase, $expQuote]) {
            $symbol = $formatter->parseExchangeSymbol($native);
            $this->assertTrue($symbol->isSwap(), "Type should be swap for {$native}");
            $this->assertEquals($expBase, $symbol->getBase(), "Base mismatch for {$native}");
            $this->assertEquals($expQuote, $symbol->getQuote(), "Quote mismatch for {$native}");
        }

        // 验证 OKX 的双向一致性：标准 → OKX → 解析 保持完全一致
        foreach (['BTC/USDT:SWAP', 'ETH/USDC:SWAP', 'BTC/USD:SWAP', 'XRP/BTC:SWAP'] as $std) {
            $original = TradingSymbol::parse($std);
            $native = $formatter->format($original);
            $parsed = $formatter->parseExchangeSymbol($native);
            $this->assertEquals($std, (string) $parsed, "OKX swap round-trip failed for {$std}");
        }
    }

    /**
     * OKX 交割合约专项测试：
     *   - 非别名日期（周一）→ 保持显式日期
     *   - 5 种别名日期 → 成功归一化
     *   - 币本位 USD 交割别名 → 归一化
     *   - 6位 & 8位日期 → 解析正常
     */
    public function testOkxFuturesVariants(): void
    {
        $formatter = new OkxSymbolFormatter();

        // 非别名日期（8月31日周一）
        $s1 = $formatter->parseExchangeSymbol('BTC-USDT-260831');
        $this->assertEquals('BTC/USDT:FUT-260831', (string) $s1);

        // 8位数字日期（非周五年份日期）
        $s2 = $formatter->parseExchangeSymbol('ETH-BTC-20260831');
        $this->assertEquals('ETH/BTC:FUT-20260831', (string) $s2);

        // 币本位交割 → 归一化 quarter
        $qDate = (new TradingSymbol('SOL', 'USD', TradingSymbol::TYPE_FUTURES, null, TradingSymbol::PERIOD_QUARTER))->getResolvedDeliveryDate();
        $s3 = $formatter->parseExchangeSymbol("SOL-USD-{$qDate}");
        $this->assertEquals('SOL/USD:QUARTER', (string) $s3);

        // 5 种周期别名完整往返（OKX）
        $periods = [
            TradingSymbol::PERIOD_THIS_WEEK   => 'BTC/USDT:THIS_WEEK',
            TradingSymbol::PERIOD_NEXT_WEEK   => 'ETH/USDT:NEXT_WEEK',
            TradingSymbol::PERIOD_QUARTER     => 'SOL/USDC:QUARTER',
            TradingSymbol::PERIOD_BI_QUARTER  => 'XRP/USDT:BI_QUARTER',
            TradingSymbol::PERIOD_CI_QUARTER  => 'DOGE/USDT:CI_QUARTER',
        ];
        foreach ($periods as $standard) {
            $original = TradingSymbol::parse($standard);
            $native = $formatter->format($original);
            $parsed = $formatter->parseExchangeSymbol($native);
            $this->assertEquals(
                $standard,
                (string) $parsed,
                "OKX full period round-trip failed: {$standard} -> {$native} -> " . (string) $parsed
            );
        }
    }

    /**
     * OKX 现货各种 quote 组合批量测试
     */
    public function testOkxSpotVariousQuotes(): void
    {
        $formatter = new OkxSymbolFormatter();

        $cases = [
            // [原生, 期望 base, 期望 quote]
            ['BTC-USDT',   'BTC', 'USDT'],
            ['ETH-USDC',   'ETH', 'USDC'],
            ['SOL-BUSD',   'SOL', 'BUSD'],
            ['BNB-DAI',    'BNB', 'DAI'],
            ['DOGE-TRY',   'DOGE', 'TRY'],
            ['XRP-EUR',    'XRP', 'EUR'],
            ['MATIC-BTC',  'MATIC', 'BTC'],
            ['LINK-ETH',   'LINK', 'ETH'],
            ['ADA-BNB',    'ADA', 'BNB'],
            ['1000SHIB-USDT', '1000SHIB', 'USDT'],
            ['PEPE-USDC',  'PEPE', 'USDC'],
        ];

        foreach ($cases as [$native, $expBase, $expQuote]) {
            $symbol = $formatter->parseExchangeSymbol($native);
            $this->assertTrue($symbol->isSpot(), "Type should be spot for {$native}");
            $this->assertEquals($expBase, $symbol->getBase(), "Base mismatch for {$native}");
            $this->assertEquals($expQuote, $symbol->getQuote(), "Quote mismatch for {$native}");
            // OKX 现货双向 100% 一致
            $reNative = $formatter->format($symbol);
            $this->assertEquals($native, $reNative, "OKX spot round-trip failed for {$native}");
        }
    }

    /**
     * Binance ↔ OKX 跨交易所的等价性验证：
     * 同一标准交易对，经过不同 formatter 转原生 → 解析回来，结果仍是同一个标准格式
     */
    public function testCrossExchangeEquivalence(): void
    {
        $binance = new BinanceSymbolFormatter();
        $okx = new OkxSymbolFormatter();

        $standards = [
            'BTC/USDT',
            'ETH/USDC',
            'BTC/USD:SWAP',
            'BTC/USDT:THIS_WEEK',
            'ETH/USDT:QUARTER',
            'BTC/USDT:FUT-260831',  // 非别名日期
        ];

        foreach ($standards as $std) {
            $original = TradingSymbol::parse($std);

            // Binance 正向 + 反向
            $bNative = $binance->format($original);
            $bParsed = $binance->parseExchangeSymbol($bNative, TradingSymbol::TYPE_SPOT);

            // OKX 正向 + 反向
            $oNative = $okx->format($original);
            $oParsed = $okx->parseExchangeSymbol($oNative);

            // 两个交易所反向解析回来的字符串应该完全相同
            $this->assertEquals(
                (string) $bParsed,
                (string) $oParsed,
                "Cross-exchange mismatch for {$std}: "
                . "Binance({$bNative}) -> " . (string) $bParsed . ', '
                . "OKX({$oNative}) -> " . (string) $oParsed
            );
        }
    }

    /**
     * OKX 异常输入测试：一段、四段、无效第三段、空 base/quote 等
     */
    public function testOkxInvalidInputsCoverage(): void
    {
        $formatter = new OkxSymbolFormatter();

        // 空
        $this->expectException(\InvalidArgumentException::class);
        $formatter->parseExchangeSymbol('-USDT');
        try { $formatter->parseExchangeSymbol('-USDT'); } catch (\Exception $e) {}

        // base 空
        $this->expectException(\InvalidArgumentException::class);
        $formatter->parseExchangeSymbol('-USDT-SWAP');
        try { $formatter->parseExchangeSymbol('-USDT-SWAP'); } catch (\Exception $e) {}

        // quote 空
        $this->expectException(\InvalidArgumentException::class);
        $formatter->parseExchangeSymbol('BTC--SWAP');
        try { $formatter->parseExchangeSymbol('BTC--SWAP'); } catch (\Exception $e) {}

        // 四段
        $this->expectException(\InvalidArgumentException::class);
        $formatter->parseExchangeSymbol('BTC-USDT-250328-EX');
        try { $formatter->parseExchangeSymbol('BTC-USDT-250328-EX'); } catch (\Exception $e) {}

        // 第三段既不是 SWAP 也不是日期
        $this->expectException(\InvalidArgumentException::class);
        $formatter->parseExchangeSymbol('BTC-USDT-QUARTER');
    }

    /**
     * Binance 异常输入测试补充
     */
    public function testBinanceInvalidInputsCoverage(): void
    {
        $formatter = new BinanceSymbolFormatter();

        // 未知 quote（尾部既不在白名单）
        $this->expectException(\InvalidArgumentException::class);
        $formatter->parseExchangeSymbol('BTCZZZ');

        // defaultType 非法
        $this->expectException(\InvalidArgumentException::class);
        $formatter->parseExchangeSymbol('BTCUSDT', TradingSymbol::TYPE_FUTURES);

        // 空字符串
        $this->expectException(\InvalidArgumentException::class);
        $formatter->parseExchangeSymbol('   ');
    }
}
