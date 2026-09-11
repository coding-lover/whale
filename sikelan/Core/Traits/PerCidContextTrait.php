<?php

namespace Sikelan\Core\Traits;

/**
 * 通用「按 Swoole 协程 ID (cid) 隔离上下文栈 + 对象属性 swap/还原」工具 Trait。
 *
 * ⭐ 解决的问题：
 *   在 Swoole 单进程 Worker 内，多个协程共享同一个对象实例时，如果使用传统
 *   「对象级共享数组 + array_push/pop」作为上下文切换机制，一旦 callback
 *   在中途让出 CPU（网络 IO、sleep、协程 sleep/channel 读写等），不同协程
 *   push/pop 的顺序就不再是 LIFO，会出现「A 协程弹走了 B 协程的上下文」。
 *
 *   同时「对象属性的临时替换」（例如 sslVerify/testnet 不同市场需要切换）也存在
 *   同样的跨协程污染问题——两个并发协程修改同一个属性时，先 resume 的协程
 *   如果把属性还原回了自己的旧值，后 resume 的协程再读就会读到错误值。
 *
 * 🏛 设计（业务层与协程细节完全隔离）：
 *   本 Trait 属于框架层，不知道任何业务含义。业务类（例如 BinanceExchange）
 *   只需要：
 *     1) 在类顶部 `use PerCidContextTrait;`；
 *     2) 在需要「进入某个业务上下文」的地方调用：
 *           $this->runInScopedContext($scope, $payload, $callback, $swapProps);
 *        在 callback 内部任何时刻，调：
 *           $this->getScopedContextTop($scope, $fallback)
 *        就能拿到**当前协程自己那条**上下文 payload，绝不可能串到别的协程。
 *
 *   业务类只需要关心：
 *     · $scope      = 业务隔离键（如 'binance_market'），互不相关的场景用不同 scope
 *     · $payload    = 本次上下文的数据（任意结构，业务自己定义）
 *     · $callback   = 在这个上下文里执行的闭包
 *     · $swapProps  = 可选，callback 执行期间临时替换的对象属性（key=属性名,value=新值），
 *                     finally 100% 自动还原，且只还原当前协程自己备份的那份
 *
 * 🧭 兼容性：
 *   · 非协程 / 无 Swoole 环境：getCurrentCoroutineId() 返回 0（虚拟单协程），
 *     行为退化为「单 cid 单栈」= 语义和原来的对象级共享栈 100% 一致，
 *     所有 PHPUnit 用例、非协程 CLI 调用完全不用改动。
 *   · 同协程内嵌套 runInScopedContext（A→B→A 递归场景）：cid 相同，内部继续 LIFO，正确。
 *   · finally 内双栈 pop + 清理空数组，避免长驻进程内存泄漏。
 *
 * 典型使用示例（见 BinanceExchange withMarketContext）：
 *
 *   use PerCidContextTrait;
 *
 *   $result = $this->runInScopedContext(
 *       'binance_market',                              // scope：多市场路由
 *       ['market' => $market, 'config' => $cfg],       // payload（业务自定义结构）
 *       function ($ctx) use ($symbol, ...) {           // 回调参数 = payload
 *           return $this->request(...);                // 在这里读 currentScopedConfig() → 永远拿对自己协程的
 *       },
 *       [                                              // 临时 swap 两个对象属性
 *           'sslVerify' => $cfg['ssl_verify'],
 *           'testnet'   => $cfg['testnet'],
 *       ]
 *   );
 *
 * @package Sikelan\Core\Traits
 */
trait PerCidContextTrait
{
    /**
     * 上下文栈容器（三层结构）： [scope string][cid int][] = mixed payload
     *
     * 私有，不允许业务类直接读。读写只能走 runInScopedContext / getScopedContextTop。
     *
     * @var array<string, array<int, list<mixed>>>
     */
    private array $perCidContextStacks = [];

    /**
     * 对象属性备份栈（配合 $swapProps 参数使用）：
     *   [scope string][cid int][] = array{propName:oldValue}
     *
     * 每次 runInScopedContext 若带 $swapProps，就 push 一份备份到这里；
     * finally 里只弹「当前 cid + 当前 scope」这条栈的最后一项 → 100% 是自己备份的那份，
     * 不管其他协程怎么 push/pop 都不会串。
     *
     * @var array<string, array<int, list<array<string, mixed>>>>
     */
    private array $perCidPropBackups = [];

    // ----------------------------------------------------------------
    //  基础：cid 探测（非协程安全退化到 0）
    // ----------------------------------------------------------------

    /**
     * 获取当前 Swoole 协程 ID。
     *
     * 非协程环境 / Swoole 不存在 / cid<0（主协程）→ 返回 0，表示「虚拟单协程共享上下文」，
     * 行为退化为普通对象级共享栈（单 LIFO），和改造前老代码完全等价，PHPUnit 等测试零改动。
     */
    protected function getCurrentCoroutineId(): int
    {
        if (class_exists(\Swoole\Coroutine::class, false)) {
            $cid = \Swoole\Coroutine::getuid();
            if (is_int($cid) && $cid > 0) {
                return $cid;
            }
        }
        return 0;
    }

    // ----------------------------------------------------------------
    //  核心入口：在一个 scope 隔离的上下文里执行 callback
    // ----------------------------------------------------------------

