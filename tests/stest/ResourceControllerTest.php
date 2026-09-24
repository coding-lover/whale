<?php

namespace Sikelan\Tests\Stest;

use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;
use Sikelan\Core\Config;
use Sikelan\Database\EloquentManager;
use Sikelan\Database\PdoPool;
use Sikelan\Http\Request;
use Sikelan\Http\ResourceController;
use Sikelan\Http\Response;

/**
 * ResourceController 完整 CRUD 集成测试
 *
 * 使用真实 MySQL（quant_trade 库的 crud_test_items 表）验证：
 *   1. 构造器校验（缺 modelClass / 非法类名 / 非 Eloquent 子类）
 *   2. index() 分页 + 过滤 + 排序
 *   3. show() 存在 / 不存在（404）
 *   4. store() 验证通过 / 验证失败（422）
 *   5. update() 存在 / 不存在 / 验证失败
 *   6. destroy() 存在 / 不存在
 */
class ResourceControllerTest extends TestCase
{
    private static bool $booted = false;
    private static EloquentManager $eloquentManager;

    protected function setUp(): void
    {
        if (!self::$booted) {
            $config = new Config();
            $config->set('database.mysql', [
                'host'     => '127.0.0.1',
                'port'     => 3306,
                'username' => 'df',
                'password' => 'aa123456',
                'database' => 'quant_trade',
                'charset'  => 'utf8mb4',
                'timeout'  => 5,
                'pool_size'=> 10,
            ]);
            self::$eloquentManager = new EloquentManager($config, new PdoPool($config));
            self::$eloquentManager->boot();
            self::$booted = true;

            // 确保测试表存在（不存在则创建）
            self::$eloquentManager->connection()->statement(
                'CREATE TABLE IF NOT EXISTS crud_test_items (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(100),
                    email VARCHAR(100),
                    status VARCHAR(20)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
        }

        // 清空测试表
        CrudTestItem::query()->delete();
    }

    public static function tearDownAfterClass(): void
    {
        // 测试结束后清理测试表
        CrudTestItem::query()->delete();
    }

    // ---------- 1. 构造器校验 ----------

    public function testConstructorThrowsWhenModelClassEmpty(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('must declare $modelClass');
        new class extends ResourceController {
        };
    }

    public function testConstructorThrowsWhenModelClassNotExist(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('does not exist');
        new class extends ResourceController {
            protected string $modelClass = 'App\\Models\\NonExistentModel';
        };
    }

    public function testConstructorThrowsWhenNotEloquentModel(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('must extend');
        new class extends ResourceController {
            protected string $modelClass = \stdClass::class;
        };
    }

    // ---------- 2. index() 分页 ----------

    public function testIndexReturnsPaginatedList(): void
    {
        // 插入 25 条数据
        for ($i = 1; $i <= 25; $i++) {
            CrudTestItem::create([
                'name'   => "Item {$i}",
                'email'  => "item{$i}@test.com",
                'status' => $i % 2 === 0 ? 'active' : 'inactive',
            ]);
        }

        $controller = new class extends ResourceController {
            protected string $modelClass = CrudTestItem::class;
            protected array $filterable = ['status'];
            protected array $sortable = ['id'];
        };

        $request = $this->makeRequest(['page' => '1', 'per_page' => '10']);
        $response = $controller->index($request);

        $body = json_decode($response->getBody(), true);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $body['code'], '成功 code=0');
        $this->assertSame('', $body['message']);
        $this->assertCount(10, $body['data'], '第一页应返回 10 条');
        $this->assertSame(25, $body['pagination']['total']);
        $this->assertSame(1, $body['pagination']['page']);
        $this->assertSame(10, $body['pagination']['per_page']);
        $this->assertSame(3, $body['pagination']['last_page']);
    }

    public function testIndexSupportsFilterAndSort(): void
    {
        CrudTestItem::create(['name' => 'A', 'email' => 'a@t.com', 'status' => 'active']);
        CrudTestItem::create(['name' => 'B', 'email' => 'b@t.com', 'status' => 'inactive']);
        CrudTestItem::create(['name' => 'C', 'email' => 'c@t.com', 'status' => 'active']);

        $controller = new class extends ResourceController {
            protected string $modelClass = CrudTestItem::class;
            protected array $filterable = ['status'];
            protected array $sortable = ['id'];
        };

        // 过滤 status=active + 按 id 倒序
        $request = $this->makeRequest(['status' => 'active', 'sort' => 'id', 'order' => 'desc']);
        $response = $controller->index($request);
        $body = json_decode($response->getBody(), true);

        $this->assertCount(2, $body['data'], 'active 状态应有 2 条');
        $this->assertGreaterThan($body['data'][1]['id'], $body['data'][0]['id'], '应按 id 倒序');
    }

    public function testIndexIgnoresFilterForNonWhitelistedFields(): void
    {
        CrudTestItem::create(['name' => 'A', 'email' => 'a@t.com', 'status' => 'active']);
        CrudTestItem::create(['name' => 'B', 'email' => 'b@t.com', 'status' => 'inactive']);

        $controller = new class extends ResourceController {
            protected string $modelClass = CrudTestItem::class;
            // filterable 为空 → 不允许任何过滤
        };

        $request = $this->makeRequest(['status' => 'active']);
        $response = $controller->index($request);
        $body = json_decode($response->getBody(), true);

        $this->assertCount(2, $body['data'], 'filterable 为空时 status 过滤应被忽略，返回全部');
    }

    // ---------- 3. show() ----------

    public function testShowReturnsSingleItem(): void
    {
        $item = CrudTestItem::create(['name' => 'Show Test', 'email' => 'show@t.com', 'status' => 'active']);

        $controller = new class extends ResourceController {
            protected string $modelClass = CrudTestItem::class;
        };

        $response = $controller->show($this->makeRequest([]), $item->id);
        $body = json_decode($response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $body['code']);
        $this->assertSame('Show Test', $body['data']['name']);
    }

