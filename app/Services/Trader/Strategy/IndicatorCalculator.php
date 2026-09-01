<?php

namespace App\Services\Trader\Strategy;

/**
 * 技术指标计算器（统一封装 PHP trader 扩展）
 *
 * 设计目的：
 *   - 所有策略的指标计算统一走 PHP `trader` 扩展（C 实现，速度 & 正确性都优于 PHP 手写）
 *   - 解决 trader_*() 返回"稀疏索引"的问题：本类输出数组长度 == 输入长度，
 *     前面的 warmup 窗口用合理的种子值 / 中性值补齐，便于策略直接按下标访问。
 *
 * 依赖约束（硬要求）：
 *   PHP 必须安装并加载 `trader` 扩展（pecl install trader）。
 *   本类在静态初始化时会主动检查，未加载则抛出 RuntimeException。
 *
 * 目前已封装常用指标（与 TA-Lib / TradingView 行为对齐，输出长度 = 输入长度）：
 *
 *  ──────────────────────── A. 均线类 ────────────────────────
 *   - sma()       简单移动平均（SMA）
 *   - ema()       指数移动平均（EMA，alpha=2/(period+1)）
 *   - wma()       加权移动平均（WMA，近大远小线性加权）
 *   - dema()      双 EMA（DEMA = 2·EMA − EMA(EMA)，比 EMA 滞后更小）
 *   - tema()      三 EMA（TEMA，滞后进一步减小，适合短期趋势）
 *   - trima()     三角移动平均（TRIMA，重心平滑，抗毛刺）
 *   - kama()      考夫曼自适应移动平均（KAMA，根据波动自动调节快慢）
 *   - ma()        通用 MA（可指定 8 种 MA_TYPE_*，底层 trader_ma）
 *
 *  ──────────────────────── B. 动量 / 震荡类 ────────────────────────
 *   - rsi()       相对强弱指数（RSI，Wilder's smoothing，值域 0..100）
 *   - cmo()       钱德动量摆动（CMO，Chande Momentum Oscillator，-100..+100）
 *   - roc()       变动率 ROC = (now/nPeriodsAgo − 1) × 100（%）
 *   - mom()       动量 MOM = now − nPeriodsAgo（绝对差）
 *   - willr()     威廉指标 Williams %R（-100..0）
 *   - cci()       顺势指标（CCI，衡量偏离典型价的程度）
 *   - stoch()     随机指标 KD（SlowK + SlowD，STOCHASTIC，0..100，KDJ 中 KD 部分）
 *   - stochf()    快速随机 StochF（FastK + FastD，0..100）
 *   - stochRsi()  StochRSI（RSI 上再做 KDJ，0..100，短线超买超卖更灵敏）
 *   - ultOsc()    终极振荡器（Ultimate Oscillator，三周期加权，0..100）
 *   - apo()       绝对价格振荡（APO = EMA_fast − EMA_slow，MACD 的"绝对差值"版）
 *   - ppo()       百分比价格振荡（PPO = (EMA_fast−EMA_slow)/EMA_slow × 100，可跨品种对比）
 *   - macd()      MACD（macdLine, signalLine, histogram 三线）
 *
 *  ──────────────────────── C. 波动率 / 波幅类 ────────────────────────
 *   - stddev()    滚动标准差（σ，样本无偏：÷(n-1)）
 *   - variance()  滚动方差 VAR = σ²
 *   - trange()    True Range（每根真实波幅 = max(high−low, |high−prevC|, |low−prevC|)）
 *   - atr()       平均真实波幅 ATR（Wilder's smoothing，衡量品种"每日波动幅度"）
 *   - natr()      归一化 ATR（ATR 百分比，%，跨品种可比）
 *
 *  ──────────────────────── D. 趋势类（方向 / 强度）────────────────────────
 *   - adx()       平均趋向指数 ADX（趋势强度，0..100，>25 视为有趋势）
 *   - adxr()      ADXR（ADX 的平滑值，降低 ADX 抖动）
 *   - plusDi()    +DI（多头方向指标，0..100）
 *   - minusDi()   −DI（空头方向指标，0..100）
 *   - aroon()     阿隆指标（AroonUp + AroonDown，0..100，判断新趋势启动）
 *   - aroonOsc()  阿隆振荡 = AroonUp − AroonDown（-100..+100）
 *   - sar()       抛物线 SAR（Stop & Reverse，逐根止损/止盈追踪价位）
 *
 *  ──────────────────────── E. 量价类 ────────────────────────
 *   - mfi()       资金流量指数 MFI（RSI 的"带成交量"版本，0..100）
 *   - obv()       能量潮 OBV（按涨跌累加/减成交量，量价背离用）
 *   - ad()        累积/派发线 A/D（Chaikin A/D Line，基于收盘位置加权）
 *   - adOsc()     A/D 振荡 = EMA(fast,A/D) − EMA(slow,A/D)
 *
 *  ──────────────────────── F. 典型价 / 价格合成 ────────────────────────
 *   - avgPrice()  均价 = (O+H+L+C)/4（比 close 更稳健，减少单根毛刺噪音）
 *   - typPrice()  典型价 = (H+L+C)/3
 *   - wclPrice()  加权收盘价 = (H+L+2·C)/4（给收盘价更大权重）
 *   - medPrice()  中间价 = (H+L)/2
 *
 * 如需更多指标（HT 周期分析 / BETA / CORREL / MINMAX 等），按相同模式在本类追加即可，
 * 不必再在单个策略里重复实现 PHP 算法。
 */
class IndicatorCalculator
{
    /** @var bool 已检查过扩展加载状态 */
    private static bool $checked = false;

    /**
     * 强制检查 trader 扩展是否可用（首次调用时执行；后续调用直接跳过）。
     *
     * @throws \RuntimeException 未安装/未加载 trader 扩展
     */
    public static function requireTraderExtension(): void
    {
        if (self::$checked) {
            return;
        }
        if (!extension_loaded('trader')) {
            throw new \RuntimeException(
                'Trader Service 依赖 PHP `trader` 扩展（pecl install trader），'
                . '但当前环境未加载。请先安装扩展后再运行策略回测 / 实盘。'
            );
        }
        // ---- 全局精度配置（默认 3 位小数会造成布林带严重偏差）----
        //  trader 扩展的 ini 设置：trader.real_precision 控制所有浮点输出的保留小数位
        //  该扩展不支持 -1（-1 在某些版本会让所有指标结果变成 0），统一设为 10 足够金融精度。
        $current = ini_get('trader.real_precision');
        if ($current === false || (int) $current < 8) {
            ini_set('trader.real_precision', '10');
        }
        self::$checked = true;
    }

    // ========================================================================
    //  公开 API：指标函数（输出长度 == 输入长度）
    // ========================================================================

