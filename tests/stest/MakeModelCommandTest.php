<?php

namespace Sikelan\Tests\Stest;

use PHPUnit\Framework\TestCase;
use Sikelan\Command\DefaultCommand\MakeModelCommand;

/**
 * make:model 命令测试
 *
 * 不依赖真实 MySQL：通过子类覆写 inspectTable() 返回模拟列信息，
 * 将输出目录重定向到临时目录，验证完整的"表名 → Model 文件"流程。
 */
class MakeModelCommandTest extends TestCase
{
    /**
     * 构造一个使用临时目录 + 模拟表结构的命令实例
     *
     * @param array|null $columns 模拟列信息；null 表示表不存在
     */
    private function makeCommand(?array $columns = null): MakeModelCommand
    {
        $tmpDir = sys_get_temp_dir() . '/sikelan_make_model_test_' . uniqid();
        mkdir($tmpDir, 0755, true);

        // 匿名子类：覆写 inspectTable 避免真实 DB 查询，重定向输出目录
        return new class ($tmpDir, $columns) extends MakeModelCommand {
            /** @var array|null 模拟的表列信息 */
            protected ?array $mockColumns;

            public function __construct(string $tmpDir, ?array $columns)
            {
                parent::__construct();
                $this->modelDir = $tmpDir;
                $this->mockColumns = $columns;
            }

            protected function inspectTable(string $table): ?array
            {
                return $this->mockColumns;
            }
        };
    }

    /**
     * 读取命令的输出目录（受保护属性）
     */
    private function modelDirOf(MakeModelCommand $cmd): string
    {
        $prop = new \ReflectionProperty($cmd, 'modelDir');
        $prop->setAccessible(true);
        return $prop->getValue($cmd);
    }

    protected function tearDown(): void
    {
        // 清理临时目录（含 .bak 备份文件）
        foreach (glob(sys_get_temp_dir() . '/sikelan_make_model_test_*') ?: [] as $dir) {
            foreach (array_merge(
                glob($dir . '/*.php') ?: [],
                glob($dir . '/*.bak.*') ?: []
            ) as $f) {
                unlink($f);
            }
            rmdir($dir);
        }
    }

    // ----------------------------------------------------------------
    //  参数解析
    // ----------------------------------------------------------------

    public function testExecWithoutTableNameReturnsError()
    {
        $cmd = $this->makeCommand([]);
        $result = $cmd->exec([]);

        $this->assertStringContainsString('Table name is required', $result);
    }

    public function testParseArgsRecognizesForceAndModel()
    {
        $cmd = $this->makeCommand([]);

        // 通过反射调用 protected parseArgs
        $method = new \ReflectionMethod($cmd, 'parseArgs');
        $method->setAccessible(true);

        $parsed = $method->invoke($cmd, ['users', '--force', '--model=Member']);
        $this->assertEquals('users', $parsed['table']);
        $this->assertTrue($parsed['force']);
        $this->assertEquals('Member', $parsed['model']);

        $parsed2 = $method->invoke($cmd, ['-f', 'orders']);
        $this->assertEquals('orders', $parsed2['table']);
        $this->assertTrue($parsed2['force']);
    }

    // ----------------------------------------------------------------
    //  表名 → 类名
    // ----------------------------------------------------------------

    /**
     * @dataProvider tableNameProvider
     */
    public function testTableToClassName(string $table, string $expected)
    {
        $cmd = $this->makeCommand([]);
        $this->assertEquals($expected, $cmd->tableToClassName($table));
    }

    public function tableNameProvider(): array
    {
        return [
            '简单复数' => ['users', 'User'],
            '多段复数' => ['order_items', 'OrderItem'],
            'ies 结尾' => ['categories', 'Category'],
            'ses 结尾' => ['buses', 'Bus'],
            'ches 结尾' => ['watches', 'Watch'],
            '不规则 people' => ['people', 'Person'],
            '不规则 children' => ['children', 'Child'],
            'ss 结尾不变' => ['glass', 'Glass'],
            '单词无复数' => ['config', 'Config'],
        ];
    }

    // ----------------------------------------------------------------
    //  表不存在
    // ----------------------------------------------------------------

    public function testExecTableNotExistsShowsError()
    {
        // inspectTable 返回 null 模拟表不存在
        $cmd = $this->makeCommand(null);
        $result = $cmd->exec(['no_such_table']);

        $this->assertStringContainsString("Table 'no_such_table' does not exist", $result);
        $this->assertStringNotContainsString('created successfully', $result);
    }

    // ----------------------------------------------------------------
    //  Model 参数推导
    // ----------------------------------------------------------------

