<?php

namespace Sikelan\Security;

/**
 * 输入净化器
 *
 * 职责：对 GET/POST/Cookie/路由参数做净化，去除 null byte 与控制字符；
 * 并提供按类型的转换与校验（校验失败返回 null，由调用方决定兜底值）。
 *
 * 设计参考：
 * - Symfony ParameterBag::filter（基于 filter_var 的类型校验）
 * - Laravel Request::input（统一入口 + 类型转换）
 *
 * 协程安全：纯静态方法、无可变静态状态，多协程并发安全。
 */
class InputSanitizer
{
    /**
     * 净化值：递归处理数组；字符串去除 null byte 与控制字符（保留 \t \n \r）
     *
     * @param mixed $value 原始值
     * @return mixed 净化后的值（保持原结构）
     */
    public static function clean($value)
    {
        // 数组：递归处理每个元素
        if (is_array($value)) {
            return array_map([self::class, 'clean'], $value);
        }
        // 非字符串：原样返回（int/float/bool/null 不需要净化）
        if (!is_string($value)) {
            return $value;
        }
        // 去除 null byte（防止截断攻击 / 文件名注入）
        $value = str_replace("\0", '', $value);
        // 去除控制字符（保留 \t=\x09 \n=\x0A \r=\x0D，业务上可能有意义）
        // 范围：\x00-\x08（已含 \0 但已被替换）, \x0B, \x0C, \x0E-\x1F, \x7F（DEL）
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
    }

    /**
     * 类型转换 + 校验
     *
     * 校验失败返回 null（不抛异常），由调用方决定是否用 default 兜底。
     *
     * @param mixed  $value 原始值
     * @param string $type  int|float|bool|string|email|url|alpha|alnum
     * @return mixed 转换后的值；校验失败返回 null
     */
    public static function cast($value, string $type)
    {
        switch ($type) {
            case 'int':
                // is_numeric 兼容 "123" / "1.5" / 123；强转 int 时 "1.5" → 1
                return is_numeric($value) ? (int) $value : null;

            case 'float':
                return is_numeric($value) ? (float) $value : null;

            case 'bool':
                // 兼容字符串 "1"/"true"/"yes"/"on" 与原生 bool
                if (is_bool($value)) {
                    return $value;
                }
                return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);

            case 'string':
                // 数组/对象不能强转字符串
                return is_array($value) || is_object($value) ? null : (string) $value;

            case 'email':
                // 先 sanitize（去非法字符）再 validate
                $v = filter_var($value, FILTER_SANITIZE_EMAIL);
                return filter_var($v, FILTER_VALIDATE_EMAIL) !== false ? $v : null;

            case 'url':
                $v = filter_var($value, FILTER_SANITIZE_URL);
                return filter_var($v, FILTER_VALIDATE_URL) !== false ? $v : null;

            case 'alpha':
                // 仅保留 a-zA-Z
                return preg_replace('/[^a-zA-Z]/', '', (string) $value);

            case 'alnum':
                // 仅保留 a-zA-Z0-9
                return preg_replace('/[^a-zA-Z0-9]/', '', (string) $value);

            default:
                // 未知类型：原样返回，由调用方决定
                return $value;
        }
    }
}
