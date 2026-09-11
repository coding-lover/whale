<?php

namespace Sikelan\Tests\trader_test;

use App\Services\Trader\Strategy\SignalCols;
use PHPUnit\Framework\TestCase;

/**
 * SignalCols 列常量 & 工具方法契约测试
 *
 * 覆盖：
 *   1. assocKeys() 返回 12 个标准列名，顺序与列下标 0..11 一一对应
 *   2. assocKeys() 的 key 列表与 rowToAssoc() 的返回 key 严格一致（防止两者漂移）
 *   3. candlesToMatrix() 生成的矩阵每行恰好 NUM_COLUMNS 列，且 OHLCV 与 Candle 一致
 */
class SignalColsTest extends TestCase
{
    public function testAssocKeysReturnsStandard12Columns(): void
    {
        $keys = SignalCols::assocKeys();

        $this->assertCount(SignalCols::NUM_COLUMNS, $keys, '应返回 12 个标准列名');
        $this->assertSame(
            [
                'date',
                'open',
                'high',
                'low',
                'close',
                'volume',
                'enter_long',
                'exit_long',
                'enter_short',
                'exit_short',
                'enter_tag',
                'exit_tag',
            ],
            $keys,
            '列名顺序必须与列下标 0..11 对应'
        );
    }

    public function testAssocKeysMatchesRowToAssocKeys(): void
    {
        // 用一个全零行构造 assoc，取其 key
        $row = array_fill(0, SignalCols::NUM_COLUMNS, 0);
        $assoc = SignalCols::rowToAssoc($row);

        $this->assertSame(
            array_keys($assoc),
            SignalCols::assocKeys(),
            'assocKeys() 必须与 rowToAssoc() 的 key 严格同步（修改其一需同步更新另一个）'
        );
    }

    public function testCandlesToMatrixProducesNumericIndexRowsWithCorrectValues(): void
    {
        $ts    = 1_700_000_000_000;
        $candle = new \App\Services\Trader\Market\Candle($ts, 100.0, 102.0, 99.0, 101.0, 50.0);
        $matrix = SignalCols::candlesToMatrix([$candle]);

        $this->assertCount(1, $matrix);
        $row = $matrix[0];
        $this->assertCount(SignalCols::NUM_COLUMNS, $row, '每行必须是 12 列数字下标数组');

        // OHLCV 必须按列下标正确映射
        $this->assertSame($ts,        $row[SignalCols::DATE]);
        $this->assertSame(100.0,      $row[SignalCols::OPEN]);
        $this->assertSame(102.0,      $row[SignalCols::HIGH]);
        $this->assertSame(99.0,       $row[SignalCols::LOW]);
        $this->assertSame(101.0,      $row[SignalCols::CLOSE]);
        $this->assertSame(50.0,       $row[SignalCols::VOLUME]);

        // 信号列默认为 0 / 空串
        $this->assertSame(0,         $row[SignalCols::ENTER_LONG]);
        $this->assertSame(0,         $row[SignalCols::EXIT_LONG]);
        $this->assertSame(0,         $row[SignalCols::ENTER_SHORT]);
        $this->assertSame(0,         $row[SignalCols::EXIT_SHORT]);
        $this->assertSame('',        $row[SignalCols::ENTER_TAG]);
        $this->assertSame('',        $row[SignalCols::EXIT_TAG]);
    }
}