    public function testBuildModelMetaWithIdPrimaryKey()
    {
        $cmd = $this->makeCommand([]);
        $columns = [
            ['name' => 'id', 'column_type' => 'bigint(20) unsigned', 'data_type' => 'bigint',
                'nullable' => false, 'key' => 'PRI', 'extra' => 'auto_increment', 'comment' => ''],
            ['name' => 'name', 'column_type' => 'varchar(100)', 'data_type' => 'varchar',
                'nullable' => false, 'key' => '', 'extra' => '', 'comment' => '姓名'],
            ['name' => 'age', 'column_type' => 'int(11)', 'data_type' => 'int',
                'nullable' => true, 'key' => '', 'extra' => '', 'comment' => ''],
            ['name' => 'is_active', 'column_type' => 'tinyint(1)', 'data_type' => 'tinyint',
                'nullable' => false, 'key' => '', 'extra' => '', 'comment' => ''],
            ['name' => 'balance', 'column_type' => 'decimal(10,2)', 'data_type' => 'decimal',
                'nullable' => false, 'key' => '', 'extra' => '', 'comment' => ''],
            ['name' => 'meta', 'column_type' => 'json', 'data_type' => 'json',
                'nullable' => true, 'key' => '', 'extra' => '', 'comment' => ''],
            ['name' => 'created_at', 'column_type' => 'datetime', 'data_type' => 'datetime',
                'nullable' => true, 'key' => '', 'extra' => '', 'comment' => ''],
            ['name' => 'updated_at', 'column_type' => 'datetime', 'data_type' => 'datetime',
                'nullable' => true, 'key' => '', 'extra' => '', 'comment' => ''],
        ];

        $meta = $cmd->buildModelMeta('users', $columns);

        $this->assertEquals('users', $meta['table']);
        $this->assertEquals('id', $meta['primaryKey']);
        $this->assertTrue($meta['incrementing']);
        $this->assertEquals('int', $meta['keyType']);
        // 主键不在 fillable
        $this->assertNotContains('id', $meta['fillable']);
        $this->assertContains('name', $meta['fillable']);
        $this->assertContains('created_at', $meta['fillable']);
        // casts 类型映射
        $this->assertEquals('boolean', $meta['casts']['is_active']);
        $this->assertEquals('integer', $meta['casts']['age']);
        $this->assertEquals('decimal:2', $meta['casts']['balance']);
        $this->assertEquals('array', $meta['casts']['meta']);
        $this->assertEquals('datetime', $meta['casts']['created_at']);
        // varchar 无 cast
        $this->assertArrayNotHasKey('name', $meta['casts']);
        // 双时间列 → timestamps 开启
        $this->assertTrue($meta['timestamps']);
    }

    public function testBuildModelMetaWithoutTimestamps()
    {
        $cmd = $this->makeCommand([]);
        $columns = [
            ['name' => 'id', 'column_type' => 'int(11)', 'data_type' => 'int',
                'nullable' => false, 'key' => 'PRI', 'extra' => 'auto_increment', 'comment' => ''],
            ['name' => 'title', 'column_type' => 'varchar(50)', 'data_type' => 'varchar',
                'nullable' => false, 'key' => '', 'extra' => '', 'comment' => ''],
        ];

        $meta = $cmd->buildModelMeta('articles', $columns);
        $this->assertFalse($meta['timestamps']);
    }

    public function testBuildModelMetaWithStringUuidPrimaryKey()
    {
        $cmd = $this->makeCommand([]);
        $columns = [
            ['name' => 'uuid', 'column_type' => 'varchar(36)', 'data_type' => 'varchar',
                'nullable' => false, 'key' => 'PRI', 'extra' => '', 'comment' => 'UUID主键'],
            ['name' => 'label', 'column_type' => 'varchar(50)', 'data_type' => 'varchar',
                'nullable' => false, 'key' => '', 'extra' => '', 'comment' => ''],
        ];

        $meta = $cmd->buildModelMeta('tokens', $columns);
        $this->assertEquals('uuid', $meta['primaryKey']);
        $this->assertFalse($meta['incrementing']);
        $this->assertEquals('string', $meta['keyType']);
    }

    // ----------------------------------------------------------------
    //  模板生成
    // ----------------------------------------------------------------

    public function testGenerateTemplateContainsEloquentPieces()
    {
        $cmd = $this->makeCommand([]);
        $meta = [
            'table' => 'order_items',
            'primaryKey' => 'id',
            'incrementing' => true,
            'keyType' => 'int',
            'fillable' => ['order_id', 'goods_name', 'quantity', 'price'],
            'casts' => ['order_id' => 'integer', 'quantity' => 'integer', 'price' => 'decimal:2'],
            'timestamps' => true,
        ];

        $code = $cmd->generateTemplate('OrderItem', $meta);

        $this->assertStringContainsString('namespace App\\Models;', $code);
        $this->assertStringContainsString('class OrderItem extends Model', $code);
        $this->assertStringContainsString("\$table = 'order_items'", $code);
        $this->assertStringContainsString("'goods_name'", $code);
        $this->assertStringContainsString("'price' => 'decimal:2'", $code);
        $this->assertStringContainsString('$timestamps = true', $code);
        // id 主键不输出 primaryKey
        $this->assertStringNotContainsString('$primaryKey', $code);
    }

