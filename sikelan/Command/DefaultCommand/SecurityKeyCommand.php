<?php

namespace Sikelan\Command\DefaultCommand;

use Sikelan\Command\CommandInterface;
use Sikelan\Security\Crypto;

/**
 * 命令：security:key
 *
 * 生成 APP_KEY 并写入 .env（已存在则覆盖）。
 *
 * APP_KEY 是 AES-256-GCM 加解密的密钥来源（见 \Sikelan\Security\Crypto），
 * 32 字节随机数 base64 编码后写入 .env 的 APP_KEY 行。
 *
 * 用法：
 *   php bin/sikelan security:key
 *
 * 安全提示：
 *   - 生成新 APP_KEY 后，之前用旧 APP_KEY 加密的数据将无法解密
 *   - 生产环境切换 APP_KEY 前请先迁移历史加密数据
 *   - .env 文件不应提交到版本控制
 */
class SecurityKeyCommand implements CommandInterface
{
    public function commandName(): string
    {
        return 'security:key';
    }

    public function desc(): string
    {
        return '生成 APP_KEY 并写入 .env（已存在则覆盖）';
    }

    public function exec(array $args): ?string
    {
        unset($args); // 本命令不接受额外参数

        // 1. 生成新的 32 字节 base64 密钥
        $key = Crypto::generateKey();

        // 2. 定位 .env 文件（BASE_PATH 在 constants.php 定义）
        $envFile = $this->resolveEnvFile();
        if ($envFile === null) {
            return "\033[31mError: .env file not found. "
                . "Please create .env first (copy from .env.example).\033[0m";
        }

        // 3. 读取 .env 内容，决定是替换已有 APP_KEY 还是追加
        $content = (string) file_get_contents($envFile);
        $newLine = "APP_KEY={$key}";

        if (preg_match('/^APP_KEY=.*$/m', $content)) {
            // 已存在 APP_KEY：替换该行
            $content = preg_replace('/^APP_KEY=.*$/m', $newLine, $content);
            $action = 'replaced';
        } else {
            // 不存在：追加到文件末尾（确保前面有换行）
            $content = rtrim($content) . "\n" . $newLine . "\n";
            $action = 'added';
        }

        // 4. 写回 .env
        $bytes = file_put_contents($envFile, $content);
        if ($bytes === false) {
            return "\033[31mError: failed to write {$envFile} (permission denied?).\033[0m";
        }

        // 5. 输出结果（不打印 key 本身，避免被终端日志记录泄漏）
        return "\033[32m✓ APP_KEY {$action} in {$envFile}\033[0m\n"
            . "  Key: base64, 32 bytes (AES-256)\n"
            . "  Length: " . strlen($key) . " chars\n"
            . "  Hint: view it via `grep APP_KEY .env`\n"
            . "\n"
            . "  \033[33m⚠ Warning:\033[0m data encrypted with the old APP_KEY cannot be decrypted now.\n"
            . "  Migrate existing encrypted data BEFORE rotating the key in production.";
    }

    public function help(array $args): ?string
    {
        return <<<HELP
Security Key Command

Usage:
  php bin/sikelan security:key

Description:
  Generate a 32-byte base64 APP_KEY and write it to .env.
  If APP_KEY already exists in .env, it will be replaced.

  The key is used by \Sikelan\Security\Crypto for AES-256-GCM encryption.

Examples:
  php bin/sikelan security:key
  # ✓ APP_KEY replaced in /path/to/.env
  #   Key: base64, 32 bytes (AES-256)

Notes:
  - Run this command once during project setup.
  - Rotating the key in production invalidates previously encrypted data.
  - Never commit .env to version control.

HELP;
    }

    /**
     * 定位 .env 文件路径
     *
     * @return string|null 找到返回路径，找不到返回 null
     */
    private function resolveEnvFile(): ?string
    {
        // BASE_PATH 在 constants.php 定义；CLI 入口已通过 Bootstrap::cli() 加载
        $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 3);
        $envFile = $basePath . '/.env';

        return file_exists($envFile) ? $envFile : null;
    }
}