    public function testShowReturns404WhenNotFound(): void
    {
        $controller = new class extends ResourceController {
            protected string $modelClass = CrudTestItem::class;
        };

        $response = $controller->show($this->makeRequest([]), 99999);
        $body = json_decode($response->getBody(), true);

        // HTTP 状态码恒为 200；业务码 CODE_NOT_FOUND（1404）
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(Response::CODE_NOT_FOUND, $body['code']);
        $this->assertSame('Resource not found', $body['message']);
        $this->assertNull($body['data']);
    }

    // ---------- 4. store() ----------

    public function testStoreCreatesItemWith201(): void
    {
        $controller = new class extends ResourceController {
            protected string $modelClass = CrudTestItem::class;
            protected array $rules = [
                'name'  => 'required|string|min:2',
                'email' => 'required|email',
            ];
        };

        $request = $this->makeRequest([], ['name' => 'New Item', 'email' => 'new@t.com', 'status' => 'active']);
        $response = $controller->store($request);
        $body = json_decode($response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $body['code']);
        $this->assertSame('New Item', $body['data']['name']);
        $this->assertNotEmpty($body['data']['id']);
    }

    public function testStoreReturns422WhenValidationFails(): void
    {
        $controller = new class extends ResourceController {
            protected string $modelClass = CrudTestItem::class;
            protected array $rules = [
                'name'  => 'required|string|min:2',
                'email' => 'required|email',
            ];
        };

        // name 太短 + email 格式错误
        $request = $this->makeRequest([], ['name' => 'A', 'email' => 'not-an-email']);
        $response = $controller->store($request);
        $body = json_decode($response->getBody(), true);

        // HTTP 200 + 业务码 CODE_VALIDATION（1422）
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(Response::CODE_VALIDATION, $body['code']);
        $this->assertSame('Validation failed', $body['message']);
        $this->assertNotEmpty($body['errors']);
        $this->assertNull($body['data']);
    }

    public function testStoreUsesFillableWhenNoRules(): void
    {
        $controller = new class extends ResourceController {
            protected string $modelClass = CrudTestItem::class;
            // rules 为空 → 用 Model 的 $fillable 白名单
        };

        $request = $this->makeRequest([], ['name' => 'No Rules', 'email' => 'norules@t.com', 'status' => 'active', 'evil' => 'injected']);
        $response = $controller->store($request);
        $body = json_decode($response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $body['code']);
        $this->assertSame('No Rules', $body['data']['name']);
        $this->assertArrayNotHasKey('evil', $body['data'], '非 fillable 字段不应被写入');
    }

    // ---------- 5. update() ----------

    public function testUpdateModifiesExistingItem(): void
    {
        $item = CrudTestItem::create(['name' => 'Old', 'email' => 'old@t.com', 'status' => 'inactive']);

        $controller = new class extends ResourceController {
            protected string $modelClass = CrudTestItem::class;
            protected array $rules = [
                'name'  => 'required|string|min:2',
                'email' => 'required|email',
            ];
        };

        $request = $this->makeRequest([], ['name' => 'Updated', 'email' => 'updated@t.com']);
        $response = $controller->update($request, $item->id);
        $body = json_decode($response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $body['code']);
        $this->assertSame('Updated', $body['data']['name']);
        $this->assertSame('updated@t.com', $body['data']['email']);
        // 未提交的字段保持不变
        $this->assertSame('inactive', $body['data']['status']);
    }

    public function testUpdateReturns404WhenNotFound(): void
    {
        $controller = new class extends ResourceController {
            protected string $modelClass = CrudTestItem::class;
        };

        $request = $this->makeRequest([], ['name' => 'X']);
        $response = $controller->update($request, 99999);
        $body = json_decode($response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(Response::CODE_NOT_FOUND, $body['code']);
    }

    // ---------- 6. destroy() ----------

    public function testDestroyDeletesItemWith204(): void
    {
        $item = CrudTestItem::create(['name' => 'To Delete', 'email' => 'del@t.com', 'status' => 'active']);

        $controller = new class extends ResourceController {
            protected string $modelClass = CrudTestItem::class;
        };

        $response = $controller->destroy($this->makeRequest([]), $item->id);
        $body = json_decode($response->getBody(), true);

        // HTTP 200 + code=0；不再用 HTTP 204
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $body['code']);
        $this->assertNull(CrudTestItem::find($item->id), '记录应被删除');
    }

    public function testDestroyReturns404WhenNotFound(): void
    {
        $controller = new class extends ResourceController {
            protected string $modelClass = CrudTestItem::class;
        };

        $response = $controller->destroy($this->makeRequest([]), 99999);
        $body = json_decode($response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(Response::CODE_NOT_FOUND, $body['code']);
    }

    // ---------- 辅助 ----------

    /**
     * 构造测试用 Request（query + post）
     */
    private function makeRequest(array $query = [], array $post = []): Request
    {
        $request = new Request('POST', '/test', [], json_encode($post));
        // 反射注入 queryParams 和 postParams
        $r = new \ReflectionClass($request);
        $qp = $r->getProperty('queryParams');
        $qp->setAccessible(true);
        $qp->setValue($request, $query);
        $pp = $r->getProperty('postParams');
        $pp->setAccessible(true);
        $pp->setValue($request, $post);
        return $request;
    }
}

/**
 * 测试用 Eloquent Model（crud_test_items 表）
 */
class CrudTestItem extends Model
{
    protected $table = 'crud_test_items';
    public $timestamps = false;
    protected $fillable = ['name', 'email', 'status'];
}