    /**
     * 简单移动平均 SMA。
     *
     * 前 period-1 根无法得到完整窗口：用当前已有的 i+1 根的均值做"种子值"填充
     * （与 TradingView / ta-lib 首段行为最接近，避免全 0 造成策略误判）。
     *
     * @param float[] $src    收盘价 / 成交量等一维价格序列
     * @param int     $period 周期 ≥ 1
     * @return float[] 长度 = count($src)
     */
    public static function sma(array $src, int $period): array
    {
        self::requireTraderExtension();
        $n = count($src);
        $out = array_fill(0, $n, 0.0);
        if ($n === 0 || $period < 1) {
            return $out;
        }

        // ---- 种子区（i < period-1）：用当前已有的 i+1 根平均 ----
        $sum = 0.0;
        $seedEnd = min($period - 1, $n);
        for ($i = 0; $i < $seedEnd; $i++) {
            $sum += (float) $src[$i];
            $out[$i] = $sum / ($i + 1);
        }

        // ---- 正常区：调用 trader_sma（返回键从 period-1 开始） ----
        if ($n >= $period) {
            $raw = \trader_sma($src, $period);
            if ($raw === false) {
                $raw = [];
            }
            foreach ($raw as $idx => $val) {
                $out[(int) $idx] = (float) $val;
            }
        }
        return $out;
    }

    /**
     * 指数移动平均 EMA（alpha = 2 / (period + 1)，标准）。
     *
     * 前 period-1 根用前 min(period, n) 根的 SMA 作为种子值（与 TA-Lib 一致），
     * 避免递推起点跳变。
     *
     * @param float[] $src
     * @param int     $period ≥ 1
     * @return float[] 长度 = count($src)
     */
    public static function ema(array $src, int $period): array
    {
        self::requireTraderExtension();
        $n = count($src);
        $out = array_fill(0, $n, 0.0);
        if ($n === 0 || $period < 1) {
            return $out;
        }

        // ---- 种子：前 min(period, n) 根取 SMA 作为 EMA 初值 ----
        $seedLen = min($period, $n);
        $seedSum = 0.0;
        for ($i = 0; $i < $seedLen; $i++) {
            $seedSum += (float) $src[$i];
        }
        $seed = $seedSum / $seedLen;
        for ($i = 0; $i < $seedLen; $i++) {
            $out[$i] = $seed;
        }

        // ---- 正常区：trader_ema（键从 period-1 开始）----
        if ($n >= $period) {
            $raw = \trader_ema($src, $period);
            if ($raw === false) {
                $raw = [];
            }
            foreach ($raw as $idx => $val) {
                $out[(int) $idx] = (float) $val;
            }
        }
        return $out;
    }

    /**
     * 相对强弱指数 RSI（Wilder's smoothing，与 Binance / OKX / TradingView 一致）。
     *
     * 前 period 根返回中性值 50.0（避免除 0 / 超买超卖误判）；
     * trader_rsi 天然从下标 period 开始产出有效值，正好匹配。
     *
     * @param float[] $src    收盘价序列
     * @param int     $period ≥ 2（常用 14）
     * @return float[] 长度 = count($src)，值域 [0, 100]
     */
    public static function rsi(array $src, int $period): array
    {
        self::requireTraderExtension();
        $n = count($src);
        $out = array_fill(0, $n, 50.0); // 中性 50
        if ($n <= $period || $period < 2) {
            return $out;
        }

        $raw = \trader_rsi($src, $period);
        if ($raw === false) {
            $raw = [];
        }
        foreach ($raw as $idx => $val) {
            $v = (float) $val;
            // 防御 1：NAN 直接 50
            if (is_nan($v)) {
                $v = 50.0;
            }
            // 防御 2：trader_rsi 在"横盘（所有相邻价格 = 0）"时错误地返回 0
            //   Wilder's smoothing 标准应该返回 50 中性；这里额外检查最近 period 窗口内是否无波动
            if ($v === 0.0 || $v === 100.0) {
                $flat = true;
                $iStart = max(1, (int) $idx - $period + 1);
                for ($j = $iStart; $j <= (int) $idx; $j++) {
                    if (abs($src[$j] - $src[$j - 1]) > 1e-12) {
                        $flat = false;
                        break;
                    }
                }
                if ($flat) {
                    $v = 50.0;
                }
            }
            $out[(int) $idx] = $v;
        }
        return $out;
    }

    /**
     * 布林带 Bollinger Bands。
     *
     *   BB_MID   = SMA(period)
     *   BB_UPPER = MID + stdDev × nbDevUp
     *   BB_LOWER = MID - stdDev × nbDevDn
     *
     * 前 period-1 根：按已有 i+1 根窗口计算种子 SMA + 滚动 σ（样本无偏）。
     * 若 stdDev < 2（无法用 n-1）则退化为 0.0（无带宽）。
     *
     * @param float[] $src
     * @param int     $period   ≥ 2（常用 20）
     * @param float   $nbDevUp  上轨 σ 倍数（常用 2.0）
     * @param float   $nbDevDn  下轨 σ 倍数（常用 2.0）
     * @return array{0:float[], 1:float[], 2:float[]} [upper, middle, lower]，每个数组长度 = count($src)
     */
    public static function bbands(array $src, int $period, float $nbDevUp = 2.0, float $nbDevDn = 2.0): array
    {
        self::requireTraderExtension();
        $n = count($src);
        $upper = array_fill(0, $n, 0.0);
        $mid   = array_fill(0, $n, 0.0);
        $lower = array_fill(0, $n, 0.0);
        if ($n === 0 || $period < 2) {
            return [$upper, $mid, $lower];
        }

        // ---- 种子区（i < period-1）：SMA 种子 + 滚动 σ 逐根算 ----
        $seedEnd = min($period - 1, $n);
        $window = [];
        $sum = 0.0;
        for ($i = 0; $i < $seedEnd; $i++) {
            $v = (float) $src[$i];
            $window[] = $v;
            $sum += $v;
            $m = count($window);
            $mean = $sum / $m;
            $mid[$i] = $mean;
            if ($m < 2) {
                $sigma = 0.0;
            } else {
                $var = 0.0;
                foreach ($window as $x) {
                    $var += ($x - $mean) ** 2;
                }
                $var /= ($m - 1); // 样本无偏
                $sigma = sqrt($var);
            }
            $upper[$i] = $mean + $sigma * $nbDevUp;
            $lower[$i] = $mean - $sigma * $nbDevDn;
        }

        // ---- 正常区：trader_bbands 返回 [upper, mid, lower] ----
        if ($n >= $period) {
            $raw = \trader_bbands($src, $period, $nbDevUp, $nbDevDn);
            if (is_array($raw) && isset($raw[0], $raw[1], $raw[2])) {
                foreach ($raw[0] as $idx => $val) { $upper[(int)$idx] = (float)$val; }
                foreach ($raw[1] as $idx => $val) { $mid[(int)$idx]   = (float)$val; }
                foreach ($raw[2] as $idx => $val) { $lower[(int)$idx] = (float)$val; }
            }
        }
        return [$upper, $mid, $lower];
    }

