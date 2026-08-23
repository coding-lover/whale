<?php

declare(strict_types=1);

namespace Tests\Stest;

use PHPUnit\Framework\TestCase;
use App\Services\Exchanges\TradingSymbol;
use App\Services\Exchanges\Formatters\BinanceSymbolFormatter;
use App\Services\Exchanges\Formatters\OkxSymbolFormatter;

/**
 * 交易对标准格式与 Formatter 转换测试
 *
 * 验证 TradingSymbol 的解析、日期推算，以及各 Formatter 的格式转换
 */
class TradingSymbolTest extends TestCase
{
    // ==================== 解析测试 ====================

    public function testParseSpotSymbol(): void
    {
        $symbol = TradingSymbol::parse('BTC/USDT');

        $this->assertEquals('BTC', $symbol->getBase());
        $this->assertEquals('USDT', $symbol->getQuote());
        $this->assertEquals(TradingSymbol::TYPE_SPOT, $symbol->getType());
        $this->assertNull($symbol->getDeliveryDate());
        $this->assertNull($symbol->getDeliveryPeriod());
        $this->assertTrue($symbol->isSpot());
        $this->assertFalse($symbol->isSwap());
        $this->assertFalse($symbol->isFutures());
    }

    public function testParseSwapSymbol(): void
    {
        $symbol = TradingSymbol::parse('BTC/USDT:SWAP');

        $this->assertEquals(TradingSymbol::TYPE_SWAP, $symbol->getType());
        $this->assertNull($symbol->getDeliveryDate());
        $this->assertNull($symbol->getDeliveryPeriod());
        $this->assertTrue($symbol->isSwap());
    }

    public function testParseCoinMarginedSwapSymbol(): void
    {
        $symbol = TradingSymbol::parse('BTC/USD:SWAP');

        $this->assertEquals('USD', $symbol->getQuote());
        $this->assertTrue($symbol->isSwap());
    }

    public function testParseFuturesWithExplicitDate(): void
    {
        $symbol = TradingSymbol::parse('BTC/USDT:FUT-250328');

        $this->assertEquals(TradingSymbol::TYPE_FUTURES, $symbol->getType());
        $this->assertEquals('250328', $symbol->getDeliveryDate());
        $this->assertNull($symbol->getDeliveryPeriod());
        $this->assertTrue($symbol->isFutures());
    }

    // ==================== 交割周期别名解析测试 ====================

    public function testParseThisWeek(): void
    {
        $symbol = TradingSymbol::parse('BTC/USDT:THIS_WEEK');

        $this->assertEquals(TradingSymbol::TYPE_FUTURES, $symbol->getType());
        $this->assertNull($symbol->getDeliveryDate());
        $this->assertEquals(TradingSymbol::PERIOD_THIS_WEEK, $symbol->getDeliveryPeriod());
        $this->assertTrue($symbol->isFutures());
    }

    public function testParseNextWeek(): void
    {
        $symbol = TradingSymbol::parse('BTC/USDT:NEXT_WEEK');

        $this->assertEquals(TradingSymbol::PERIOD_NEXT_WEEK, $symbol->getDeliveryPeriod());
    }

    public function testParseQuarter(): void
    {
        $symbol = TradingSymbol::parse('BTC/USDT:QUARTER');

        $this->assertEquals(TradingSymbol::PERIOD_QUARTER, $symbol->getDeliveryPeriod());
    }

    public function testParseBiQuarter(): void
    {
        $symbol = TradingSymbol::parse('BTC/USDT:BI_QUARTER');

        $this->assertEquals(TradingSymbol::PERIOD_BI_QUARTER, $symbol->getDeliveryPeriod());
    }

    public function testParseCiQuarter(): void
    {
        $symbol = TradingSymbol::parse('BTC/USDT:CI_QUARTER');

        $this->assertEquals(TradingSymbol::PERIOD_CI_QUARTER, $symbol->getDeliveryPeriod());
    }

