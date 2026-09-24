<?php

namespace App\Commands;

use App\Services\Trader\StrategySyncService;
use Sikelan\Command\CommandInterface;
use Sikelan\Command\CommandManager;
use Sikelan\Framework;

/**
 * 命令：trader:sync-strategies
 *
 * 把系统现有策略同步写入 strategies 表：
 *   1. config/trader.php 的 strategies 注册表（权威源：别名 + 构造参数）
 *   2. app/Services/Trader/Strategies 目录扫描（补充注册表里遗漏的类）
 *
 * 增量策略：
 *   - 表中不存在 → 插入（status=enabled）
 *   - 已存在     → 仅覆盖发生变化的字段；status 运营字段保留 DB 现值
 *
 * 用法：
 *   php bin/sikelan trader:sync-strategies            # 注册表 + 目录全量同步
 *   php bin/sikelan trader:sync-strategies --no-scan  # 只同步 config 注册表
 *   php bin/sikelan trader:sync-strategies --dry-run  # 只预览不落库
 *
 * @package App\Commands
 */
class TraderSyncStrategiesCommand implements CommandInterface
{
    public function commandName(): string
    {
        return 'trader:sync-strategies';
    }

    public function desc(): string
    {
        return '把 config/trader.php 注册表与策略目录中的策略同步到 strategies 表（增量更新）';
    }

    public function exec(array $args): ?string
    {
        unset($args);

        try {
            // 引导 Framework（确保 Eloquent 已 boot、config 可读）
            Framework::getInstance();

            $cmd = CommandManager::getInstance();
            $options = [
                'no_scan' => $cmd->getOpt('--no-scan', null) !== null,
                'dry_run' => $cmd->getOpt('--dry-run', null) !== null,
            ];
            // --dir 为空时让服务使用默认目录
            $dir = (string) ($cmd->getOpt('--dir', '') ?: '');
            if ($dir !== '') {
                $options['dir'] = $dir;
            }

            // 通过 DI 容器解析服务（规范：禁止业务代码里直接 new 服务类）
            $service = app(StrategySyncService::class);
            $stats = $service->syncAll($options);

            return $this->formatResult($stats, $options);
        } catch (\Throwable $e) {
            return "\033[31m[ERROR]\033[0m 策略同步失败：{$e->getMessage()}\n\n" . $this->help([]);
        }
    }

    /**
     * 渲染同步结果表格。
     *
     * @param array{created:array<int,string>, updated:array<int,string>,
     *              unchanged:array<int,string>, skipped:array<string,string>} $stats
     * @param array<string, mixed> $options
     */
    private function formatResult(array $stats, array $options): string
    {
        $dryRun = !empty($options['dry_run']);
        $title = $dryRun ? '策略同步预览（--dry-run，未落库）' : '策略同步完成';
        $out = "\n\033[32m[INFO]\033[0m {$title}\n";
        $out .= str_repeat('-', 72) . "\n";

        $out .= $this->renderGroup('新增', $stats['created'], '32');
        $out .= $this->renderGroup('更新', $stats['updated'], '33');
        $out .= $this->renderGroup('无变化', $stats['unchanged'], '90');

        $out .= str_repeat('-', 72) . "\n";
        if ($stats['skipped'] !== []) {
            $out .= "\n\033[31m跳过 " . count($stats['skipped']) . " 个：\033[0m\n";
            foreach ($stats['skipped'] as $alias => $reason) {
                $out .= "  \033[31m✗\033[0m {$alias}：{$reason}\n";
            }
        }

        $total = count($stats['created']) + count($stats['updated'])
            + count($stats['unchanged']) + count($stats['skipped']);
        $out .= sprintf(
            "\n合计 %d 个：新增 %d / 更新 %d / 无变化 %d / 跳过 %d\n",
            $total,
            count($stats['created']),
            count($stats['updated']),
            count($stats['unchanged']),
            count($stats['skipped'])
        );
        return $out;
    }

    /**
     * 渲染一组结果。
     *
     * @param array<int, string> $aliases
     */
    private function renderGroup(string $label, array $aliases, string $color): string
    {
        if ($aliases === []) {
            return '';
        }
        $out = "\n\033[{$color}m{$label}（" . count($aliases) . "）\033[0m\n";
        foreach ($aliases as $alias) {
            $out .= "  • {$alias}\n";
        }
        return $out;
    }

    public function help(array $args): ?string
    {
        unset($args);
        return <<<'HELP'
把 config/trader.php 注册表与策略目录中的策略同步到 strategies 表

Usage:
  php sikelan trader:sync-strategies [options]

数据源:
  1. config/trader.php 的 strategies 注册表（别名 + 构造参数，权威源）
  2. app/Services/Trader/Strategies 目录扫描（补充注册表遗漏的策略类）

增量规则:
  - 表中不存在 → 插入（status=enabled）
  - 已存在     → 仅覆盖变化字段；手动设置的 status 不会被重置

Options:
  --no-scan     只同步 config/trader.php 注册表，不扫描策略目录
  --dir=PATH    指定策略目录（默认 app/Services/Trader/Strategies）
  --dry-run     只预览将要执行的变更，不写数据库
  -h, --help    查看本帮助

示例:
  php sikelan trader:sync-strategies
  php sikelan trader:sync-strategies --no-scan
  php sikelan trader:sync-strategies --dry-run
HELP;
    }
}