    /**
     * 滚动标准差（样本无偏：÷(n-1)）。
     *
     * 前 period-1 根按当前已有窗口 i+1 根计算（不足则退化为 0）。
     *
     * @param float[] $src
     * @param int     $period ≥ 2
     * @return float[] 长度 = count($src)
     */
    public static function stddev(array $src, int $period): array
    {
        self::requireTraderExtension();
        $n = count($src);
        $out = array_fill(0, $n, 0.0);
        if ($n === 0 || $period < 2) {
            return $out;
        }

        // 种子区：逐根算滚动 σ（样本无偏 ÷(n-1)）
        $seedEnd = min($period - 1, $n);
        $window = [];
        for ($i = 0; $i < $seedEnd; $i++) {
            $window[] = (float) $src[$i];
            $m = count($window);
            if ($m < 2) {
                $out[$i] = 0.0;
                continue;
            }
            $mean = array_sum($window) / $m;
            $var = 0.0;
            foreach ($window as $x) {
                $var += ($x - $mean) ** 2;
            }
            $var /= ($m - 1);
            $out[$i] = sqrt($var);
        }

        if ($n >= $period) {
            // 注意：某些 PHP trader 扩展版本中 `trader_stddev` 返回异常（溢出或未初始化）
            // 改用 `trader_bbands` 反推（bbands 用 population σ = sqrt(SS/n)），
            // 再换算成 sample σ = pop_sigma × sqrt(n/(n-1))。
            $bb = \trader_bbands($src, $period, 1.0, 1.0);
            if (is_array($bb) && isset($bb[0], $bb[1])) {
                // 换算系数：sample σ = pop σ × sqrt(period / (period - 1))
                $correction = sqrt($period / ($period - 1));
                foreach ($bb[0] as $idx => $upperVal) {
                    $midVal = $bb[1][$idx] ?? 0.0;
                    $popSigma = ((float) $upperVal) - ((float) $midVal); // nbDevUp = 1
                    if ($popSigma < 0) {
                        $popSigma = 0.0;
                    }
                    $out[(int) $idx] = $popSigma * $correction;
                }
            }
        }
        return $out;
    }

    // ========================================================================
    //  A. 均线类（WMA / DEMA / TEMA / TRIMA / KAMA / MA）
    // ========================================================================

    /**
     * 加权移动平均 WMA（近大远小：period、period-1、…、1 权重线性加权）。
     *
     * 前 period-1 根按已有的 i+1 根做归一化线性加权（和 trader_wma 对齐）。
     *
     * @param float[] $src
     * @param int     $period ≥ 1
     * @return float[] 长度 = count($src)
     */
    public static function wma(array $src, int $period): array
    {
        self::requireTraderExtension();
        $n = count($src);
        $out = array_fill(0, $n, 0.0);
        if ($n === 0 || $period < 1) {
            return $out;
        }
        // 种子区
        $seedEnd = min($period - 1, $n);
        for ($i = 0; $i < $seedEnd; $i++) {
            $m = $i + 1;
            $weightSum = $m * ($m + 1) / 2; // 1+2+…+m
            if ($weightSum <= 0) {
                $out[$i] = (float) $src[$i];
                continue;
            }
            $sum = 0.0;
            for ($j = 0; $j <= $i; $j++) {
                $w = $j + 1; // 越新权重越大（和 trader_wma 行为一致）
                $sum += ((float) $src[$j]) * $w;
            }
            $out[$i] = $sum / $weightSum;
        }
        if ($n >= $period) {
            $raw = \trader_wma($src, $period);
            if ($raw !== false) {
                foreach ($raw as $idx => $val) {
                    $out[(int) $idx] = (float) $val;
                }
            }
        }
        return $out;
    }

    /**
     * 通用 MA：支持 8 种 MA_TYPE（SMA/EMA/WMA/DEMA/TEMA/TRIMA/KAMA/MAMA）。
     * 常用 TRADER_MA_TYPE_SMA / TRADER_MA_TYPE_EMA / TRADER_MA_TYPE_WMA
     *      TRADER_MA_TYPE_DEMA / TRADER_MA_TYPE_TEMA / TRADER_MA_TYPE_TRIMA / TRADER_MA_TYPE_KAMA
     *
     * @param float[] $src
     * @param int     $period ≥ 1
     * @param int     $maType TRADER_MA_TYPE_* 常量
     * @return float[] 长度 = count($src)
     */
    public static function ma(array $src, int $period, int $maType = TRADER_MA_TYPE_SMA): array
    {
        self::requireTraderExtension();
        $n = count($src);
        $out = array_fill(0, $n, 0.0);
        if ($n === 0 || $period < 1) {
            return $out;
        }
        // 种子区（简单取当前已有 i+1 根平均；与 SMA 行为一致即可，因为窗口非常短不会影响后续）
        $seedEnd = min($period - 1, $n);
        $sum = 0.0;
        for ($i = 0; $i < $seedEnd; $i++) {
            $sum += (float) $src[$i];
            $out[$i] = $sum / ($i + 1);
        }
        if ($n >= $period) {
            $raw = \trader_ma($src, $period, $maType);
            if ($raw !== false) {
                foreach ($raw as $idx => $val) {
                    $out[(int) $idx] = (float) $val;
                }
            }
        }
        return $out;
    }

    /**
     * 双 EMA（DEMA = 2·EMA(p) − EMA(p,EMA(p))），滞后小于单 EMA。
     *
     * @param float[] $src
     * @param int     $period ≥ 1
     * @return float[] 长度 = count($src)
     */
    public static function dema(array $src, int $period): array
    {
        return self::ma($src, $period, TRADER_MA_TYPE_DEMA);
    }

    /**
     * 三 EMA（TEMA，进一步降低滞后，适合短线趋势捕捉）。
     *
     * @param float[] $src
     * @param int     $period ≥ 1
     * @return float[] 长度 = count($src)
     */
    public static function tema(array $src, int $period): array
    {
        return self::ma($src, $period, TRADER_MA_TYPE_TEMA);
    }

    /**
     * 三角移动平均 TRIMA（先 SMA 再 SMA → 重心平滑，抗毛刺能力强，适合趋势跟踪）。
     *
     * @param float[] $src
     * @param int     $period ≥ 1
     * @return float[] 长度 = count($src)
     */
    public static function trima(array $src, int $period): array
    {
        return self::ma($src, $period, TRADER_MA_TYPE_TRIMA);
    }

    /**
     * 考夫曼自适应 MA（KAMA：根据效率比自动切换快慢，噪音小的时候跟随灵敏，震荡时平缓）。
     *
     * @param float[] $src
     * @param int     $period ≥ 1（常用 10 / 14 / 30）
     * @return float[] 长度 = count($src)
     */
    public static function kama(array $src, int $period): array
    {
        return self::ma($src, $period, TRADER_MA_TYPE_KAMA);
    }

    // ========================================================================
    //  B. 动量 / 震荡类（CMO / ROC / MOM / WILLR / CCI / STOCH* / ULTOSC / APO / PPO / MACD）
    // ========================================================================

    /**
     * 钱德动量摆动 CMO（Chande Momentum Oscillator）：
     *   CMO = (SumUp − SumDn) / (SumUp + SumDn) × 100，值域 [-100, +100]
     *   +50 / -50 为经典超买超卖。
     *
     * 前 period 根默认 0。
     *
     * @param float[] $src    收盘价
     * @param int     $period ≥ 2（常用 9 / 14 / 20）
     * @return float[] 长度 = count($src)，范围 [-100, 100]
     */
    public static function cmo(array $src, int $period): array
    {
        self::requireTraderExtension();
        $n = count($src);
        $out = array_fill(0, $n, 0.0);
        if ($n <= $period || $period < 2) {
            return $out;
        }
        $raw = \trader_cmo($src, $period);
        if ($raw !== false) {
            foreach ($raw as $idx => $val) {
                $v = (float) $val;
                $out[(int) $idx] = is_nan($v) ? 0.0 : $v;
            }
        }
        return $out;
    }