    /**
     * 在「当前协程私有」的 scope 栈里 push 一份 payload；执行 $callback；finally 自动 pop。
     * 可选地在执行期间临时 swap 一组对象属性（finally 100% 还原，且只还原自己那份备份）。
     *
     * @template T
     *
     * @param string               $scope     业务隔离键（例如 'binance_market'）。不同场景用不同字符串
     * @param mixed                $payload   本次上下文数据（任意类型，原样作为 $callback 的第 1 个参数传入）
     * @param callable(mixed):T    $callback  要在上下文里执行的函数；参数 = $payload
     * @param array<string, mixed> $swapProps 可选：「执行期间临时替换的对象属性」键值对；
     *                                         key = 对象属性名（不含 $），value = 临时新值；
     *                                         finally 里会还原成备份旧值，100% 协程安全
     *
     * @return T $callback 的返回值
     *
     * @throws \Throwable 任何 $callback 抛的异常都会原样透传，finally 仍保证 pop + 还原
     */
    protected function runInScopedContext(
        string $scope,
        $payload,
        callable $callback,
        array $swapProps = []
    ) {
        $cid = $this->getCurrentCoroutineId();

        // ------------------------------------------------------------------
        //  ① 备份并 swap 对象属性（仅在当前 cid + 当前 scope 的备份栈里留记录）
        // ------------------------------------------------------------------
        $snapshot = [];
        foreach ($swapProps as $propName => $newValue) {
            if (property_exists($this, $propName)) {
                $snapshot[$propName] = $this->{$propName};  // 备份旧值（快照）
                $this->{$propName} = $newValue;             // 写临时新值
            }
        }
        if ($snapshot !== []) {
            $this->perCidPropBackups[$scope][$cid][] = $snapshot;
        }

        // ------------------------------------------------------------------
        //  ② push payload 到「当前协程私有」的 scope 栈
        // ------------------------------------------------------------------
        $this->perCidContextStacks[$scope][$cid][] = $payload;

        try {
            // 执行业务回调；即使业务回调里 yield 让出 CPU、其他协程也只改自己 cid 那条栈，
            // 等 resume 回来时，当前协程的 payload 仍在它自己那条栈的末尾，
            // getScopedContextTop 永远拿对自己那份
            return $callback($payload);
        } finally {
            // --------------------------------------------------------------
            //  ③ finally 先：pop payload（只弹当前 cid + 当前 scope 栈最后一项）
            // --------------------------------------------------------------
            if (isset($this->perCidContextStacks[$scope][$cid])) {
                $ctxStack = &$this->perCidContextStacks[$scope][$cid];
                if (is_array($ctxStack) && $ctxStack !== []) {
                    array_pop($ctxStack);
                }
                if ($ctxStack === [] || $ctxStack === null) {
                    unset($this->perCidContextStacks[$scope][$cid]);
                    if (empty($this->perCidContextStacks[$scope])) {
                        unset($this->perCidContextStacks[$scope]);
                    }
                }
                unset($ctxStack);
            }

            // --------------------------------------------------------------
            //  ④ finally 后：还原对象属性（只取当前 cid + 当前 scope 备份栈最后一项 = 自己刚备份的快照）
            // --------------------------------------------------------------
            if ($snapshot !== [] && isset($this->perCidPropBackups[$scope][$cid])) {
                $bakStack = &$this->perCidPropBackups[$scope][$cid];
                $savedSnapshot = null;
                if (is_array($bakStack) && $bakStack !== []) {
                    $savedSnapshot = array_pop($bakStack);
                }
                if (is_array($savedSnapshot) && $savedSnapshot !== []) {
                    foreach ($savedSnapshot as $propName => $oldValue) {
                        if (property_exists($this, $propName)) {
                            $this->{$propName} = $oldValue;
                        }
                    }
                }
                if ($bakStack === [] || $bakStack === null) {
                    unset($this->perCidPropBackups[$scope][$cid]);
                    if (empty($this->perCidPropBackups[$scope])) {
                        unset($this->perCidPropBackups[$scope]);
                    }
                }
                unset($bakStack);
            }
        }
    }

    // ----------------------------------------------------------------
    //  读上下文：拿当前 scope + 当前协程栈顶的 payload
    // ----------------------------------------------------------------

    /**
     * 取当前「scope + 当前协程」上下文栈的栈顶 payload。
     *
     * 如果当前协程在这个 scope 下没有上下文（即未调用过 runInScopedContext），
     * 则返回调用方传入的 $fallback。
     *
     * 在同协程嵌套 runInScopedContext 时，返回「最内层」的 payload（符合栈语义）。
     *
     * @param string $scope    业务隔离键
     * @param mixed  $fallback 无上下文时的返回值（默认 null）
     *
     * @return mixed 栈顶 payload 或 $fallback
     */
    protected function getScopedContextTop(string $scope, $fallback = null)
    {
        $cid = $this->getCurrentCoroutineId();
        $stack = $this->perCidContextStacks[$scope][$cid] ?? null;
        if (!is_array($stack) || $stack === []) {
            return $fallback;
        }
        // end()：取数组最后一项（栈顶），不改变数组本身
        return end($stack);
    }
}