    public function testParsePeriodCaseInsensitive(): void
    {
        $symbol = TradingSymbol::parse('btc/usdt:quarter');

        $this->assertEquals(TradingSymbol::PERIOD_QUARTER, $symbol->getDeliveryPeriod());
        $this->assertEquals('BTC', $symbol->getBase());
    }

    public function testToStringRetainsPeriodAlias(): void
    {
        $this->assertEquals('BTC/USDT:THIS_WEEK', (string) TradingSymbol::parse('BTC/USDT:THIS_WEEK'));
        $this->assertEquals('BTC/USDT:QUARTER', (string) TradingSymbol::parse('BTC/USDT:QUARTER'));
        $this->assertEquals('BTC/USDT:CI_QUARTER', (string) TradingSymbol::parse('BTC/USDT:CI_QUARTER'));
    }

    // ==================== 交割日期推算测试 ====================

    public function testGetResolvedDeliveryDateForPeriods(): void
    {
        $periods = [
            TradingSymbol::PERIOD_THIS_WEEK,
            TradingSymbol::PERIOD_NEXT_WEEK,
            TradingSymbol::PERIOD_QUARTER,
            TradingSymbol::PERIOD_BI_QUARTER,
            TradingSymbol::PERIOD_CI_QUARTER,
        ];

        foreach ($periods as $period) {
            $symbol = new TradingSymbol('BTC', 'USDT', TradingSymbol::TYPE_FUTURES, null, $period);
            $date = $symbol->getResolvedDeliveryDate();

            $this->assertNotNull($date, "Failed for period: {$period}");
            $this->assertMatchesRegularExpression('/^\d{6}$/', $date, "Date format should be YYMMDD for period: {$period}");

            // 验证推算的日期确实是周五
            $year = 2000 + (int) substr($date, 0, 2);
            $month = (int) substr($date, 2, 2);
            $day = (int) substr($date, 4, 2);
            $ts = gmmktime(8, 0, 0, $month, $day, $year);
            $this->assertEquals(5, (int) gmdate('N', $ts), "Resolved date should be Friday for period: {$period}, got date: {$date}");
        }
    }

    public function testGetResolvedDeliveryDateForExplicitDate(): void
    {
        $symbol = TradingSymbol::parse('BTC/USDT:FUT-250328');
        $this->assertEquals('250328', $symbol->getResolvedDeliveryDate());
    }

    public function testThisWeekIsUpcomingFriday(): void
    {
        $symbol = new TradingSymbol('BTC', 'USDT', TradingSymbol::TYPE_FUTURES, null, TradingSymbol::PERIOD_THIS_WEEK);
        $date = $symbol->getResolvedDeliveryDate();

        $year = 2000 + (int) substr($date, 0, 2);
        $month = (int) substr($date, 2, 2);
        $day = (int) substr($date, 4, 2);
        $resolvedTs = gmmktime(8, 0, 0, $month, $day, $year);

        $this->assertGreaterThan(time(), $resolvedTs, "this_week date should be in the future");
        $this->assertEquals(5, (int) gmdate('N', $resolvedTs), "this_week should be Friday");
        $this->assertLessThanOrEqual(time() + 8 * 86400, $resolvedTs, "this_week should be within 8 days");
    }

    public function testNextWeekIsAfterThisWeek(): void
    {
        $thisWeek = new TradingSymbol('BTC', 'USDT', TradingSymbol::TYPE_FUTURES, null, TradingSymbol::PERIOD_THIS_WEEK);
        $nextWeek = new TradingSymbol('BTC', 'USDT', TradingSymbol::TYPE_FUTURES, null, TradingSymbol::PERIOD_NEXT_WEEK);

        $twDate = $thisWeek->getResolvedDeliveryDate();
        $nwDate = $nextWeek->getResolvedDeliveryDate();

        $twTs = gmmktime(8, 0, 0, (int) substr($twDate, 2, 2), (int) substr($twDate, 4, 2), 2000 + (int) substr($twDate, 0, 2));
        $nwTs = gmmktime(8, 0, 0, (int) substr($nwDate, 2, 2), (int) substr($nwDate, 4, 2), 2000 + (int) substr($nwDate, 0, 2));

        $this->assertEquals(7 * 86400, $nwTs - $twTs, "next_week should be exactly 7 days after this_week");
    }

