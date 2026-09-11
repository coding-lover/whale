<?php

namespace Sikelan\Security;

/**
 * 密码哈希器（bcrypt）
 *
 * 职责：使用 PHP 内置 password_hash (PASSWORD_BCRYPT) 做密码哈希与校验，
 * 支持 cost 参数调优与 needs_rehash 检测（用于哈希策略升级后自动重算）。
 *
 * 设计参考：Laravel `Hash::make` / `Hash::check` / `Hash::needsRehash`
 *
 * 协程安全：纯静态方法、无可变静态状态。
 */
class Hasher
{
    /**
     * 生成密码哈希
     *
     * @param string $plain  明文密码
     * @param array  $options 可选 ['cost' => int]，未指定时从 config('security.bcrypt_cost') 读取
     * @return string bcrypt 哈希字符串（60 字符）
     * @throws \RuntimeException password_hash 失败时抛异常（带上下文）
     */
    public static function make(string $plain, array $options = []): string
    {
        $cost = $options['cost'] ?? self::defaultCost();
        $hash = password_hash($plain, PASSWORD_BCRYPT, ['cost' => $cost]);
        // password_hash 失败返回 false（极少见，通常是 PHP 内部错误）
        if ($hash === false) {
            // 上下文信息拼进 message（RuntimeException 第二参数是 int code，不能传数组）
            throw new \RuntimeException(
                'bcrypt password_hash failed (plain_length=' . strlen($plain) . ', cost=' . $cost . ')'
            );
        }
        return $hash;
    }

    /**
     * 校验明文密码与哈希是否匹配
     *
     * 注意：哈希字符串长度恒定（60 字符），不依赖 timing-safe 比较——password_verify
     * 内部已用 timing-safe 比较防止时序攻击。
     *
     * @param string $plain 明文密码
     * @param string $hash  哈希字符串
     * @return bool 匹配返回 true
     */
    public static function verify(string $plain, string $hash): bool
    {
        // 哈希格式不合法时 password_verify 返回 false；不抛异常
        return password_verify($plain, $hash);
    }

    /**
     * 检测哈希是否需要重算
     *
     * 用于：cost 升级、算法变更后，在用户登录时自动重算哈希。
     *
     * @param string $hash    现有哈希
     * @param array  $options 可选 ['cost' => int]
     * @return bool 需要重算返回 true
     */
    public static function needsRehash(string $hash, array $options = []): bool
    {
        $cost = $options['cost'] ?? self::defaultCost();
        return password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => $cost]);
    }

    /**
     * 读取默认 bcrypt cost：从 config('security.bcrypt_cost') 取，失败兜底 10
     */
    private static function defaultCost(): int
    {
        // 测试或非容器环境下 config() 可能不存在，安全降级
        if (function_exists('config')) {
            try {
                $cost = config('security.bcrypt_cost', 10);
                if (is_numeric($cost) && $cost >= 4 && $cost <= 31) {
                    return (int) $cost;
                }
            } catch (\Throwable $e) {
                // 配置读取异常：用内置默认值
            }
        }
        return 10;
    }
}
