<?php

namespace Sikelan\Core;

/**
 * Composer 脚本：在 autoload dump 后调整加载顺序。
 *
 * 确保 sikelan/Core/env.php 先于 illuminate/support/helpers.php 加载，
 * 让框架的 env() 优先定义（illuminate 的 env() 有 function_exists 守卫会跳过）。
 */
class ComposerScripts
{
    /**
     * 将框架 env.php 移到 autoload_files.php / autoload_static.php 首位。
     * 由 composer.json 的 post-autoload-dump 事件触发。
     */
    public static function prioritizeEnv(): void
    {
        $vendorDir = __DIR__ . '/../../vendor/composer';

        foreach (['autoload_files.php', 'autoload_static.php'] as $name) {
            $file = $vendorDir . '/' . $name;
            if (file_exists($file)) {
                self::moveToTop($file, '/sikelan/Core/env.php');
            }
        }
    }

    /**
     * 将包含 $needle 路径的行移到数组声明后的第一行。
     *
     * @param string $file   autoload 文件路径
     * @param string $needle 要移动的路径片段
     */
    private static function moveToTop(string $file, string $needle): void
    {
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        $targetIdx = -1;
        $arrayIdx  = -1;

        foreach ($lines as $idx => $line) {
            if ($targetIdx === -1 && strpos($line, $needle) !== false) {
                $targetIdx = $idx;
            }
            if ($arrayIdx === -1 && preg_match('/array\s*\(/', $line) === 1) {
                $arrayIdx = $idx;
            }
            if ($targetIdx !== -1 && $arrayIdx !== -1) {
                break;
            }
        }

        if ($targetIdx === -1 || $arrayIdx === -1) {
            return;
        }

        $targetLine = $lines[$targetIdx];
        // 移除目标行
        array_splice($lines, $targetIdx, 1);

        // 如果目标行在 array 声明之前，移除后 arrayIdx 不变；否则减 1
        if ($targetIdx < $arrayIdx) {
            $arrayIdx--;
        }

        // 插入到 array 声明之后
        array_splice($lines, $arrayIdx + 1, 0, [$targetLine]);

        file_put_contents($file, implode("\n", $lines) . "\n");
    }
}