    public function testQuarterIsInCurrentQuarterEndMonth(): void
    {
        $symbol = new TradingSymbol('BTC', 'USDT', TradingSymbol::TYPE_FUTURES, null, TradingSymbol::PERIOD_QUARTER);
        $date = $symbol->getResolvedDeliveryDate();

        $month = (int) substr($date, 2, 2);
        $currentMonth = (int) gmdate('n');
        $currentQuarter = (int) ceil($currentMonth / 3);
        $endMonth = $currentQuarter * 3;

        $this->assertEquals($endMonth, $month, "quarter should be in month {$endMonth} (current quarter end month), got {$month}");
    }

    public function testBiQuarterIsInNextQuarterEndMonth(): void
    {
        $symbol = new TradingSymbol('BTC', 'USDT', TradingSymbol::TYPE_FUTURES, null, TradingSymbol::PERIOD_BI_QUARTER);
        $date = $symbol->getResolvedDeliveryDate();

        $month = (int) substr($date, 2, 2);
        $currentMonth = (int) gmdate('n');
        $currentQuarter = (int) ceil($currentMonth / 3);
        $nextQuarter = $currentQuarter + 1;
        if ($nextQuarter > 4) {
            $nextQuarter = 1;
        }
        $endMonth = $nextQuarter * 3;

        $this->assertEquals($endMonth, $month, "bi_quarter should be in month {$endMonth} (next quarter end month), got {$month}");
    }

    public function testCiQuarterIsInThirdQuarterEndMonth(): void
    {
        $symbol = new TradingSymbol('BTC', 'USDT', TradingSymbol::TYPE_FUTURES, null, TradingSymbol::PERIOD_CI_QUARTER);
        $date = $symbol->getResolvedDeliveryDate();

        $month = (int) substr($date, 2, 2);
        $currentMonth = (int) gmdate('n');
        $currentQuarter = (int) ceil($currentMonth / 3);
        $thirdQuarter = $currentQuarter + 2;
        while ($thirdQuarter > 4) {
            $thirdQuarter -= 4;
        }
        $endMonth = $thirdQuarter * 3;

        $this->assertEquals($endMonth, $month, "ci_quarter should be in month {$endMonth} (third quarter end month), got {$month}");
    }

    // ==================== 异常测试 ====================