    /**
     * 变动率 ROC（Rate of Change %）：ROC = (price / priceNperiodsAgo − 1) × 100
     *
     * 前 period 根：用当前已有的 i+1 根近似（基于 i=0），避免异常 0 误判。
     *
     * @param float[] $src
     * @param int     $period ≥ 1（常用 10 / 12 / 20）
     * @return float[] 长度 = count($src)（单位 %）
     */
    public static function roc(array $src, int $period): array
    {
        self::requireTraderExtension();
        $n = count($src);
        $out = array_fill(0, $n, 0.0);
        if ($n === 0 || $period < 1) {
            return $out;
        }
        // 种子区：用 (src[i]/src[0] − 1) × 100 近似（合理、无除零）
        $base = (float) ($src[0] ?? 1.0);
        $seedEnd = min($period, $n);
        for ($i = 0; $i < $seedEnd; $i++) {
            $cur = (float) $src[$i];
            $out[$i] = $base == 0 ? 0.0 : (($cur / $base) - 1.0) * 100.0;
        }
        if ($n > $period) {
            $raw = \trader_roc($src, $period);
            if ($raw !== false) {
                foreach ($raw as $idx => $val) {
                    $v = (float) $val;
                    $out[(int) $idx] = is_nan($v) ? 0.0 : $v;
                }
            }
        }
        return $out;
    }

    /**
     * 动量 MOM（Momentum，绝对差）：MOM = price[i] − price[i−period]
     *
     * 前 period 根：近似 = price[i] − price[0]
     *
     * @param float[] $src
     * @param int     $period ≥ 1
     * @return float[] 长度 = count($src)
     */
    public static function mom(array $src, int $period): array
    {
        self::requireTraderExtension();
        $n = count($src);
        $out = array_fill(0, $n, 0.0);
        if ($n === 0 || $period < 1) {
            return $out;
        }
        $base = (float) ($src[0] ?? 0.0);
        $seedEnd = min($period, $n);
        for ($i = 0; $i < $seedEnd; $i++) {
            $out[$i] = ((float) $src[$i]) - $base;
        }
        if ($n > $period) {
            $raw = \trader_mom($src, $period);
            if ($raw !== false) {
                foreach ($raw as $idx => $val) {
                    $out[(int) $idx] = (float) $val;
                }
            }
        }
        return $out;
    }

    /**
     * 威廉指标 Williams %R（衡量当前收盘在最近 period 高低区间的位置）。
     *
     *   %R = (highestHigh − close) / (highestHigh − lowestLow) × −100，值域 [-100, 0]
     *   -20 以上 = 超买，-80 以下 = 超卖。
     *
     * 前 period-1 根默认 0（中性）。
     *
     * @param float[] $high
     * @param float[] $low
     * @param float[] $close
     * @param int     $period ≥ 2（常用 14）
     * @return float[] 长度 = count($close)，[-100, 0]
     */
    public static function willr(array $high, array $low, array $close, int $period): array
    {
        self::requireTraderExtension();
        $n = count($close);
        $out = array_fill(0, $n, 0.0);
        if ($n <= $period - 1 || $period < 2) {
            return $out;
        }
        $raw = \trader_willr($high, $low, $close, $period);
        if ($raw !== false) {
            foreach ($raw as $idx => $val) {
                $v = (float) $val;
                $out[(int) $idx] = is_nan($v) ? 0.0 : $v;
            }
        }
        return $out;
    }

    /**
     * 顺势指标 CCI（Commodity Channel Index）：
     *   CCI = (TypicalPrice − SMA(TypicalPrice)) / (0.015 × MeanDev)
     *   常用阈值 ±100（>100 强趋势、<-100 弱趋势），±200 为极端。
     *
     * 前 period-1 根默认 0。
     *
     * @param float[] $high
     * @param float[] $low
     * @param float[] $close
     * @param int     $period ≥ 2（常用 14 / 20）
     * @return float[] 长度 = count($close)
     */
    public static function cci(array $high, array $low, array $close, int $period): array
    {
        self::requireTraderExtension();
        $n = count($close);
        $out = array_fill(0, $n, 0.0);
        if ($n <= $period - 1 || $period < 2) {
            return $out;
        }
        $raw = \trader_cci($high, $low, $close, $period);
        if ($raw !== false) {
            foreach ($raw as $idx => $val) {
                $v = (float) $val;
                $out[(int) $idx] = is_nan($v) || is_infinite($v) ? 0.0 : $v;
            }
        }
        return $out;
    }

    /**
     * 随机指标 KDJ 的 KD 线（Stochastic SlowK + SlowD）。
     *
     *   FastK = (close − LowestLow) / (HighestHigh − LowestLow) × 100（0..100）
     *   SlowK = MA(fastKPeriod, SlowK_MAType) of FastK  （常 SMA(3) → K 线）
     *   SlowD = MA(slowDPeriod, SlowD_MAType) of SlowK   （常 SMA(3) → D 线）
     *   J 线 = 3K − 2D（策略侧自行算，因 trader 扩展未输出）
     *
     * 默认参数：fastK=14 / slowK=3 SMA / slowD=3 SMA（经典 KDJ(14,3,3)）。
     *
     * @param float[] $high
     * @param float[] $low
     * @param float[] $close
     * @param int     $fastKPeriod   ≥ 2（RSV 周期）
     * @param int     $slowKPeriod   ≥ 1（K 平滑周期）
     * @param int     $slowKMaType   TRADER_MA_TYPE_*，默认 SMA
     * @param int     $slowDPeriod   ≥ 1（D 平滑周期）
     * @param int     $slowDMaType   TRADER_MA_TYPE_*，默认 SMA
     * @return array{0:float[], 1:float[]} [slowK, slowD]，每个长度 = count($close)，值域 [0, 100]
     */
    public static function stoch(
        array $high,
        array $low,
        array $close,
        int $fastKPeriod = 14,
        int $slowKPeriod = 3,
        int $slowKMaType = TRADER_MA_TYPE_SMA,
        int $slowDPeriod = 3,
        int $slowDMaType = TRADER_MA_TYPE_SMA
    ): array {
        self::requireTraderExtension();
        $n = count($close);
        $k = array_fill(0, $n, 50.0);
        $d = array_fill(0, $n, 50.0);
        // 经验阈值：trader_stoch 需要 slowKPerid + slowDPeriod + fastKPeriod 足够多
        $minLen = $fastKPeriod + $slowKPeriod + $slowDPeriod - 2;
        if ($n <= max(0, $minLen - 1)) {
            return [$k, $d];
        }
        $raw = \trader_stoch($high, $low, $close, $fastKPeriod, $slowKPeriod, $slowKMaType, $slowDPeriod, $slowDMaType);
        if (is_array($raw) && isset($raw[0], $raw[1])) {
            foreach ($raw[0] as $idx => $kv) {
                $k[(int)$idx] = (float) $kv;
            }
            foreach ($raw[1] as $idx => $dv) {
                $d[(int)$idx] = (float) $dv;
            }
        }
        return [$k, $d];
    }