    public function testGenerateTemplateWithCustomPrimaryKey()
    {
        $cmd = $this->makeCommand([]);
        $meta = [
            'table' => 'members',
            'primaryKey' => 'member_id',
            'incrementing' => true,
            'keyType' => 'int',
            'fillable' => ['name'],
            'casts' => [],
            'timestamps' => false,
        ];

        $code = $cmd->generateTemplate('Member', $meta);
        $this->assertStringContainsString("\$primaryKey = 'member_id'", $code);
        $this->assertStringNotContainsString('$timestamps = true', $code);
    }

    // ----------------------------------------------------------------
    //  端到端：exec 生成文件
    // ----------------------------------------------------------------

    public function testExecCreatesModelFile()
    {
        $columns = [
            ['name' => 'id', 'column_type' => 'bigint(20) unsigned', 'data_type' => 'bigint',
                'nullable' => false, 'key' => 'PRI', 'extra' => 'auto_increment', 'comment' => ''],
            ['name' => 'name', 'column_type' => 'varchar(100)', 'data_type' => 'varchar',
                'nullable' => false, 'key' => '', 'extra' => '', 'comment' => ''],
            ['name' => 'is_vip', 'column_type' => 'tinyint(1)', 'data_type' => 'tinyint',
                'nullable' => false, 'key' => '', 'extra' => '', 'comment' => ''],
        ];

        $cmd = $this->makeCommand($columns);
        $result = $cmd->exec(['users']);

        $this->assertStringContainsString("Model 'User' created successfully", $result);

        $file = $this->modelDirOf($cmd) . '/User.php';
        $this->assertFileExists($file);

        $code = file_get_contents($file);
        $this->assertStringContainsString('class User extends Model', $code);
        $this->assertStringContainsString("\$table = 'users'", $code);
        $this->assertStringContainsString("'name'", $code);
        $this->assertStringContainsString("'is_vip' => 'boolean'", $code);

        // 生成的代码必须语法正确
        exec('php -l ' . escapeshellarg($file) . ' 2>&1', $lintOut, $lintCode);
        $this->assertEquals(0, $lintCode, 'Generated file has syntax error: ' . implode("\n", $lintOut));
    }

    public function testExecRefusesOverwriteWithoutForce()
    {
        $columns = [
            ['name' => 'id', 'column_type' => 'int(11)', 'data_type' => 'int',
                'nullable' => false, 'key' => 'PRI', 'extra' => 'auto_increment', 'comment' => ''],
            ['name' => 'name', 'column_type' => 'varchar(50)', 'data_type' => 'varchar',
                'nullable' => false, 'key' => '', 'extra' => '', 'comment' => ''],
        ];

        $cmd = $this->makeCommand($columns);
        $modelFile = $this->modelDirOf($cmd) . '/User.php';

        // 第一次创建成功
        $first = $cmd->exec(['users']);
        $this->assertStringContainsString('created successfully', $first);

        // 第二次不带 --force 应拒绝
        $second = $cmd->exec(['users']);
        $this->assertStringContainsString('already exists', $second);

        // 带 --force 但内容与模板一致 → unchanged（幂等，不写盘不备份）
        $third = $cmd->exec(['users', '--force']);
        $this->assertStringContainsString('unchanged', $third);
        $this->assertSame([], glob($modelFile . '.bak.*'), '内容一致时不应产生备份');

        // 手工修改后 -f 覆盖 → overwritten 且原文件被备份
        file_put_contents($modelFile, "<?php\n// 手工追加的关联方法\n");
        $fourth = $cmd->exec(['users', '--force']);
        $this->assertStringContainsString('overwritten', $fourth);
        $backups = glob($modelFile . '.bak.*');
        $this->assertNotEmpty($backups);
        $this->assertStringContainsString('手工追加的关联方法', file_get_contents($backups[0]));

        // --no-backup：再次手工修改后覆盖，不产生新备份（保留上一份）
        file_put_contents($modelFile, "<?php\n// 第二次手工修改\n");
        $backupCountBefore = count(glob($modelFile . '.bak.*'));
        $fifth = $cmd->exec(['users', '--force', '--no-backup']);
        $this->assertStringContainsString('overwritten', $fifth);
        $this->assertCount($backupCountBefore, glob($modelFile . '.bak.*'));
    }

    public function testExecWithCustomModelName()
    {
        $columns = [
            ['name' => 'id', 'column_type' => 'int(11)', 'data_type' => 'int',
                'nullable' => false, 'key' => 'PRI', 'extra' => 'auto_increment', 'comment' => ''],
            ['name' => 'title', 'column_type' => 'varchar(50)', 'data_type' => 'varchar',
                'nullable' => false, 'key' => '', 'extra' => '', 'comment' => ''],
        ];

        $cmd = $this->makeCommand($columns);
        $result = $cmd->exec(['t_articles', '--model=Post']);

        $this->assertStringContainsString("Model 'Post' created successfully", $result);
        $this->assertFileExists($this->modelDirOf($cmd) . '/Post.php');
    }
}