    public function testParseInvalidFormatThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TradingSymbol::parse('BTCUSDT');
    }

    public function testParseEmptyStringThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TradingSymbol::parse('');
    }

    public function testParseUnknownTypeThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TradingSymbol::parse('BTC/USDT:UNKNOWN');
    }

    public function testParseInvalidDeliveryDateThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TradingSymbol::parse('BTC/USDT:FUT-INVALID');
    }

    // ==================== 字符串转换测试 ====================

    public function testToString(): void
    {
        $this->assertEquals('BTC/USDT', (string) TradingSymbol::parse('BTC/USDT'));
        $this->assertEquals('BTC/USDT:SWAP', (string) TradingSymbol::parse('BTC/USDT:SWAP'));
        $this->assertEquals('BTC/USDT:FUT-250328', (string) TradingSymbol::parse('BTC/USDT:FUT-250328'));
    }

    public function testToStringUppercase(): void
    {
        $this->assertEquals('BTC/USDT:SWAP', (string) TradingSymbol::parse('btc/usdt:swap'));
        $this->assertEquals('BTC/USDT:QUARTER', (string) TradingSymbol::parse('btc/usdt:quarter'));
    }

    // ==================== BinanceSymbolFormatter 测试 ====================

    public function testBinanceFormatterSpot(): void
    {
        $formatter = new BinanceSymbolFormatter();
        $symbol = TradingSymbol::parse('BTC/USDT');

        $this->assertEquals('BTCUSDT', $formatter->format($symbol));
    }

    public function testBinanceFormatterSwap(): void
    {
        $formatter = new BinanceSymbolFormatter();
        $symbol = TradingSymbol::parse('BTC/USDT:SWAP');

        $this->assertEquals('BTCUSDT', $formatter->format($symbol));
    }

    public function testBinanceFormatterCoinMarginedSwap(): void
    {
        $formatter = new BinanceSymbolFormatter();
        $symbol = TradingSymbol::parse('BTC/USD:SWAP');

        $this->assertEquals('BTCUSD_PERP', $formatter->format($symbol));
    }

    public function testBinanceFormatterFuturesExplicitDate(): void
    {
        $formatter = new BinanceSymbolFormatter();
        $symbol = TradingSymbol::parse('BTC/USDT:FUT-250328');

        $this->assertEquals('BTCUSDT_250328', $formatter->format($symbol));
    }

    public function testBinanceFormatterFuturesPeriodQuarter(): void
    {
        $formatter = new BinanceSymbolFormatter();
        $symbol = TradingSymbol::parse('BTC/USDT:QUARTER');
        $result = $formatter->format($symbol);

        $this->assertMatchesRegularExpression('/^BTCUSDT_\d{6}$/', $result);
    }

    public function testBinanceFormatterFuturesPeriodThisWeek(): void
    {
        $formatter = new BinanceSymbolFormatter();
        $symbol = TradingSymbol::parse('BTC/USDT:THIS_WEEK');
        $result = $formatter->format($symbol);

        $this->assertMatchesRegularExpression('/^BTCUSDT_\d{6}$/', $result);
    }

    public function testBinanceFormatterGetExchangeName(): void
    {
        $formatter = new BinanceSymbolFormatter();

        $this->assertEquals('binance', $formatter->getExchangeName());
    }

    // ==================== OkxSymbolFormatter 测试 ====================

    public function testOkxFormatterSpot(): void
    {
        $formatter = new OkxSymbolFormatter();
        $symbol = TradingSymbol::parse('BTC/USDT');

        $this->assertEquals('BTC-USDT', $formatter->format($symbol));
    }

    public function testOkxFormatterSwap(): void
    {
        $formatter = new OkxSymbolFormatter();
        $symbol = TradingSymbol::parse('BTC/USDT:SWAP');

        $this->assertEquals('BTC-USDT-SWAP', $formatter->format($symbol));
    }

    public function testOkxFormatterFuturesExplicitDate(): void
    {
        $formatter = new OkxSymbolFormatter();
        $symbol = TradingSymbol::parse('BTC/USDT:FUT-250328');

        $this->assertEquals('BTC-USDT-250328', $formatter->format($symbol));
    }

    public function testOkxFormatterFuturesPeriodQuarter(): void
    {
        $formatter = new OkxSymbolFormatter();
        $symbol = TradingSymbol::parse('BTC/USDT:QUARTER');
        $result = $formatter->format($symbol);

        $this->assertMatchesRegularExpression('/^BTC-USDT-\d{6}$/', $result);
    }

    public function testOkxFormatterFuturesPeriodThisWeek(): void
    {
        $formatter = new OkxSymbolFormatter();
        $symbol = TradingSymbol::parse('BTC/USDT:THIS_WEEK');
        $result = $formatter->format($symbol);

        $this->assertMatchesRegularExpression('/^BTC-USDT-\d{6}$/', $result);
    }

    public function testOkxFormatterGetExchangeName(): void
    {
        $formatter = new OkxSymbolFormatter();

        $this->assertEquals('okx', $formatter->getExchangeName());
    }

    // ==================== 跨 Formatter 一致性测试 ====================

    /**
     * 周期别名和显式日期推算出相同结果时，两个 Formatter 格式应一致
     */
    public function testPeriodAndExplicitDateProduceSameFormat(): void
    {
        $binanceFormatter = new BinanceSymbolFormatter();
        $okxFormatter = new OkxSymbolFormatter();

        // 使用 QUARTER 别名
        $quarterSymbol = TradingSymbol::parse('BTC/USDT:QUARTER');
        $resolvedDate = $quarterSymbol->getResolvedDeliveryDate();

        // 用推算出的日期构造显式日期格式
        $explicitSymbol = TradingSymbol::parse("BTC/USDT:FUT-{$resolvedDate}");

        // 两者通过 Formatter 转出的格式应该一致
        $this->assertEquals($binanceFormatter->format($quarterSymbol), $binanceFormatter->format($explicitSymbol));
        $this->assertEquals($okxFormatter->format($quarterSymbol), $okxFormatter->format($explicitSymbol));
    }

    /**
     * 批量验证所有交割周期别名在 Binance 和 OKX Formatter 上的格式
     */
    public function testBatchPeriodConversion(): void
    {
        $binanceFormatter = new BinanceSymbolFormatter();
        $okxFormatter = new OkxSymbolFormatter();

        $periods = ['THIS_WEEK', 'NEXT_WEEK', 'QUARTER', 'BI_QUARTER', 'CI_QUARTER'];

        foreach ($periods as $period) {
            $symbol = TradingSymbol::parse("BTC/USDT:{$period}");

            // Binance 格式：BTCUSDT_YYMMDD
            $binanceResult = $binanceFormatter->format($symbol);
            $this->assertMatchesRegularExpression(
                '/^BTCUSDT_\d{6}$/',
                $binanceResult,
                "Binance format failed for period: {$period}"
            );

            // OKX 格式：BTC-USDT-YYMMDD
            $okxResult = $okxFormatter->format($symbol);
            $this->assertMatchesRegularExpression(
                '/^BTC-USDT-\d{6}$/',
                $okxResult,
                "OKX format failed for period: {$period}"
            );

            // 两者推算出的日期部分应相同
            $binanceDate = substr($binanceResult, -6);
            $okxDate = substr($okxResult, -6);
            $this->assertEquals($binanceDate, $okxDate, "Date mismatch for period: {$period}");
        }
    }

    /**
     * 多交易对多交易所批量验证
     */
    public function testBatchConversion(): void
    {
        $binanceFormatter = new BinanceSymbolFormatter();
        $okxFormatter = new OkxSymbolFormatter();

        $cases = [
            // [标准格式, Binance, OKX]
            ['BTC/USDT',            'BTCUSDT',        'BTC-USDT'],
            ['ETH/USDT',            'ETHUSDT',        'ETH-USDT'],
            ['SOL/USDC',            'SOLUSDC',        'SOL-USDC'],
            ['BTC/USDT:SWAP',       'BTCUSDT',        'BTC-USDT-SWAP'],
            ['ETH/USDT:SWAP',       'ETHUSDT',        'ETH-USDT-SWAP'],
            ['BTC/USD:SWAP',        'BTCUSD_PERP',    'BTC-USD-SWAP'],
            ['BTC/USDT:FUT-240328', 'BTCUSDT_240328', 'BTC-USDT-240328'],
            ['ETH/USDT:FUT-240628', 'ETHUSDT_240628', 'ETH-USDT-240628'],
        ];

        foreach ($cases as [$standard, $binance, $okx]) {
            $symbol = TradingSymbol::parse($standard);
            $this->assertEquals($binance, $binanceFormatter->format($symbol), "Binance conversion failed for: {$standard}");
            $this->assertEquals($okx, $okxFormatter->format($symbol), "OKX conversion failed for: {$standard}");
        }
    }
}
