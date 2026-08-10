# Sikelan Framework

基于 Swoole 的高性能 PHP 框架，专为量化交易系统设计，支持高并发、低延迟的服务端开发。

**项目规则**: 请参考 [global-style.md](file:///Users/wmc/data/trae/project/whale/global-style.md) 了解项目的架构设计、命名规范、代码风格等核心约束。

## 功能特性

- **协程支持**: 充分利用 Swoole 4.6.x 的协程特性，实现高性能异步 IO
- **依赖注入**: 内置 DI 容器，支持自动装配和工厂模式
- **路由系统**: 基于 FastRoute 的高效路由匹配，支持 RESTful
- **连接池**: MySQL/Redis 协程连接池管理，复用连接提高性能
- **任务系统**: 异步任务投递和处理，支持回调和等待结果
- **定时任务**: 基于 Swoole 定时器的定时任务调度，支持 cron 表达式
- **配置管理**: 支持 PHP、YAML、JSON 多种配置格式，环境变量支持
- **日志系统**: 实现 PSR-3 日志接口，支持多级别日志
- **多服务器支持**: HTTP、WebSocket、TCP 服务器一键切换
- **进程管理**: 自定义进程支持，守护进程模式

## 技术栈

- PHP 7.4+
- Swoole 4.6.3+
- MySQL 8.0+
- Redis 6.0+
- Composer

## 安装

```bash
# 克隆项目
git clone <repository-url>
cd whale

# 安装依赖
composer install

# 复制环境变量配置
cp .env.example .env

# 启动服务（HTTP 模式，默认）
composer start

# 启动方式
composer start:http        # HTTP 模式
composer start:websocket   # WebSocket 模式
composer start:tcp         # TCP 模式
```

## 命令控制

框架提供了强大的命令行工具，参考 EasySwoole 框架设计理念，可以通过命令来启动框架、管理配置、自动生成代码等。

### 命令入口

```bash
# 使用 PHP 直接调用
php bin/sikelan <command> [options]

# 或通过 Composer 脚本
composer serve          # 启动服务
composer serve:dev      # 启动开发环境
composer serve:stop     # 停止服务
composer serve:restart  # 重启服务
composer serve:status   # 查看服务状态
```

### 查看所有命令

```bash
# 显示所有可用命令列表
php bin/sikelan list

# 显示帮助信息
php bin/sikelan help

# 查看某个命令的详细帮助
php bin/sikelan help server
php bin/sikelan help make:controller
```

### 服务器管理

```bash
# 启动服务器（默认 HTTP 模式）
php bin/sikelan server start

# 指定环境启动
php bin/sikelan server start -e=dev
php bin/sikelan server start --env=prod

# 守护进程模式启动
php bin/sikelan server start -d
php bin/sikelan server start --daemon

# 停止服务器
php bin/sikelan server stop

# 强制停止（SIGKILL）
php bin/sikelan server stop -f

# 重启服务器
php bin/sikelan server restart

# 查看服务器状态
php bin/sikelan server status
```

### 代码生成

框架提供了代码生成命令，可以快速创建控制器、模型、任务类等。

#### 生成控制器

```bash
# 创建控制器（会自动添加 RESTful 路由）
php bin/sikelan make:controller User

# 创建后会自动追加路由到 config/router.php
# 生成的控制器包含 index/show/store/update/destroy 五个方法

# 强制覆盖已存在的文件
php bin/sikelan make:controller Product -f
```

**生成的控制器示例：**

```php
// app/Controllers/UserController.php
namespace App\Controllers;

use Sikelan\Http\Request;
use Sikelan\Http\Response;

class UserController
{
    public function index(Request $request, $params) { ... }
    public function show(Request $request, $params) { ... }
    public function store(Request $request, $params) { ... }
    public function update(Request $request, $params) { ... }
    public function destroy(Request $request, $params) { ... }
}
```

#### 生成模型

```bash
# 创建模型类（包含 CRUD 操作方法）
php bin/sikelan make:model User

# 自定义表名会自动转换为复数蛇形命名
# User -> users, Product -> products, OrderItem -> order_items

# 强制覆盖
php bin/sikelan make:model Order -f
```

**生成的模型示例：**

```php
// app/Models/UserModel.php
namespace App\Models;

class UserModel
{
    protected string $table = 'users';
    protected string $primaryKey = 'id';

    public function find(int $id): ?array { ... }
    public function all(int $page = 1, int $perPage = 20): array { ... }
    public function create(array $data): int { ... }
    public function update(int $id, array $data): bool { ... }
    public function delete(int $id): bool { ... }
}
```

#### 生成任务类

```bash
# 创建异步任务类
php bin/sikelan make:task SendEmail

# 生成的任务类自动实现 TaskInterface 接口
# 包含日志记录、异常处理等标准模板

# 强制覆盖
php bin/sikelan make:task ProcessData -f
```

**生成的任务类示例：**

```php
// app/Tasks/SendEmailTask.php
namespace App\Tasks;

use Sikelan\Task\TaskInterface;
use Sikelan\Core\Logger;

class SendEmailTask implements TaskInterface
{
    protected ?Logger $logger = null;

    public function __construct(?Logger $logger = null)
    {
        $this->logger = $logger;
    }

    public function handle(array $args) { ... }
}
```

### 配置管理

```bash
# 显示所有配置
php bin/sikelan config show

# 显示指定配置项（支持点号分隔）
php bin/sikelan config show app
php bin/sikelan config show app.name
php bin/sikelan config show database.mysql.host

# 获取单个配置值
php bin/sikelan config get app.env

# 临时设置配置值（仅运行时生效）
php bin/sikelan config set app.debug true

# 清除配置缓存
php bin/sikelan config clear
```

### 自定义命令

框架支持自定义命令，只需实现 `CommandInterface` 接口并注册到 `CommandManager` 即可。

#### 1. 创建命令类

```php
// app/Commands/HelloCommand.php
namespace App\Commands;

use Sikelan\Command\CommandInterface;

class HelloCommand implements CommandInterface
{
    public function commandName(): string
    {
        return 'hello';
    }

    public function exec(array $args): ?string
    {
        $name = $args[0] ?? 'World';
        return "\033[32mHello, {$name}!\033[0m";
    }

    public function help(array $args): ?string
    {
        return "Usage: php sikelan hello [name]\n\nSay hello to someone.";
    }

    public function desc(): string
    {
        return 'Say hello to someone';
    }
}
```

#### 2. 注册命令

**方式一：通过代码注册（在启动脚本中）**

```php
// bin/sikelan 或其他入口文件
use Sikelan\Command\CommandRunner;

$runner = CommandRunner::getInstance();
$runner->addCommand(new \App\Commands\HelloCommand());
$runner->run($argv);
```

**方式二：通过配置文件批量注册**

```php
// config/commands.php
return [
    \App\Commands\HelloCommand::class,
    \App\Commands\CustomCommand::class,
];

// 在入口文件中加载
$runner->loadCommandsFromConfig(__DIR__ . '/config/commands.php');
```

#### 3. 执行自定义命令

```bash
php bin/sikelan hello          # 输出: Hello, World!
php bin/sikelan hello Sikelan  # 输出: Hello, Sikelan!
php bin/sikelan hello --help   # 显示帮助
```

### 内置命令列表

| 命令 | 说明 | 示例 |
|------|------|------|
| `server start` | 启动服务器 | `php sikelan server start -e=dev` |
| `server stop` | 停止服务器 | `php sikelan server stop` |
| `server restart` | 重启服务器 | `php sikelan server restart` |
| `server status` | 查看服务器状态 | `php sikelan server status` |
| `make:controller` | 生成控制器 | `php sikelan make:controller User` |
| `make:model` | 生成模型 | `php sikelan make:model Product` |
| `make:task` | 生成任务类 | `php sikelan make:task SendEmail` |
| `config show` | 显示配置 | `php sikelan config show app` |
| `config get` | 获取配置值 | `php sikelan config get app.env` |
| `config set` | 设置配置值 | `php sikelan config set app.debug true` |
| `config clear` | 清除配置缓存 | `php sikelan config clear` |
| `help` | 查看命令帮助 | `php sikelan help server` |
| `list` | 列出所有命令 | `php sikelan list` |

## 快速开始

### 创建简单的 HTTP 服务

```php
// bin/start.php
use Sikelan\Framework;

$app = Framework::getInstance();

// 路由已配置在 config/router.php 中，框架会自动加载

// 启动服务器
$app->run('http');
```

启动后访问 `http://localhost:9501` 即可看到响应。

## 路由系统

### 配置化路由

框架支持通过配置文件定义路由，所有路由集中管理在 `config/router.php` 中：

```php
// config/router.php
use Sikelan\Http\Response;

return [
    // 闭包路由
    [
        'method' => 'GET',
        'path' => '/',
        'handler' => function () {
            return (new Response())->withJson([
                'status' => 'success',
                'message' => 'Sikelan Framework is running',
            ]);
        },
    ],

    // 控制器路由
    [
        'method' => 'GET',
        'path' => '/api/users',
        'handler' => 'App\Controllers\UserController@index',
    ],

    // 多方法路由
    [
        'method' => ['GET', 'POST'],
        'path' => '/api/data',
        'handler' => 'App\Controllers\DataController@handle',
    ],
];
```

**路由配置格式说明：**

| 字段 | 类型 | 说明 |
|------|------|------|
| `method` | string \| array | HTTP 方法，支持 `GET`、`POST`、`PUT`、`DELETE` 或数组 |
| `path` | string | 路由路径，支持参数如 `{id}` |
| `handler` | callable \| string | 处理器，支持闭包或 `Controller@method` 格式 |

### 路由方法（代码方式）

除了配置化路由，也支持在代码中动态添加路由：

```php
$router = $app->getRouter();

// GET 请求
$router->get('/users', function ($request, $params) {
    return ['users' => []];
});

// POST 请求
$router->post('/users', function ($request) {
    $data = $request->getPostParams();
    return ['received' => $data];
});

// PUT 请求
$router->put('/users/{id}', function ($request, $params) {
    return ['updated_id' => $params['id']];
});

// DELETE 请求
$router->delete('/users/{id}', function ($request, $params) {
    return ['deleted_id' => $params['id']];
});

// 支持所有方法
$router->any('/api/test', function ($request) {
    return ['method' => $request->getMethod()];
});
```

**请求示例：**

```bash
# GET 请求
curl http://localhost:9501/users

# POST 请求
curl -X POST -H "Content-Type: application/json" -d '{"name":"John"}' http://localhost:9501/users

# PUT 请求
curl -X PUT http://localhost:9501/users/1

# DELETE 请求
curl -X DELETE http://localhost:9501/users/1

# ANY 请求（支持任意 HTTP 方法）
curl http://localhost:9501/api/test
curl -X POST http://localhost:9501/api/test
```

### 路由参数

```php
// 单个参数
$router->get('/users/{id}', function ($request, $params) {
    return ['user_id' => $params['id']];
});

// 多个参数
$router->get('/users/{userId}/orders/{orderId}', function ($request, $params) {
    return [
        'user_id' => $params['userId'],
        'order_id' => $params['orderId']
    ];
});

// 带正则约束的参数（仅匹配数字）
$router->get('/products/{id:\d+}', function ($request, $params) {
    return ['product_id' => (int)$params['id']];
});
```

**请求示例：**

```bash
# 单个参数
curl http://localhost:9501/users/123

# 多个参数
curl http://localhost:9501/users/1/orders/100

# 正则约束 - 匹配数字 ID
curl http://localhost:9501/products/42      # 匹配成功
curl http://localhost:9501/products/abc     # 不匹配，返回 404
```

### 路由分组

```php
$router->group('/api/v1', function ($r) {
    $r->get('/users', 'App\Controllers\UserController@index');
    $r->get('/users/{id}', 'App\Controllers\UserController@show');
    $r->post('/users', 'App\Controllers\UserController@store');
    
    $r->group('/admin', function ($admin) {
        $admin->get('/dashboard', function () {
            return ['dashboard' => 'admin'];
        });
    });
});
```

**请求示例：**

```bash
# 访问分组路由
curl http://localhost:9501/api/v1/users
curl http://localhost:9501/api/v1/users/1
curl -X POST http://localhost:9501/api/v1/users

# 嵌套分组路由
curl http://localhost:9501/api/v1/admin/dashboard
```

### 使用控制器

```php
// app/Controllers/UserController.php
namespace App\Controllers;

use Sikelan\Http\Request;
use Sikelan\Http\Response;

class UserController
{
    public function index(Request $request, $params)
    {
        // 获取查询参数
        $page = $request->getParam('page', 1);
        $limit = $request->getParam('limit', 10);
        
        return [
            'users' => [],
            'pagination' => [
                'page' => $page,
                'limit' => $limit
            ]
        ];
    }
    
    public function show(Request $request, $params)
    {
        $userId = $params['id'];
        return [
            'user' => [
                'id' => $userId,
                'name' => 'John Doe',
                'email' => 'john@example.com'
            ]
        ];
    }
    
    public function store(Request $request, $params)
    {
        $data = $request->getPostParams();
        
        if (!isset($data['name']) || !isset($data['email'])) {
            return (new Response(400))->withJson([
                'error' => '缺少必要参数'
            ]);
        }
        
        return (new Response(201))->withJson([
            'message' => '用户创建成功',
            'data' => $data
        ]);
    }
}

// 路由配置
$router->get('/users', 'App\Controllers\UserController@index');
$router->get('/users/{id}', 'App\Controllers\UserController@show');
$router->post('/users', 'App\Controllers\UserController@store');
```

**请求示例：**

```bash
# 获取用户列表（带分页参数）
curl "http://localhost:9501/users?page=1&limit=10"

# 获取单个用户
curl http://localhost:9501/users/1

# 创建用户（POST JSON 数据）
curl -X POST -H "Content-Type: application/json" \
  -d '{"name":"John Doe","email":"john@example.com"}' \
  http://localhost:9501/users

# 创建用户（缺少参数，返回 400 错误）
curl -X POST -H "Content-Type: application/json" \
  -d '{"name":"John"}' \
  http://localhost:9501/users
```

## 请求处理

### 获取请求信息

```php
$router->get('/test', function ($request) {
    // 获取请求方法
    $method = $request->getMethod(); // GET, POST, PUT, DELETE...
    
    // 获取请求 URI
    $uri = $request->getUri()->getPath();
    
    // 获取查询参数
    $query = $request->getQueryParams();
    
    // 获取 POST 参数
    $post = $request->getPostParams();
    
    // 获取单个参数（优先查询参数，其次 POST 参数）
    $id = $request->getParam('id', 1);
    
    // 获取服务器参数
    $server = $request->getServerParams();
    $ip = $server['remote_addr'];
    
    // 获取请求头
    $contentType = $request->getHeaderLine('Content-Type');
    
    // 获取 Cookie
    $cookies = $request->getCookies();
    
    return compact('method', 'uri', 'query', 'post', 'id', 'ip', 'contentType', 'cookies');
});
```

## 响应处理

### 返回不同类型的响应

```php
$router->get('/json', function () {
    // 返回 JSON
    return (new Response())->withJson([
        'status' => 'success',
        'data' => []
    ]);
});

$router->get('/html', function () {
    // 返回 HTML
    return (new Response())->withHtml('<html><body><h1>Hello World</h1></body></html>');
});

$router->get('/redirect', function () {
    // 重定向
    return (new Response())->withRedirect('/new-url');
});

$router->get('/error', function () {
    // 返回错误状态码
    return (new Response(404))->withJson([
        'error' => 'Not Found'
    ]);
});

$router->get('/custom-header', function () {
    // 自定义响应头
    return (new Response())
        ->withHeader('X-Custom', 'value')
        ->withHeader('Cache-Control', 'max-age=3600')
        ->withJson(['message' => 'custom header']);
});
```

## 依赖注入容器

### 基本用法

```php
$container = $app->getContainer();

// 设置实例
$container->set('config', $config);

// 设置工厂（每次获取都创建新实例）
$container->set('logger', function ($c) {
    return new \Sikelan\Core\Logger($c->get('config'));
});

// 获取实例
$logger = $container->get('logger');

// 检查是否存在
if ($container->has('database')) {
    // ...
}
```

### 自动装配

容器支持自动装配，会自动解析类的构造函数依赖。

```php
class UserService
{
    protected $db;
    protected $cache;
    
    public function __construct(
        \Sikelan\Database\MysqlPool $db,
        \Sikelan\Cache\RedisCache $cache
    ) {
        $this->db = $db;
        $this->cache = $cache;
    }
    
    public function getUser($id)
    {
        // ...
    }
}

// 自动解析依赖
$userService = $container->get(UserService::class);
```

## 数据库操作

### MySQL 连接池

```php
use Sikelan\Database\MysqlPool;

$db = $app->getContainer()->get(MysqlPool::class);

// 查询数据
$users = $db->select('SELECT * FROM users WHERE status = ? LIMIT 10', [1]);

// 查询单条记录
$user = $db->select('SELECT * FROM users WHERE id = ?', [1]);
$user = $user ? $user[0] : null;

// 插入数据
$userId = $db->insert('users', [
    'name' => 'John Doe',
    'email' => 'john@example.com',
    'created_at' => date('Y-m-d H:i:s'),
    'updated_at' => date('Y-m-d H:i:s')
]);

// 更新数据
$affected = $db->update('users', [
    'name' => 'Jane Doe',
    'updated_at' => date('Y-m-d H:i:s')
], ['id' => $userId]);

// 删除数据
$affected = $db->delete('users', ['id' => $userId]);
```

### 事务处理

```php
// 开始事务
$connection = $db->beginTransaction();

try {
    // 执行多个操作
    $db->insert('orders', ['user_id' => 1, 'total' => 100]);
    $db->update('users', ['balance' => 900], ['id' => 1]);
    
    // 提交事务
    $db->commit($connection);
} catch (\Exception $e) {
    // 回滚事务
    $db->rollback($connection);
    throw $e;
}
```

### 原生查询

```php
// 执行原生 SQL
$result = $db->query('UPDATE users SET views = views + 1 WHERE id = ?', [1]);

// 获取插入 ID
$insertId = $db->query('INSERT INTO logs (message) VALUES (?)', ['test']);
```

## 缓存操作

### Redis 缓存

```php
use Sikelan\Cache\RedisCache;

$cache = $app->getContainer()->get(RedisCache::class);

// 字符串操作
$cache->set('key', 'value', 3600); // 有效期 1 小时
$value = $cache->get('key');
$cache->del('key');
$exists = $cache->exists('key');
$cache->expire('key', 60); // 延长有效期 60 秒

// 哈希操作
$cache->hSet('user:1', 'name', 'John');
$cache->hSet('user:1', 'email', 'john@example.com');
$user = $cache->hGetAll('user:1'); // ['name' => 'John', 'email' => 'john@example.com']
$name = $cache->hGet('user:1', 'name');
$cache->hDel('user:1', 'email');

// 列表操作
$cache->lPush('queue', 'task1');
$cache->rPush('queue', 'task2');
$task = $cache->lPop('queue'); // 'task1'
$length = $cache->lLen('queue');

// 计数器
$cache->set('counter', 0);
$cache->incr('counter'); // 1
$cache->incr('counter'); // 2
$cache->decr('counter'); // 1

// 获取原生客户端
$redis = $cache->getClient();
$redis->publish('channel', 'message');
```

## 异步任务

### 创建任务类

```php
// app/Tasks/SendEmailTask.php
namespace App\Tasks;

use Sikelan\Task\TaskInterface;
use Sikelan\Core\Logger;

class SendEmailTask implements TaskInterface
{
    protected $logger;
    
    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }
    
    public function handle(array $args)
    {
        $to = $args['to'] ?? '';
        $subject = $args['subject'] ?? 'No Subject';
        $content = $args['content'] ?? '';
        
        $this->logger->info("Sending email to: {$to}");
        
        // 模拟发送邮件
        sleep(2);
        
        return [
            'success' => true,
            'to' => $to,
            'message' => 'Email sent successfully'
        ];
    }
}
```

### 投递任务

```php
$taskManager = $app->getTaskManager();

// 异步执行（不等待结果）
$taskManager->async(\App\Tasks\SendEmailTask::class, [
    'to' => 'user@example.com',
    'subject' => 'Welcome',
    'content' => 'Hello World'
]);

// 异步执行（带回调）
$taskManager->async(\App\Tasks\SendEmailTask::class, [
    'to' => 'admin@example.com',
    'subject' => 'System Alert'
], function ($result) {
    if ($result['success']) {
        // 处理成功结果
    }
});

// 同步执行（等待结果）
$result = $taskManager->sync(\App\Tasks\SendEmailTask::class, [
    'to' => 'sync@example.com'
]);
```

## 定时任务

### 添加定时任务

```php
$crontab = $app->getCrontab();

// 每分钟执行
$crontab->addTask('minute_task', '* * * * *', function () {
    // 每分钟执行的任务
    // 例如：清理临时文件、检查队列等
});

// 每小时执行
$crontab->addTask('hourly_task', '0 * * * *', function () {
    // 每小时执行的任务
    // 例如：统计每小时数据
});

// 每天凌晨 2 点执行
$crontab->addTask('daily_task', '0 2 * * *', function () {
    // 每天执行的任务
    // 例如：生成日报表
});

// 每周一凌晨执行
$crontab->addTask('weekly_task', '0 0 * * 1', function () {
    // 每周执行的任务
    // 例如：生成周报表
});

// 每月 1 号凌晨执行
$crontab->addTask('monthly_task', '0 0 1 * *', function () {
    // 每月执行的任务
    // 例如：生成月报表
});
```

### Cron 表达式格式

```
*    *    *    *    *
-    -    -    -    -
|    |    |    |    |
|    |    |    |    +----- 星期几 (0-7) (0 和 7 都代表周日)
|    |    |    +---------- 月份 (1-12)
|    |    +--------------- 日期 (1-31)
|    +-------------------- 小时 (0-23)
+------------------------- 分钟 (0-59)
```

## WebSocket 服务器

### 启动 WebSocket 服务

```php
// bin/websocket.php
use Sikelan\Framework;

$app = Framework::getInstance();

// WebSocket 连接事件
$app->getServer()->on('open', function ($server, $request) {
    echo "Client connected: {$request->fd}\n";
});

// WebSocket 消息事件
$app->getServer()->on('message', function ($server, $frame) {
    echo "Received message from {$frame->fd}: {$frame->data}\n";
    
    // 回复消息
    $server->push($frame->fd, "Server received: {$frame->data}");
});

// WebSocket 关闭事件
$app->getServer()->on('close', function ($server, $fd) {
    echo "Client disconnected: {$fd}\n";
});

// 启动 WebSocket 服务器
$app->run('websocket');
```

### 客户端连接示例

```javascript
// 前端 JavaScript
const ws = new WebSocket('ws://localhost:9501');

ws.onopen = function() {
    ws.send('Hello Server');
};

ws.onmessage = function(event) {
    console.log('Received:', event.data);
};

ws.onclose = function() {
    console.log('Disconnected');
};
```

## 异常任务 Demo

框架提供了异常任务的演示示例，展示如何处理任务执行中的异常情况。

### 测试接口

| 接口 | 方法 | 说明 |
|------|------|------|
| `/api/task/test-exception` | GET | 测试同步异常任务 |
| `/api/task/test-normal` | GET | 测试同步正常任务 |
| `/api/task/test-async-exception` | GET | 测试异步异常任务 |

### 使用示例

```bash
# 测试正常任务执行
curl http://localhost:9501/api/task/test-normal

# 测试异常任务执行（预期返回错误）
curl http://localhost:9501/api/task/test-exception

# 测试异步异常任务
curl http://localhost:9501/api/task/test-async-exception
```

### 响应示例

**正常任务响应：**
```json
{
    "status": "success",
    "data": {
        "success": true,
        "message": "This task will execute normally",
        "timestamp": "2024-01-15 10:30:00",
        "args_received": {
            "should_throw": false,
            "message": "This task will execute normally"
        }
    }
}
```

**异常任务响应：**
```json
{
    "status": "error",
    "message": "Task execution failed",
    "error": "Intentional exception: This task will intentionally throw an exception"
}
```

### 异常任务类实现

```php
// app/Tasks/ExceptionDemoTask.php
class ExceptionDemoTask implements TaskInterface
{
    private $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    public function handle(array $args)
    {
        $shouldThrow = $args['should_throw'] ?? false;
        $message = $args['message'] ?? 'No message provided';

        if ($shouldThrow) {
            throw new \RuntimeException("Intentional exception: {$message}");
        }

        return [
            'success' => true,
            'message' => $message,
            'timestamp' => date('Y-m-d H:i:s'),
            'args_received' => $args
        ];
    }
}
```

## 系统状态接口

框架提供 `/api/status` 接口，用于获取系统所有运行时状态信息，包括服务器配置、运行时长、内存使用、Swoole 统计等。

### 接口信息

| 接口 | 方法 | 说明 |
|------|------|------|
| `/api/status` | GET | 获取系统所有状态信息 |

### 使用示例

```bash
curl http://localhost:9501/api/status
```

### 响应示例

```json
{
    "status": "success",
    "data": {
        "timestamp": 1720000000,
        "datetime": "2026-07-27 10:00:00",
        "uptime": 3600,
        "uptime_human": "1h 0m 0s",
        "main_server": "Swoole\\Http\\Server",
        "listen_address": "0.0.0.0",
        "listen_port": 9501,
        "worker_num": 8,
        "swoole_version": "4.8.13",
        "php_version": "8.1.28",
        "framework_version": "1.0.0",
        "environment": "development",
        "memory": {
            "usage": 20971520,
            "usage_human": "20 MB",
            "peak": 33554432,
            "peak_human": "32 MB"
        },
        "server_stats": {
            "start_time": 1720000000,
            "connections": 10,
            "worker_num": 8,
            "idle_worker_num": 7,
            "task_worker_num": 4,
            "idle_task_worker_num": 4,
            "request_count": 1000,
            "pending_task_num": 0
        },
        "routes_count": 5
    }
}
```

### 返回字段说明

| 字段 | 类型 | 说明 |
|------|------|------|
| `timestamp` | int | 当前时间戳 |
| `datetime` | string | 当前日期时间（Y-m-d H:i:s） |
| `uptime` | int | 服务器运行秒数 |
| `uptime_human` | string | 服务器运行时长（人类可读） |
| `main_server` | string | 主服务器类名 |
| `listen_address` | string | 监听地址 |
| `listen_port` | int | 监听端口 |
| `worker_num` | int | Worker 进程数 |
| `swoole_version` | string | Swoole 版本 |
| `php_version` | string | PHP 版本 |
| `framework_version` | string | 框架版本 |
| `environment` | string | 当前运行环境 |
| `memory` | array | 内存使用信息 |
| `memory.usage` | int | 当前内存使用量（字节） |
| `memory.usage_human` | string | 当前内存使用量（人类可读） |
| `memory.peak` | int | 峰值内存使用量（字节） |
| `memory.peak_human` | string | 峰值内存使用量（人类可读） |
| `server_stats` | array | Swoole 服务器运行时统计（仅当服务器运行时可用） |
| `routes_count` | int | 当前注册的路由数量 |

## TCP 服务器

### 启动 TCP 服务

```php
// bin/tcp.php
use Sikelan\Framework;

$app = Framework::getInstance();

// TCP 连接事件
$app->getServer()->on('connect', function ($server, $fd) {
    echo "Client connected: {$fd}\n";
});

// TCP 接收数据事件
$app->getServer()->on('receive', function ($server, $fd, $fromId, $data) {
    echo "Received from {$fd}: {$data}\n";
    
    // 回复客户端
    $server->send($fd, "Server echo: {$data}");
});

// TCP 关闭事件
$app->getServer()->on('close', function ($server, $fd) {
    echo "Client disconnected: {$fd}\n";
});

// 启动 TCP 服务器
$app->run('tcp');
```

### 测试 TCP 连接

```bash
# 使用 telnet 测试
telnet localhost 9501

# 或使用 nc
nc localhost 9501
```

## 自定义进程

### 创建自定义进程

```php
use Sikelan\Process\ProcessManager;

$processManager = new ProcessManager($app->getContainer());

// 添加自定义进程
$processManager->addProcess('data_collector', function (\Swoole\Process $worker) {
    while (true) {
        // 数据采集逻辑
        echo "Collecting data...\n";
        sleep(5);
    }
});

// 添加定时清理进程
$processManager->addProcess('cleaner', function (\Swoole\Process $worker) {
    while (true) {
        // 清理逻辑
        echo "Cleaning up...\n";
        sleep(60);
    }
});

// 启动所有进程
$processManager->startAll();
```

## 日志系统

### 使用日志

```php
use Sikelan\Core\Logger;

$logger = $app->getContainer()->get(Logger::class);

// 不同级别的日志
$logger->emergency('System emergency');
$logger->alert('System alert');
$logger->critical('Critical error');
$logger->error('Error occurred');
$logger->warning('Warning message');
$logger->notice('Notice message');
$logger->info('Info message');
$logger->debug('Debug message');

// 带上下文信息
$logger->info('User login', [
    'user_id' => 1,
    'ip' => '192.168.1.1',
    'time' => date('Y-m-d H:i:s')
]);
```

### 日志配置

```php
// config/app.php
return [
    'log_level' => env('APP_LOG_LEVEL', 'debug'), // debug, info, warning, error
    'log_path' => env('APP_LOG_PATH', __DIR__ . '/../logs'),
    'log_channel' => env('APP_LOG_CHANNEL', 'app'),
];
```

日志文件会按日期分割，例如 `app_2024-01-01.log`。

## 配置管理

### 支持多种配置格式

框架支持 PHP、YAML、JSON 三种配置格式，配置文件放在 `config/` 目录下。

```php
// config/database.php
return [
    'mysql' => [
        'host' => env('DB_HOST', '127.0.0.1'),
        'port' => env('DB_PORT', 3306),
        'username' => env('DB_USERNAME', 'root'),
        'password' => env('DB_PASSWORD', ''),
        'database' => env('DB_DATABASE', 'quant_trade'),
    ],
];
```

```yaml
# config/app.yaml
name: Sikelan
env: development
debug: true
```

```json
// config/cache.json
{
    "redis": {
        "host": "127.0.0.1",
        "port": 6379
    }
}
```

### 获取配置

```php
$config = $app->getConfig();

// 获取配置
$appName = $config->get('app.name');
$dbHost = $config->get('database.mysql.host');

// 设置配置
$config->set('custom.key', 'value');

// 获取默认值
$timeout = $config->get('app.timeout', 30);

// 获取所有配置
$allConfig = $config->all();
```

### 多环境配置支持

框架支持多环境配置，可以为不同环境（如开发、测试、生产）创建独立的配置文件。

#### 环境配置目录结构

```
config/
├── app.php                 # 基础配置（所有环境共享）
├── database.php            # 基础数据库配置
├── cache.php               # 基础缓存配置
├── server.php              # 基础服务器配置
├── dev/                    # 开发环境配置
│   ├── app.php
│   ├── database.php
│   ├── cache.php
│   └── server.php
├── prod/                   # 生产环境配置
│   ├── app.php
│   ├── database.php
│   ├── cache.php
│   └── server.php
└── testing/                # 测试环境配置
    └── app.php
```

#### 配置加载顺序

1. 首先加载 `config/` 目录下的基础配置文件
2. 如果指定了环境，再加载 `config/{env}/` 目录下的环境配置
3. 环境配置会**递归合并覆盖**基础配置（相同键值会被环境配置覆盖）

> **说明**：环境配置目录中可以放置任意配置文件（`app.php`、`database.php`、`cache.php`、`server.php` 等），框架会自动检测并合并同名配置文件。

#### 环境变量优先级

框架按以下优先级确定当前运行环境（从高到低）：

| 优先级 | 来源 | 示例 |
|--------|------|------|
| **1** | 命令行参数 `--env` 或 `-e` | `php bin/start.php --env=dev` |
| **2** | `.env` 文件中的 `APP_ENV` | `APP_ENV=dev` |
| **3** | 默认值 | `development` |

> **重要**：`config/app.php` 中不再需要定义 `env` 字段，框架会自动注入当前环境到 `app.env` 配置中。

#### 启动时指定环境

```bash
# 方式一：使用 --env 参数（最高优先级）
php bin/start.php http --env=dev
php bin/start.php http --env=prod

# 方式二：使用 -e 短参数
php bin/start.php http -e dev
php bin/websocket.php -e prod

# 方式三：通过 .env 文件设置 APP_ENV
# 1. 复制 .env.example 为 .env
# 2. 修改 APP_ENV=dev
# 3. 启动时不传环境参数即可

# 不指定环境时，使用默认值 development
php bin/start.php http
```

#### 环境配置示例

**config/app.php** (基础配置)
```php
return [
    'name' => 'Sikelan',
    'debug' => false,
    'log_level' => 'warning',
];
```

**config/dev/app.php** (开发环境覆盖)
```php
return [
    'debug' => true,           // 覆盖基础配置
    'log_level' => 'debug',   // 覆盖基础配置
    'name' => 'Sikelan Dev',  // 覆盖基础配置
];
```

**config/dev/cache.php** (开发环境缓存配置)
```php
return [
    'redis' => [
        'host' => '127.0.0.1',
        'port' => 6379,
        'password' => '',
        'database' => 0,
        'timeout' => 5,
    ],
];
```

**config/dev/server.php** (开发环境服务器配置)
```php
return [
    'host' => '127.0.0.1',
    'port' => 9502,
    'type' => 'http',
    'settings' => [
        'worker_num' => 2,
        'max_request' => 1000,
        'log_file' => LOG_PATH . '/swoole_dev.log',
        'pid_file' => RUNTIME_PATH . '/server_dev.pid',
    ],
];
```

**config/prod/app.php** (生产环境覆盖)
```php
return [
    'debug' => false,
    'log_level' => 'error',
];
```

**config/prod/cache.php** (生产环境缓存配置)
```php
return [
    'redis' => [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'port' => env('REDIS_PORT', 6379),
        'password' => env('REDIS_PASSWORD', ''),
        'database' => env('REDIS_DATABASE', 0),
    ],
];
```

**config/prod/server.php** (生产环境服务器配置)
```php
return [
    'host' => env('SERVER_HOST', '0.0.0.0'),
    'port' => env('SERVER_PORT', 9502),
    'settings' => [
        'worker_num' => env('SERVER_WORKER_NUM', swoole_cpu_num() * 2),
        'max_request' => env('SERVER_MAX_REQUEST', 10000),
        'log_file' => env('SERVER_LOG_FILE', LOG_PATH . '/swoole.log'),
        'pid_file' => env('SERVER_PID_FILE', RUNTIME_PATH . '/server.pid'),
    ],
];
```

#### 获取当前环境

```php
// 方式一：通过 Framework 方法
$app = Framework::getInstance('dev');
$environment = $app->getEnvironment(); // 返回 'dev'

// 方式二：通过 Config 对象
$config = $app->getConfig();
$env = $config->getEnvironment(); // 返回 'dev'

// 方式三：通过配置键（框架自动注入）
$env = $config->get('app.env'); // 返回 'dev'
```

#### .env 文件示例

项目根目录提供 `.env.example` 文件作为模板：

```bash
# 复制模板文件
cp .env.example .env

# 编辑 .env 文件配置环境
APP_ENV=development
APP_NAME=Sikelan
APP_DEBUG=true
APP_LOG_LEVEL=debug

# 数据库配置
DB_HOST=127.0.0.1
DB_PORT=3306
DB_USERNAME=root
DB_PASSWORD=
DB_DATABASE=sikelan

# Redis 配置
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=
REDIS_DATABASE=0
```

> **注意**：`.env` 文件中的 `APP_ENV` 可以被命令行参数 `--env` 覆盖。

#### 实际应用场景

```bash
# 本地开发
php bin/start.php http --env=dev

# 测试环境
php bin/start.php http --env=testing

# 生产环境
php bin/start.php http --env=prod

# 使用 .env 文件配置（APP_ENV=prod）
php bin/start.php http
```

## 系统常量

框架启动时会自动定义以下系统常量，方便在应用中使用：

| 常量名 | 说明 | 默认值 |
|--------|------|--------|
| `BASE_PATH` | 项目根目录 | `/path/to/whale` |
| `APP_PATH` | 应用代码目录 | `BASE_PATH/app` |
| `CONFIG_PATH` | 配置文件目录 | `BASE_PATH/config` |
| `RUNTIME_PATH` | 运行时文件目录 | `BASE_PATH/runtime` |
| `LOG_PATH` | 日志文件目录 | `BASE_PATH/logs` |
| `FRAMEWORK_PATH` | 框架核心目录 | `BASE_PATH/sikelan` |
| `VENDOR_PATH` | 第三方依赖目录 | `BASE_PATH/vendor` |
| `STORAGE_PATH` | 存储目录 | `BASE_PATH/storage` |

### 使用示例

```php
// 在配置文件中使用
return [
    'log_path' => LOG_PATH,
    'pid_file' => RUNTIME_PATH . '/server.pid',
];

// 在代码中使用
$logFile = LOG_PATH . '/app.log';
$pidFile = RUNTIME_PATH . '/server.pid';
```

### 公用函数

框架提供了以下公用函数：

| 函数名 | 说明 | 示例 |
|--------|------|------|
| `env($key, $default)` | 获取环境变量 | `env('DB_HOST', '127.0.0.1')` |

`env()` 函数支持从 `.env` 文件加载环境变量，并自动解析布尔值、整数和 null 值。

## 目录结构

```
whale/
├── app/                    # 应用代码目录
│   ├── Commands/           # 自定义命令
│   │   └── HelloCommand.php
│   ├── Controllers/        # 控制器
│   │   └── UserController.php
│   ├── Services/           # 服务层
│   │   └── UserService.php
│   └── Tasks/              # 任务类
│       └── SendEmailTask.php
├── bin/                    # 启动脚本
│   ├── sikelan             # 命令行入口
│   ├── start.php           # HTTP 启动
│   ├── websocket.php       # WebSocket 启动
│   └── tcp.php             # TCP 启动
├── config/                 # 配置文件
│   ├── app.php             # 应用配置（基础）
│   ├── cache.php           # 缓存配置（基础）
│   ├── commands.php        # 命令注册配置
│   ├── database.php        # 数据库配置（基础）
│   ├── router.php          # 路由配置
│   ├── server.php          # 服务器配置（基础）
│   ├── dev/                # 开发环境配置
│   │   ├── app.php
│   │   ├── database.php
│   │   ├── cache.php
│   │   └── server.php
│   ├── prod/               # 生产环境配置
│   │   ├── app.php
│   │   ├── database.php
│   │   ├── cache.php
│   │   └── server.php
│   └── testing/            # 测试环境配置
│       └── app.php
├── logs/                   # 日志目录
├── runtime/                # 运行时文件
├── sikelan/                # 框架核心代码
│   ├── Cache/              # 缓存组件
│   │   └── RedisCache.php
│   ├── Command/            # 命令控制组件
│   │   ├── CommandInterface.php
│   │   ├── CommandManager.php
│   │   ├── CommandRunner.php
│   │   └── DefaultCommand/ # 内置命令
│   │       ├── ServerCommand.php
│   │       ├── HelpCommand.php
│   │       ├── MakeControllerCommand.php
│   │       ├── MakeModelCommand.php
│   │       ├── MakeTaskCommand.php
│   │       └── ConfigCommand.php
│   ├── Core/               # 核心组件
│   │   ├── Config.php      # 配置管理
│   │   ├── Container.php   # 依赖注入容器
│   │   ├── Logger.php      # 日志组件
│   │   ├── constants.php   # 系统常量定义
│   │   └── common.php      # 公用函数定义
│   ├── Crontab/            # 定时任务
│   │   └── Crontab.php
│   ├── Database/           # 数据库组件
│   │   └── MysqlPool.php
│   ├── Http/               # HTTP组件
│   │   ├── Request.php
│   │   ├── Response.php
│   │   ├── Router.php
│   │   └── Uri.php
│   ├── Process/            # 进程管理
│   │   └── ProcessManager.php
│   ├── Server/             # 服务器管理
│   │   └── Server.php
│   ├── Task/               # 任务系统
│   │   ├── TaskInterface.php
│   │   └── TaskManager.php
│   └── Framework.php       # 框架入口
├── tests/                  # 测试用例
│   ├── bootstrap.php       # 测试引导
│   ├── ContainerTest.php
│   ├── ConfigTest.php
│   └── RouterTest.php
├── .env                    # 环境变量
├── .env.example            # 环境变量示例
├── composer.json           # 依赖配置
└── phpunit.xml             # 测试配置
```

## 部署指南

### 环境要求

- PHP 7.4+
- Swoole 4.6.3+ (需编译安装)
- MySQL 8.0+
- Redis 6.0+

### 安装 Swoole

```bash
# 安装 Swoole 扩展
pecl install swoole-4.6.3

# 或使用源码编译
git clone https://github.com/swoole/swoole-src.git
cd swoole-src
git checkout v4.6.3
phpize
./configure --enable-coroutine --enable-openssl --enable-http2
make && make install
```

### 启动服务

```bash
# 开发环境
composer start

# 生产环境 - 使用 nohup 后台运行
nohup composer start:http > /dev/null 2>&1 &

# 查看进程
ps aux | grep start.php

# 停止服务
kill -9 <pid>
```

### 配置 Supervisor

```ini
[program:sikelan]
command=php /path/to/project/bin/start.php
directory=/path/to/project
user=www-data
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=/var/log/sikelan.log
```

## 性能优化

### 协程安全

- 避免使用全局变量和静态变量
- 注意协程上下文隔离
- 使用协程安全的客户端（Swoole Coroutine\MySQL, Coroutine\Redis）

### 连接池配置

```php
// config/database.php
return [
    'mysql' => [
        'pool_size' => 20, // 增加连接池大小
        'timeout' => 3,     // 减少超时时间
    ],
];
```

### 服务器优化

```php
// config/server.php
return [
    'settings' => [
        'worker_num' => swoole_cpu_num() * 4, // 增加 worker 进程数
        'max_request' => 50000,              // 增加最大请求数
        'task_worker_num' => swoole_cpu_num() * 2,
        'enable_coroutine' => true,
        'open_tcp_nodelay' => true,
        'reuse_port' => true,
        'buffer_output_size' => 2 * 1024 * 1024, // 2MB
    ],
];
```

## 运行测试

```bash
# 运行所有测试
composer test

# 运行 stest 目录下的单例测试
composer test:stest

# 运行指定测试
./vendor/bin/phpunit tests/ContainerTest.php

# 生成测试覆盖率报告
./vendor/bin/phpunit --coverage-html coverage/
```

## 代码检查

项目使用 [PHP CodeSniffer](https://github.com/squizlabs/PHP_CodeSniffer) 进行代码风格检查，遵循 PSR-12 标准和项目自定义规则。

### 检查代码风格

```bash
# 运行代码检查
composer lint

# 自动修复代码风格问题
composer lint:fix
```

### 代码规范

项目代码规范定义在 [global-style.md](file:///Users/wmc/data/trae/project/whale/global-style.md) 文件中，包含：

- **架构设计约束**: 组件化设计、依赖注入、单一职责等
- **命名规范**: 类名（PascalCase）、方法名（camelCase）、变量名（camelCase）等
- **代码风格**: 缩进（4空格）、行宽（120字符）、括号风格（Allman）等
- **编码规范**: PSR-1、PSR-2、PSR-12 标准
- **安全规范**: SQL 注入防护、XSS 防护等

### 配置文件

代码检查配置文件位于 `phpcs.xml`，包含以下主要配置：

```xml
<?xml version="1.0"?>
<ruleset name="Sikelan Framework Coding Standard">
    <description>Sikelan Framework 项目代码规范，基于 PSR-12 扩展</description>
    
    <arg name="encoding" value="UTF-8"/>
    <arg name="tab-width" value="4"/>
    
    <file>sikelan/</file>
    <file>app/</file>
    <file>tests/</file>
    <file>bin/</file>
    <file>config/</file>
    <file>bootstrap/</file>
    
    <rule ref="PSR12"/>
    
    <!-- 自定义规则 -->
    <rule ref="Generic.WhiteSpace.ScopeIndent">
        <properties>
            <property name="indent" value="4"/>
            <property name="tabIndent" value="false"/>
        </properties>
    </rule>
    
    <rule ref="Generic.Files.LineLength">
        <properties>
            <property name="lineLimit" value="120"/>
        </properties>
    </rule>
</ruleset>
```

## License

MIT License