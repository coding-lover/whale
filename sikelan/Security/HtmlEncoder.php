<?php

namespace Sikelan\Security;

/**
 * HTML 输出转义器
 *
 * 职责：把任意值安全地渲染为 HTML 文本，防止 XSS。
 *
 * 设计参考：
 * - Laravel `e()` 助手：默认 ENT_QUOTES + ENT_SUBSTITUTE + UTF-8
 * - Symfony HtmlSanitizer：可选递归转义数据结构中的字符串字段
 *
 * 协程安全：纯静态方法、无可变静态状态。
 */
class HtmlEncoder
{
    /**
     * 默认转义 flag：ENT_QUOTES（单双引号都转）+ ENT_SUBSTITUTE（无效 UTF-8 用替代符而不是空）+ ENT_HTML401
     */
    public const DEFAULT_FLAGS = ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401;

    /**
     * HTML 转义单个值
     *
     * @param mixed      $value  任意值（非字符串会被强转）
     * @param int|null   $flags  htmlspecialchars flags，默认 ENT_QUOTES|ENT_SUBSTITUTE
     * @return string 转义后的安全 HTML 文本
     */
    public static function encode($value, ?int $flags = null): string
    {
        $flags = $flags ?? self::DEFAULT_FLAGS;
        // htmlspecialchars 默认 UTF-8 编码（PHP 5.4+），$double_encode=true 防止重复编码丢失
        return htmlspecialchars((string) $value, $flags, 'UTF-8', true);
    }

    /**
     * 递归转义数据结构中的字符串字段
     *
     * 用于 JSON 响应的可选 HTML 转义模式：
     * 把所有字符串字段做 htmlspecialchars，但保持数组/对象结构不变。
     * 适合把 JSON 直接渲染到前端 HTML 场景的彻底防 XSS。
     *
     * @param mixed    $data  原始数据（数组/对象/标量）
     * @param int|null $flags htmlspecialchars flags
     * @return mixed 结构不变，但所有字符串值被 HTML 转义
     */
    public static function encodeJsonStrings($data, ?int $flags = null)
    {
        // 字符串：直接转义
        if (is_string($data)) {
            return self::encode($data, $flags);
        }
        // 数组：递归处理每个元素（保持索引/关联结构）
        if (is_array($data)) {
            return array_map([self::class, 'encodeJsonStrings'], $data);
        }
        // 对象：转属性数组，再重新封装回对象（保持类型）
        if (is_object($data)) {
            return (object) self::encodeJsonStrings(get_object_vars($data));
        }
        // 标量（int/float/bool/null）：原样返回
        return $data;
    }
}
