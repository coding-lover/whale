<?php

namespace Sikelan\Command;

/**
 * make 系列命令的目标文件安全写入门禁（Trait）。
 *
 * 解决的问题：
 *   `make:xxx -f` 以前会无条件覆盖目标文件。如果开发者已在生成的类里
 *   手写过业务代码（验证规则、关联、业务方法），一次误操作就全部丢失。
 *
 * 行为矩阵（guardWrite）：
 *   1. 文件不存在                 → 直接写入，status=created
 *   2. 文件存在但内容与模板一致   → 不写盘（保持 mtime），status=unchanged
 *   3. 文件存在且内容不同、无 -f  → 拒绝写入，status=rejected（消息提示手工修改规模）
 *   4. 文件存在且内容不同、有 -f  → 先备份(.bak.YmdHis)再覆盖，status=overwritten
 *
 * “已修改”的判定：
 *   磁盘内容 !== 即将写入的模板内容。无需在文件里埋标记，对老文件同样生效；
 *   模板自身升级后重新生成也会被识别为“有差异”（此时覆盖通常正是目的，备份兜底）。
 *
 * 备份策略：
 *   - 备份文件名：<目标文件>.bak.YmdHis（同目录，便于直接 diff）
 *   - 同一目标文件只保留最近 $backupKeep 份（默认 3），更旧的自动删除
 */
trait FileOverwriteGuard
{
    /**
     * 同一目标文件保留的备份份数上限（按修改时间，超出删最旧）。
     */
    protected int $backupKeep = 3;

    /**
     * 进程内单调递增的备份序号（保证同秒内多次覆盖时备份文件名严格递增，
     * 轮转按文件名排序即可正确识别新旧）。
     */
    private static int $backupSeq = 0;

    /**
     * 带门禁的文件写入。
     *
     * @param string $path       目标文件绝对路径
     * @param string $content    待写入的（模板渲染后的）完整内容
     * @param bool   $force      调用方是否带了 -f/--force
     * @param bool   $backup     force 覆盖前是否备份；false=不备份直接覆盖
     * @return array{
     *   status:string,
     *   backup:?string,
     *   added:int,
     *   removed:int,
     *   message:?string
     * } 状态：created|unchanged|rejected|overwritten；backup=备份文件路径或 null
     */
    protected function guardWrite(
        string $path,
        string $content,
        bool $force,
        bool $backup = true
    ): array
    {
        // 情形 1：文件不存在，直接创建
        if (!is_file($path)) {
            $this->writeBytes($path, $content);
            return $this->result('created');
        }

        $existing = (string) file_get_contents($path);

        // 情形 2：内容一致，幂等跳过（不触碰 mtime）
        if ($existing === $content) {
            return $this->result('unchanged');
        }

        [$added, $removed] = $this->lineDiff($existing, $content);

        // 情形 3：有手工修改但未授权覆盖 → 拒绝
        if (!$force) {
            return $this->result('rejected', null, $added, $removed, $this->buildRejectMessage($path, $added, $removed));
        }

        // 情形 4：已授权覆盖，先备份（除非显式关闭）
        $backupPath = null;
        if ($backup) {
            $backupPath = $this->makeBackup($path, $existing);
        }
        $this->writeBytes($path, $content);

        return $this->result('overwritten', $backupPath, $added, $removed);
    }

    /**
     * 创建备份并执行轮转。
     *
     * @return string 备份文件绝对路径
     */
    protected function makeBackup(string $path, string $existing): string
    {
        // 文件名 = .bak.YmdHis_进程内单调序号
        // 用单调序号而非"探测空缺位置"：轮转删掉旧备份后不会出现序号复用，
        // 否则新备份可能拿到字符串排序最小的名字而被轮转误删
        $stamp = date('YmdHis');
        do {
            $seq = self::$backupSeq++;
            $backupPath = $path . '.bak.' . $stamp . '_' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
        } while (is_file($backupPath)); // is_file 仅防跨进程极端碰撞
        $this->writeBytes($backupPath, $existing);
        $this->rotateBackups($path);
        return $backupPath;
    }

    /**
     * 保留最近 $backupKeep 份备份，删除更旧的。
     */
    protected function rotateBackups(string $path): void
    {
        $pattern = $path . '.bak.*';
        $files = glob($pattern);
        if ($files === false || count($files) <= $this->backupKeep) {
            return;
        }

        // 按文件名（内含时间戳）降序：新的在前，保留前 N 个，其余删除
        rsort($files);
        $stale = array_slice($files, $this->backupKeep);
        foreach ($stale as $file) {
            // 显式判空（禁止 @ 错误抑制）；清理失败不影响主流程，跳过即可
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /**
     * 粗略行级差异统计（用于提示变化规模，非精确 diff）。
     *
     * 用行集合差计算：模板中存在而旧文件没有的行数 = 新增；反之 = 删除。
     * 重复行会被集合去重，因此数字可能偏小——提示用途足够，精确对比请 diff 备份文件。
     *
     * @return array{0:int, 1:int} [added, removed]
     */
    protected function lineDiff(string $old, string $new): array
    {
        $oldLines = explode("\n", $old);
        $newLines = explode("\n", $new);
        $added = count(array_diff($newLines, $oldLines));
        $removed = count(array_diff($oldLines, $newLines));
        return [$added, $removed];
    }

    /**
     * 构造拒绝覆盖时的提示消息（纯文本，不含 ANSI，由调用方上色）。
     */
    protected function buildRejectMessage(string $path, int $added, int $removed): string
    {
        return "目标文件已被手工修改（约 +{$added}/-{$removed} 行差异）：{$path}\n"
            . "直接覆盖会丢失这些修改。如确认要用模板重新生成，请加 -f/--force；\n"
            . "使用 -f 覆盖前会自动备份为 .bak.YmdHis 文件。";
    }

    /**
     * 确保目录存在后写入文件。
     */
    protected function writeBytes(string $path, string $content): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, $content);
    }

    /**
     * 组装统一返回结构。
     *
     * @return array{status:string, backup:?string, added:int, removed:int, message:?string}
     */
    private function result(
        string $status,
        ?string $backup = null,
        int $added = 0,
        int $removed = 0,
        ?string $message = null
    ): array
    {
        return [
            'status'  => $status,
            'backup'  => $backup,
            'added'   => $added,
            'removed' => $removed,
            'message' => $message,
        ];
    }
}
