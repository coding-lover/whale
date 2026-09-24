<?php

namespace Sikelan\Tests\stest;

use PHPUnit\Framework\TestCase;
use Sikelan\Command\FileOverwriteGuard;

/**
 * FileOverwriteGuard Trait 测试
 *
 * 覆盖 make 系列命令的目标文件写入门禁：
 *   created / unchanged（不写盘）/ rejected / overwritten（+备份）/ 备份轮转 / 自动建目录
 */
class FileOverwriteGuardTest extends TestCase
{
    private string $tmpDir;

    /** @var object 匿名类，暴露 Trait 的 protected 方法 */
    private object $guard;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/sikelan_guard_' . uniqid();
        mkdir($this->tmpDir, 0755, true);

        // 匿名类 use Trait，把 protected 方法转 public 供测试调用
        $this->guard = new class {
            use FileOverwriteGuard;

            /** @return array<string,mixed> */
            public function call(string $path, string $content, bool $force, bool $backup = true): array
            {
                return $this->guardWrite($path, $content, $force, $backup);
            }
        };
    }

    protected function tearDown(): void
    {
        // 递归清理临时目录（部分用例创建了 sub/dir 嵌套结构）
        if (is_dir($this->tmpDir)) {
            $this->removeDirectory($this->tmpDir);
        }
        parent::tearDown();
    }

    /** 递归删除目录（含 .bak 备份文件） */
    private function removeDirectory(string $dir): void
    {
        foreach ((array) glob($dir . '/*') as $entry) {
            if (is_dir($entry) && !is_link($entry)) {
                $this->removeDirectory($entry);
            } else {
                unlink($entry);
            }
        }
        rmdir($dir);
    }

    public function testCreatesNewFile(): void
    {
        $path = $this->tmpDir . '/Demo.php';
        $r = $this->guard->call($path, '<?php // v1', false);

        $this->assertSame('created', $r['status']);
        $this->assertNull($r['backup']);
        $this->assertFileExists($path);
        $this->assertSame('<?php // v1', file_get_contents($path));
    }

    public function testAutoCreatesParentDirectory(): void
    {
        $path = $this->tmpDir . '/sub/dir/Demo.php';
        $r = $this->guard->call($path, 'content', false);

        $this->assertSame('created', $r['status']);
        $this->assertFileExists($path);
    }

    public function testUnchangedDoesNotTouchFile(): void
    {
        $path = $this->tmpDir . '/Demo.php';
        file_put_contents($path, 'same content');
        $mtimeBefore = filemtime($path);

        $r = $this->guard->call($path, 'same content', false);

        $this->assertSame('unchanged', $r['status']);
        $this->assertSame($mtimeBefore, filemtime($path), '内容一致时不应重写文件（mtime 不变）');
        $this->assertSame([], glob($path . '.bak.*'), '不应产生备份');
    }

    public function testRejectsOverwriteWithoutForce(): void
    {
        $path = $this->tmpDir . '/Demo.php';
        file_put_contents($path, "<?php\n// 手工写的业务代码\n");

        $r = $this->guard->call($path, "<?php\n// 模板内容\n", false);

        $this->assertSame('rejected', $r['status']);
        $this->assertNotNull($r['message']);
        $this->assertStringContainsString((string) $path, $r['message']);
        $this->assertStringContainsString('--force', $r['message']);
        // 原文件必须完好无损
        $this->assertStringContainsString('手工写的业务代码', file_get_contents($path));
        $this->assertSame([], glob($path . '.bak.*'));
    }

    public function testForceOverwriteCreatesBackupOfOldContent(): void
    {
        $path = $this->tmpDir . '/Demo.php';
        $oldContent = "<?php\n// 我的手工修改，不能丢\n";
        file_put_contents($path, $oldContent);

        $r = $this->guard->call($path, "<?php\n// 新模板\n", true);

        $this->assertSame('overwritten', $r['status']);
        $this->assertNotNull($r['backup']);
        $this->assertFileExists($r['backup']);
        // 备份内容必须是被覆盖前的手工版本
        $this->assertSame($oldContent, file_get_contents($r['backup']));
        // 目标文件已是新模板
        $this->assertSame("<?php\n// 新模板\n", file_get_contents($path));
    }

    public function testNoBackupFlagSkipsBackup(): void
    {
        $path = $this->tmpDir . '/Demo.php';
        file_put_contents($path, "<?php\n// old\n");

        $r = $this->guard->call($path, "<?php\n// new\n", true, false);

        $this->assertSame('overwritten', $r['status']);
        $this->assertNull($r['backup']);
        $this->assertSame([], glob($path . '.bak.*'));
        $this->assertSame("<?php\n// new\n", file_get_contents($path));
    }

    public function testForceOnIdenticalContentIsUnchanged(): void
    {
        $path = $this->tmpDir . '/Demo.php';
        file_put_contents($path, 'same');

        $r = $this->guard->call($path, 'same', true);

        $this->assertSame('unchanged', $r['status']);
        $this->assertSame([], glob($path . '.bak.*'), '内容一致时即使带 -f 也不应备份');
    }

    public function testBackupRotationKeepsLatestThree(): void
    {
        $path = $this->tmpDir . '/Demo.php';
        file_put_contents($path, 'v0');

        // 连续覆盖 5 次（同秒内，备份名带 _n 序号），只应保留最近 3 份
        for ($i = 1; $i <= 5; $i++) {
            $this->guard->call($path, "v{$i}", true);
        }

        $backups = (array) glob($path . '.bak.*');
        $this->assertCount(3, $backups, '备份轮转应只保留最近 3 份');

        // 最新的目标文件是 v5；被保留的备份应包含 v2/v3/v4，v0/v1 已删除
        $this->assertSame('v5', file_get_contents($path));
        $contents = array_map('file_get_contents', $backups);
        sort($contents);
        $this->assertSame(['v2', 'v3', 'v4'], $contents);
    }

    public function testLineDiffReportedOnRejection(): void
    {
        $path = $this->tmpDir . '/Demo.php';
        file_put_contents($path, "line1\nline2\nline3\n");

        $r = $this->guard->call($path, "line1\nline2\nline4\nline5\n", false);

        $this->assertSame('rejected', $r['status']);
        // line3 被删，line4/line5 新增 → removed>=1, added>=1
        $this->assertGreaterThanOrEqual(1, $r['removed']);
        $this->assertGreaterThanOrEqual(1, $r['added']);
        $this->assertStringContainsString("+{$r['added']}/-{$r['removed']}", $r['message']);
    }
}