    /**
     * 快速随机指标（Stochastic Fast — FastK + FastD，无 SlowK 二次平滑，响应更快但噪音大）。
     *
     * @param float[] $high
     * @param float[] $low
     * @param float[] $close
     * @param int     $fastKPeriod  ≥ 2（RSV 周期，常用 14）
     * @param int     $fastDPeriod  ≥ 1（FastD 平均周期，常用 3）
     * @param int     $fastDMaType  TRADER_MA_TYPE_*
     * @return array{0:float[], 1:float[]} [fastK, fastD]，长度 = count($close)，[0, 100]
     */
    public static function stochf(
        array $high,
        array $low,
        array $close,
        int $fastKPeriod = 14,
        int $fastDPeriod = 3,
        int $fastDMaType = TRADER_MA_TYPE_SMA
    ): array {
        self::requireTraderExtension();
        $n = count($close);
        $k = array_fill(0, $n, 50.0);
        $d = array_fill(0, $n, 50.0);
        if ($n <= $fastKPeriod + $fastDPeriod - 2) {
            return [$k, $d];
        }
        $raw = \trader_stochf($high, $low, $close, $fastKPeriod, $fastDPeriod, $fastDMaType);
        if (is_array($raw) && isset($raw[0], $raw[1])) {
            foreach ($raw[0] as $idx => $kv) { $k[(int)$idx] = (float) $kv; }
            foreach ($raw[1] as $idx => $dv) { $d[(int)$idx] = (float) $dv; }
        }
        return [$k, $d];
    }

    /**
     * StochRSI：对 RSI 做一次 Stochastic（短线超买超卖更灵敏，捕捉极值更准）。
     *
     *   先算 RSI(period)，再对其算 STOCHF(fastK, fastD)。
     *
     * @param float[] $close
     * @param int     $rsiPeriod    ≥ 2（常用 14）
     * @param int     $fastKPeriod  ≥ 2（RSI 的 RSV 周期，常用 5/14）
     * @param int     $fastDPeriod  ≥ 1（常用 3）
     * @param int     $fastDMaType  TRADER_MA_TYPE_*
     * @return array{0:float[], 1:float[]} [stochFastK, stochFastD] 长度 = count($close)，[0, 100]
     */
    public static function stochRsi(
        array $close,
        int $rsiPeriod = 14,
        int $fastKPeriod = 5,
        int $fastDPeriod = 3,
        int $fastDMaType = TRADER_MA_TYPE_SMA
    ): array {
        self::requireTraderExtension();
        $n = count($close);
        $k = array_fill(0, $n, 50.0);
        $d = array_fill(0, $n, 50.0);
        if ($n <= $rsiPeriod + $fastKPeriod + $fastDPeriod - 2) {
            return [$k, $d];
        }
        $raw = \trader_stochrsi($close, $rsiPeriod, $fastKPeriod, $fastDPeriod, $fastDMaType);
        if (is_array($raw) && isset($raw[0], $raw[1])) {
            foreach ($raw[0] as $idx => $kv) { $k[(int)$idx] = (float) $kv; }
            foreach ($raw[1] as $idx => $dv) { $d[(int)$idx] = (float) $dv; }
        }
        return [$k, $d];
    }

    /**
     * 终极振荡器（Ultimate Oscillator，Larry Williams 发明，三周期加权避免单一周期误判）。
     *
     *   BuyPressure = close − min(low, prevClose)
     *   TrueRange   = max(high, prevClose) − min(low, prevClose)
     *   Avg7  = sum(BuyPressure tp1-7)  / sum(TR tp1-7)
     *   Avg14 = sum(BuyPressure tp1-14) / sum(TR tp1-14)
     *   Avg28 = sum(BuyPressure tp1-28) / sum(TR tp1-28)
     *   UltOsc = 100 × (4·Avg7 + 2·Avg14 + 1·Avg28) / (4+2+1)
     *   常用阈值 30（超卖）/ 70（超买）。
     *
     * 默认参数 (7, 14, 28) 与发明者推荐一致。前 period3-1 根默认 50。
     *
     * @param float[] $high
     * @param float[] $low
     * @param float[] $close
     * @param int     $tp1 ≥ 2（短）
     * @param int     $tp2 ≥ 2（中）
     * @param int     $tp3 ≥ 2（长）
     * @return float[] [0, 100]
     */
    public static function ultOsc(
        array $high,
        array $low,
        array $close,
        int $tp1 = 7,
        int $tp2 = 14,
        int $tp3 = 28
    ): array {
        self::requireTraderExtension();
        $n = count($close);
        $out = array_fill(0, $n, 50.0);
        if ($n <= $tp3 - 1) {
            return $out;
        }
        $raw = \trader_ultosc($high, $low, $close, $tp1, $tp2, $tp3);
        if ($raw !== false) {
            foreach ($raw as $idx => $val) {
                $v = (float) $val;
                $out[(int) $idx] = is_nan($v) ? 50.0 : $v;
            }
        }
        return $out;
    }

    /**
     * 绝对价格振荡 APO（Absolute Price Oscillator）：APO = MA_fast − MA_slow。
     * 注意 APO 用两个 MA 的**绝对差**（MACD 本质也是 APO(fastEMA,slowEMA) + signal）。
     * 正值表示短 MA 在长 MA 上方（偏多），负值反之。
     *
     * 前 max(fast,slow)−1 根默认 0。
     *
     * @param float[] $close
     * @param int     $fastPeriod  ≥ 1（常用 12）
     * @param int     $slowPeriod  ≥ 1（> fastPeriod，常用 26）
     * @param int     $maType      TRADER_MA_TYPE_*（默认 EMA，和 MACD 对齐）
     * @return float[]
     */
    public static function apo(
        array $close,
        int $fastPeriod = 12,
        int $slowPeriod = 26,
        int $maType = TRADER_MA_TYPE_EMA
    ): array {
        self::requireTraderExtension();
        $n = count($close);
        $out = array_fill(0, $n, 0.0);
        if ($n <= max($fastPeriod, $slowPeriod) - 1 || $fastPeriod < 1 || $slowPeriod <= $fastPeriod) {
            return $out;
        }
        $raw = \trader_apo($close, $fastPeriod, $slowPeriod, $maType);
        if ($raw !== false) {
            foreach ($raw as $idx => $val) {
                $out[(int) $idx] = (float) $val;
            }
        }
        return $out;
    }

    /**
     * 百分比价格振荡 PPO（Percentage Price Oscillator）：
     *   PPO = (EMA_fast − EMA_slow) / EMA_slow × 100（单位 %）
     * 相比 APO，PPO 是**百分比**，可跨不同价格水平的品种/历史阶段横向比较。
     *   +1% 表示短 EMA 比长 EMA 高 1%。
     *
     * 前 max(fast,slow)−1 根默认 0。
     *
     * @param float[] $close
     * @param int     $fastPeriod ≥ 1
     * @param int     $slowPeriod ≥ 1, > fastPeriod
     * @param int     $maType     TRADER_MA_TYPE_*（默认 EMA）
     * @return float[] 单位 %
     */
    public static function ppo(
        array $close,
        int $fastPeriod = 12,
        int $slowPeriod = 26,
        int $maType = TRADER_MA_TYPE_EMA
    ): array {
        self::requireTraderExtension();
        $n = count($close);
        $out = array_fill(0, $n, 0.0);
        if ($n <= max($fastPeriod, $slowPeriod) - 1 || $fastPeriod < 1 || $slowPeriod <= $fastPeriod) {
            return $out;
        }
        $raw = \trader_ppo($close, $fastPeriod, $slowPeriod, $maType);
        if ($raw !== false) {
            foreach ($raw as $idx => $val) {
                $out[(int) $idx] = (float) $val;
            }
        }
        return $out;
    }

    /**
     * MACD（Moving Average Convergence Divergence，Gerald Appel）。
     *
     *   macdLine     = EMA(fast, close) − EMA(slow, close)    —— DIF
     *   signalLine   = EMA(signalPeriod, macdLine)             —— DEA
     *   histogram    = macdLine − signalLine                   —— MACD 柱（两倍红绿柱 = 2·histogram）
     *
     * 默认参数：fast=12, slow=26, signal=9（最经典）。
     *
     * 前 (slow+signal−2) 根（trader 启动期）三条均填 0（避免启动期异常值干扰）。
     *
     * @param float[] $close
     * @param int     $fastPeriod   ≥ 1（常 12）
     * @param int     $slowPeriod   > fastPeriod（常 26）
     * @param int     $signalPeriod ≥ 1（常 9）
     * @return array{0:float[], 1:float[], 2:float[]} [macdLine, signalLine, histogram]，长度 = count($close)
     */
    public static function macd(
        array $close,
        int $fastPeriod = 12,
        int $slowPeriod = 26,
        int $signalPeriod = 9
    ): array {
        self::requireTraderExtension();
        $n = count($close);
        $macd   = array_fill(0, $n, 0.0);
        $signal = array_fill(0, $n, 0.0);
        $hist   = array_fill(0, $n, 0.0);
        $minLen = $slowPeriod + $signalPeriod - 1;
        if ($n < $minLen || $fastPeriod < 1 || $slowPeriod <= $fastPeriod || $signalPeriod < 1) {
            return [$macd, $signal, $hist];
        }
        $raw = \trader_macd($close, $fastPeriod, $slowPeriod, $signalPeriod);
        if (is_array($raw) && isset($raw[0], $raw[1], $raw[2])) {
            foreach ($raw[0] as $idx => $v) { $macd[(int)$idx]   = (float) $v; }
            foreach ($raw[1] as $idx => $v) { $signal[(int)$idx] = (float) $v; }
            foreach ($raw[2] as $idx => $v) { $hist[(int)$idx]   = (float) $v; }
        }
        return [$macd, $signal, $hist];
    }

    // ========================================================================
    //  C. 波动率 / 波幅类（VARIANCE / TRANGE / ATR / NATR）
    // ========================================================================

    /**
     * 滚动方差（样本无偏 ÷(n-1)）= stddev²。
     *
     * @param float[] $src
     * @param int     $period ≥ 2
     * @return float[] 长度 = count($src)
     */
    public static function variance(array $src, int $period): array
    {
        $s = self::stddev($src, $period);
        $out = [];
        foreach ($s as $i => $v) {
            $out[$i] = $v * $v;
        }
        return $out;
    }

    /**
     * True Range（每根 K 线真实波幅，不做平滑）：
     *   TR = max(high−low,  |high − prevClose|,  |low − prevClose|)
     *
     * 第 0 根（无前一根 close）填 high−low。
     *
     * @param float[] $high
     * @param float[] $low
     * @param float[] $close
     * @return float[] 长度 = count($close)（≥ 0）
     */
    public static function trange(array $high, array $low, array $close): array
    {
        self::requireTraderExtension();
        $n = count($close);
        $out = array_fill(0, $n, 0.0);
        if ($n === 0) {
            return $out;
        }
        // 第 0 根：trader_trange 只有 n-1 根（从 idx=1 开始），兜底自己算
        $out[0] = max(0.0, ((float) ($high[0] ?? 0.0)) - ((float) ($low[0] ?? 0.0)));
        if ($n >= 2) {
            $raw = \trader_trange($high, $low, $close);
            if ($raw !== false) {
                foreach ($raw as $idx => $val) {
                    $out[(int) $idx] = (float) $val;
                }
            }
        }
        return $out;
    }

    /**
     * 平均真实波幅 ATR（Wilder's smoothing，衡量品种"每日波动绝对幅度"）：
     *   ATR(i) = (ATR(i−1) × (period−1) + TR(i)) / period
     *
     * 前 period 根用 trange 的当前 i+1 根平均兜底；之后走 trader_atr。
     * 用途：动态止损距离（2×ATR）、仓位大小（凯利/固定分数）、波动率过滤。
     *
     * @param float[] $high
     * @param float[] $low
     * @param float[] $close
     * @param int     $period ≥ 2（常用 14）
     * @return float[] ≥ 0
     */
    public static function atr(array $high, array $low, array $close, int $period = 14): array
    {
        self::requireTraderExtension();
        $n = count($close);
        $out = array_fill(0, $n, 0.0);
        if ($n === 0 || $period < 2) {
            return $out;
        }
        // 种子区：tr(i) 的滚动平均（前 period 根）
        $tr = self::trange($high, $low, $close);
        $seedEnd = min($period, $n);
        $sum = 0.0;
        for ($i = 0; $i < $seedEnd; $i++) {
            $sum += $tr[$i];
            $out[$i] = $sum / ($i + 1);
        }
        if ($n > $period) {
            $raw = \trader_atr($high, $low, $close, $period);
            if ($raw !== false) {
                foreach ($raw as $idx => $val) {
                    $out[(int) $idx] = (float) $val;
                }
            }
        }
        return $out;
    }

    /**
     * 归一化 ATR（NATR = ATR / close × 100，单位 %）。
     * 跨品种可比（BTC 和 DOGE 绝对 ATR 天差地别，但 NATR 都在常见 1~5% 区间）。
     *
     * @param float[] $high
     * @param float[] $low
     * @param float[] $close
     * @param int     $period ≥ 2（常 14）
     * @return float[] 单位 %
     */
    public static function natr(array $high, array $low, array $close, int $period = 14): array
    {
        self::requireTraderExtension();
        $n = count($close);
        $out = array_fill(0, $n, 0.0);
        if ($n <= $period - 1 || $period < 2) {
            return $out;
        }
        $raw = \trader_natr($high, $low, $close, $period);
        if ($raw !== false) {
            foreach ($raw as $idx => $val) {
                $out[(int) $idx] = (float) $val;
            }
        }
        return $out;
    }

    // ========================================================================
    //  D. 趋势方向/强度类（ADX / ADXR / ±DI / AROON / AROONOSC / SAR）
    // ========================================================================

    /**
     * 平均趋向指数 ADX（Welles Wilder）：衡量"趋势强度"（不管多空）。
     *   0-25：无趋势 / 弱趋势； 25-50：强趋势； 50-75：非常强； 75+：极端。
     * 结合 +DI / -DI 判断方向。
     *
     * 前 2·period−2 根（启动期）默认 0。
     *
     * @param float[] $high
     * @param float[] $low
     * @param float[] $close
     * @param int     $period ≥ 2（常用 14）
     * @return float[] [0, 100]
     */
    public static function adx(array $high, array $low, array $close, int $period = 14): array
    {
        self::requireTraderExtension();
        $n = count($close);
        $out = array_fill(0, $n, 0.0);
        // trader_adx 启动需要约 2·period 根
        if ($n < 2 * $period - 1 || $period < 2) {
            return $out;
        }
        $raw = \trader_adx($high, $low, $close, $period);
        if ($raw !== false) {
            foreach ($raw as $idx => $val) {
                $out[(int) $idx] = (float) $val;
            }
        }
        return $out;
    }

    /**
     * ADXR = (ADX(i) + ADX(i−period+1)) / 2，对 ADX 做前后 period 跨度的平均，
     * 降低 ADX 抖动，更适合作为"趋势过滤器"（>25 才允许趋势型策略开仓）。
     *
     * @param float[] $high
     * @param float[] $low
     * @param float[] $close
     * @param int     $period ≥ 2（常 14）
     * @return float[] [0, 100]
     */
    public static function adxr(array $high, array $low, array $close, int $period = 14): array
    {
        self::requireTraderExtension();
        $n = count($close);
        $out = array_fill(0, $n, 0.0);
        if ($n < 3 * $period - 2 || $period < 2) {
            return $out;
        }
        $raw = \trader_adxr($high, $low, $close, $period);
        if ($raw !== false) {
            foreach ($raw as $idx => $val) {
                $out[(int) $idx] = (float) $val;
            }
        }
        return $out;
    }

    /**
     * +DI（+Directional Indicator，多头方向强度，0..100）。
     *   +DI > −DI 说明上行力更强，金叉可做多。
     *
     * 前 period 根默认 0。
     *
     * @param float[] $high
     * @param float[] $low
     * @param float[] $close
     * @param int     $period ≥ 2（常 14）
     * @return float[] [0, 100]
     */
    public static function plusDi(array $high, array $low, array $close, int $period = 14): array
    {
        self::requireTraderExtension();
        $n = count($close);
        $out = array_fill(0, $n, 0.0);
        if ($n <= $period || $period < 2) {
            return $out;
        }
        $raw = \trader_plus_di($high, $low, $close, $period);
        if ($raw !== false) {
            foreach ($raw as $idx => $val) {
                $out[(int) $idx] = (float) $val;
            }
        }
        return $out;
    }

    /**
     * −DI（空头方向强度，0..100）。
     *
     * @param float[] $high
     * @param float[] $low
     * @param float[] $close
     * @param int     $period ≥ 2
     * @return float[] [0, 100]
     */
    public static function minusDi(array $high, array $low, array $close, int $period = 14): array
    {
        self::requireTraderExtension();
        $n = count($close);
        $out = array_fill(0, $n, 0.0);
        if ($n <= $period || $period < 2) {
            return $out;
        }
        $raw = \trader_minus_di($high, $low, $close, $period);
        if ($raw !== false) {
            foreach ($raw as $idx => $val) {
                $out[(int) $idx] = (float) $val;
            }
        }
        return $out;
    }

    /**
     * 阿隆指标 Aroon（Tushar Chande 发明）：衡量"多久没创新高/新低"。
     *
     *   AroonUp   = ((period − periods_since_high) / period) × 100   —— 距上次 N 日新高
     *   AroonDown = ((period − periods_since_low)  / period) × 100   —— 距上次 N 日新低
     *   常用阈值：Up=100（刚创新高）、Down=100（刚创新低）、Up>70 且 Down<30 → 多头趋势。
     *
     * 前 period 根默认 50（中性）。
     *
     * @param float[] $high
     * @param float[] $low
     * @param int     $period ≥ 2（常用 14 / 25）
     * @return array{0:float[], 1:float[]} [aroonUp, aroonDown]，长度 = count($high)，[0, 100]
     */
    public static function aroon(array $high, array $low, int $period = 14): array
    {
        self::requireTraderExtension();
        $n = count($high);
        $up   = array_fill(0, $n, 50.0);
        $down = array_fill(0, $n, 50.0);
        if ($n <= $period || $period < 2) {
            return [$up, $down];
        }
        $raw = \trader_aroon($high, $low, $period);
        if (is_array($raw) && isset($raw[0], $raw[1])) {
            foreach ($raw[0] as $idx => $v) { $up[(int)$idx]   = (float) $v; }
            foreach ($raw[1] as $idx => $v) { $down[(int)$idx] = (float) $v; }
        }
        return [$up, $down];
    }

    /**
     * 阿隆振荡器 = AroonUp − AroonDown（−100..+100）。
     *   > 0 偏多、> +50 强势多头； < 0 偏空、< −50 强势空头。
     *
     * @param float[] $high
     * @param float[] $low
     * @param int     $period ≥ 2
     * @return float[] [-100, +100]
     */
    public static function aroonOsc(array $high, array $low, int $period = 14): array
    {
        self::requireTraderExtension();
        $n = count($high);
        $out = array_fill(0, $n, 0.0);
        if ($n <= $period || $period < 2) {
            return $out;
        }
        // 注意：PHP trader 扩展的 trader_aroonosc() 实现方向为 AroonDown − AroonUp
        // 但行业通用语义（与 +DI / −DI / APO 等一致）应该是：>0 → 偏多，<0 → 偏空，
        // 即 AroonOsc = AroonUp − AroonDown。这里直接用 aroon() 结果按正确公式重算，
        // 既避免了和扩展的方向冲突，也保证和 aroon() 输出完全一致的解析关系。
        [$up, $down] = self::aroon($high, $low, $period);
        for ($i = 0; $i < $n; $i++) {
            $out[$i] = $up[$i] - $down[$i];
        }
        return $out;
    }

    /**
     * 抛物线 SAR（Parabolic SAR，Stop And Reverse，Welles Wilder 发明）：
     *   逐根给出追踪止损/止盈价位——上涨时 SAR 在下、逐步抬高；
     *   反向穿越则切换多空。适合趋势市场做 trailing stop。
     *
     * 参数：
     *   acceleration（AF，加速因子）= 初值 0.02，创极值后 +0.02
     *   maximum（AF 上限）= 0.2（Wilder 默认）
     *
     * 第 0 根没有前向信息，填 close[0]（兜底）；从 1 开始走 trader_sar。
     *
     * @param float[] $high
     * @param float[] $low
     * @param float   $accelerationStep 步长（0.02 标准）
     * @param float   $accelerationMax  上限（0.2 标准）
     * @return float[] 长度 = count($high)
     */
    public static function sar(
        array $high,
        array $low,
        float $accelerationStep = 0.02,
        float $accelerationMax = 0.2
    ): array {
        self::requireTraderExtension();
        $n = count($high);
        $out = array_fill(0, $n, 0.0);
        if ($n === 0) {
            return $out;
        }
        // 第 0 根：trader_sar 从 idx=1 开始，兜底用 high[0]/low[0] 均值
        $out[0] = (((float) ($high[0] ?? 0.0)) + ((float) ($low[0] ?? 0.0))) / 2.0;
        if ($n >= 2) {
            $raw = \trader_sar($high, $low, $accelerationStep, $accelerationMax);
            if ($raw !== false) {
                foreach ($raw as $idx => $val) {
                    $out[(int) $idx] = (float) $val;
                }
            }
        }
        return $out;
    }

    // ========================================================================
    //  E. 量价类（MFI / OBV / AD / ADOSC）
    // ========================================================================

    /**
     * 资金流量指数 MFI（Money Flow Index）—— 带成交量的 RSI：
     *   MoneyFlow = TypicalPrice × Volume
     *   Positive/Negative MF 按 TypicalPrice 增减归类
     *   MFI = 100 − 100 / (1 + SumPosMF / SumNegMF)   （值域 [0, 100]）
     *   阈值 80/20 常用作超买超卖，也用于量价背离检测。
     *
     * 前 period 根默认 50。
     *
     * @param float[] $high
     * @param float[] $low
     * @param float[] $close
     * @param float[] $volume
     * @param int     $period ≥ 2（常 14）
     * @return float[] [0, 100]
     */
    public static function mfi(array $high, array $low, array $close, array $volume, int $period = 14): array
    {
        self::requireTraderExtension();
        $n = count($close);
        $out = array_fill(0, $n, 50.0);
        if ($n <= $period || $period < 2) {
            return $out;
        }
        $raw = \trader_mfi($high, $low, $close, $volume, $period);
        if ($raw !== false) {
            foreach ($raw as $idx => $val) {
                $v = (float) $val;
                $out[(int) $idx] = is_nan($v) ? 50.0 : $v;
            }
        }
        return $out;
    }

    /**
     * 能量潮 OBV（On Balance Volume，Granville 发明）：
     *   涨时累加 volume，跌时减去 volume（收盘未变则不变）。
     *   OBV 领先价格出现背离（价格新高但 OBV 未新高 → 顶背离；反之底背离）。
     *
     * 第 0 根 = volume[0]（与 trader_obv 行为一致）。
     *
     * @param float[] $close
     * @param float[] $volume
     * @return float[] 累计成交量（可正可负）
     */
    public static function obv(array $close, array $volume): array
    {
        self::requireTraderExtension();
        $n = count($close);
        $out = array_fill(0, $n, 0.0);
        if ($n === 0) {
            return $out;
        }
        $raw = \trader_obv($close, $volume);
        if ($raw !== false) {
            foreach ($raw as $idx => $val) {
                $out[(int) $idx] = (float) $val;
            }
        }
        return $out;
    }

    /**
     * Chaikin 累积/派发线（A/D Line，Marc Chaikin 发明）：
     *   MoneyFlowMultiplier = ((close − low) − (high − close)) / (high − low)  (∈[-1, +1])
     *   MoneyFlowVolume = MFM × volume
     *   A/D = 累加 MFV
     * 收盘价越靠近 high，当日归"累积（买盘主导）"；越靠近 low=派发；收盘在区间中点=0。
     *
     * 长度对齐：trader_ad 输出从 0 开始（逐根累加），可直接填全。
     *
     * @param float[] $high
     * @param float[] $low
     * @param float[] $close
     * @param float[] $volume
     * @return float[]
     */
    public static function ad(array $high, array $low, array $close, array $volume): array
    {
        self::requireTraderExtension();
        $n = count($close);
        $out = array_fill(0, $n, 0.0);
        if ($n === 0) {
            return $out;
        }
        $raw = \trader_ad($high, $low, $close, $volume);
        if ($raw !== false) {
            foreach ($raw as $idx => $val) {
                $out[(int) $idx] = (float) $val;
            }
        }
        return $out;
    }

    /**
     * Chaikin A/D 振荡器（Chaikin Oscillator）= EMA(fast, A/D) − EMA(slow, A/D)。
     * 相比 A/D 本身，A/D Osc 是"差值"，更易看金叉/死叉，常配合 MACD 二次确认。
     *
     * 默认参数 fast=3, slow=10（Chaikin 原推荐）。
     * 前 max(fast,slow)−1 根默认 0。
     *
     * @param float[] $high
     * @param float[] $low
     * @param float[] $close
     * @param float[] $volume
     * @param int     $fastPeriod ≥ 1
     * @param int     $slowPeriod > fastPeriod
     * @return float[]
     */
    public static function adOsc(
        array $high,
        array $low,
        array $close,
        array $volume,
        int $fastPeriod = 3,
        int $slowPeriod = 10
    ): array {
        self::requireTraderExtension();
        $n = count($close);
        $out = array_fill(0, $n, 0.0);
        if ($n < $slowPeriod || $fastPeriod < 1 || $slowPeriod <= $fastPeriod) {
            return $out;
        }
        $raw = \trader_adosc($high, $low, $close, $volume, $fastPeriod, $slowPeriod);
        if ($raw !== false) {
            foreach ($raw as $idx => $val) {
                $out[(int) $idx] = (float) $val;
            }
        }
        return $out;
    }

    // ========================================================================
    //  F. 典型价 / 价格合成（AVGPRICE / TYPPRICE / WCLPRICE / MEDPRICE）
    // ========================================================================

    /**
     * 均价（Average Price）= (Open + High + Low + Close) / 4。
     * 相比单根 Close 更稳健，显著减少毛刺噪音（推荐用它代替 close 算各类均线）。
     *
     * 长度对齐：trader_avgprice 从 idx=0 开始产出所有值。
     *
     * @param float[] $open
     * @param float[] $high
     * @param float[] $low
     * @param float[] $close
     * @return float[] 长度 = count($close)
     */
    public static function avgPrice(array $open, array $high, array $low, array $close): array
    {
        self::requireTraderExtension();
        $n = count($close);
        $out = array_fill(0, $n, 0.0);
        if ($n === 0) {
            return $out;
        }
        $raw = \trader_avgprice($open, $high, $low, $close);
        if ($raw !== false) {
            foreach ($raw as $idx => $val) {
                $out[(int) $idx] = (float) $val;
            }
        }
        return $out;
    }

    /**
     * 典型价 Typical Price = (High + Low + Close) / 3。
     * 经典指标（CCI/Aroon/MFI 等内部都用它）。
     *
     * @param float[] $high
     * @param float[] $low
     * @param float[] $close
     * @return float[]
     */
    public static function typPrice(array $high, array $low, array $close): array
    {
        self::requireTraderExtension();
        $n = count($close);
        $out = array_fill(0, $n, 0.0);
        if ($n === 0) {
            return $out;
        }
        $raw = \trader_typprice($high, $low, $close);
        if ($raw !== false) {
            foreach ($raw as $idx => $val) {
                $out[(int) $idx] = (float) $val;
            }
        }
        return $out;
    }

    /**
     * 加权收盘价 Weighted Close Price = (High + Low + 2·Close) / 4。
     * 收盘价权重加倍，保留 close 的方向信息、同时平滑 H-L 冲击。
     *
     * @param float[] $high
     * @param float[] $low
     * @param float[] $close
     * @return float[]
     */
    public static function wclPrice(array $high, array $low, array $close): array
    {
        self::requireTraderExtension();
        $n = count($close);
        $out = array_fill(0, $n, 0.0);
        if ($n === 0) {
            return $out;
        }
        $raw = \trader_wclprice($high, $low, $close);
        if ($raw !== false) {
            foreach ($raw as $idx => $val) {
                $out[(int) $idx] = (float) $val;
            }
        }
        return $out;
    }

    /**
     * 中间价 Median Price = (High + Low) / 2。
     * 最简单的"真实成交重心估计"，无需 O/C 参与，仅看 H-L。
     *
     * @param float[] $high
     * @param float[] $low
     * @return float[]
     */
    public static function medPrice(array $high, array $low): array
    {
        self::requireTraderExtension();
        $n = count($high);
        $out = array_fill(0, $n, 0.0);
        if ($n === 0) {
            return $out;
        }
        $raw = \trader_medprice($high, $low);
        if ($raw !== false) {
            foreach ($raw as $idx => $val) {
                $out[(int) $idx] = (float) $val;
            }
        }
        return $out;
    }
}
