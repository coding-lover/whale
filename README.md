# Sikelan Framework

基于 Swoole 的高性能 PHP 框架，专为量化交易系统设计，支持高并发、低延迟的服务端开发。

**项目规则**: 请参考 [global-style.md](file:///Users/wmc/data/trae/project/whale/global-style.md) 了解项目的架构设计、命名规范、代码风格等核心约束。

---

## 目录

- [第一部分：引言](#第一部分引言)
  - [功能特性](#功能特性)
  - [技术栈](#技术栈)
  - [核心概念](#核心概念)
    - [框架架构](#框架架构)
    - [生命周期](#生命周期)
    - [组件交互](#组件交互)
- [第二部分：快速入门](#第二部分快速入门)
  - [环境要求](#环境要求)
  - [安装步骤](#安装步骤)
  - [安装 Swoole（详细）](#安装-swoole详细)
  - [创建简单的 HTTP 服务](#创建简单的-http-服务)
  - [完整示例](#完整示例)
  - [生产环境部署](#生产环境部署)
    - [启动方式](#启动方式)
    - [使用 Supervisor 管理](#使用-supervisor-管理)
    - [使用 Systemd 管理](#使用-systemd-管理)
- [第三部分：核心功能](#第三部分核心功能)
  - [路由系统](#路由系统)
    - [配置化路由](#配置化路由)
    - [路由方法（代码方式）](#路由方法代码方式)
    - [路由参数](#路由参数)
    - [路由分组](#路由分组)
    - [请求示例](#请求示例)
  - [请求与响应](#请求与响应)
    - [获取请求信息](#获取请求信息)
    - [返回响应](#返回响应)
    - [使用控制器](#使用控制器)
  - [依赖注入](#依赖注入)
    - [基本用法](#基本用法)
    - [自动装配](#自动装配)
- [第四部分：高级特性](#第四部分高级特性)
  - [Hook 机制](#hook-机制)
    - [工作流程](#工作流程)
    - [启用 Hook](#启用-hook)
    - [配置参数说明](#配置参数说明)
    - [创建 Hook 类](#创建-hook-类)
    - [完整示例：实现请求中间件](#完整示例实现请求中间件)
    - [完整示例：添加自定义监控进程](#完整示例添加自定义监控进程)
    - [事件回调说明](#事件回调说明)
    - [注意事项](#注意事项)
  - [异步任务](#异步任务)
    - [创建任务类](#创建任务类)
    - [投递任务](#投递任务)
    - [异常处理](#异常处理)
  - [定时任务](#定时任务)
    - [添加定时任务](#添加定时任务)
    - [Cron 表达式格式](#cron-表达式格式)
    - [常用表达式](#常用表达式)
  - [自定义进程](#自定义进程)
    - [方式一：通过数组配置注册（简单场景）](#方式一通过数组配置注册简单场景)
    - [方式二：通过 AbstractProcess 实例注册（推荐）](#方式二通过-abstractprocess-实例注册推荐)
    - [两种方式对比](#两种方式对比)
    - [注意事项](#注意事项-1)
  - [服务器类型](#服务器类型)
    - [HTTP 服务器](#http-服务器)
    - [WebSocket 服务器](#websocket-服务器)
    - [TCP 服务器](#tcp-服务器)
    - [客户端连接示例](#客户端连接示例)
- [第五部分：基础设施](#第五部分基础设施)
  - [缓存操作](#缓存操作)
    - [Redis 缓存](#redis-缓存)
    - [缓存最佳实践](#缓存最佳实践)
  - [数据库操作](#数据库操作)
    - [MySQL 连接池](#mysql-连接池)
    - [事务处理](#事务处理)
    - [原生查询](#原生查询)
    - [安全规范](#安全规范)
  - [日志系统](#日志系统)
    - [使用日志](#使用日志)
    - [日志配置](#日志配置)
    - [日志级别](#日志级别)
  - [配置管理](#配置管理)
    - [配置格式](#配置格式)
    - [获取配置](#获取配置)
    - [多环境配置](#多环境配置)
    - [环境变量](#环境变量)
  - [系统常量与公用函数](#系统常量与公用函数)
    - [系统常量](#系统常量)
    - [公用函数](#公用函数)
- [第六部分：命令与运维](#第六部分命令与运维)
  - [命令控制](#命令控制)
    - [命令入口](#命令入口)
    - [查看命令列表](#查看命令列表)
    - [服务器管理](#服务器管理)
    - [代码生成](#代码生成)
    - [配置管理](#配置管理)
    - [自定义命令](#自定义命令)
    - [内置命令列表](#内置命令列表)
  - [性能优化](#性能优化)
    - [服务器配置](#服务器配置)
    - [连接池配置](#连接池配置)
    - [缓存策略](#缓存策略)
    - [协程安全](#协程安全)
  - [测试与代码检查](#测试与代码检查)
    - [运行测试](#运行测试)
    - [代码检查](#代码检查)
    - [代码规范](#代码规范)
- [第七部分：附录](#第七部分附录)
  - [目录结构](#目录结构)
  - [常见问题](#常见问题)
  - [License](#license)

---

# 第一部分：引言

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
- **Hook 机制**: 允许用户自定义事件回调和进程，不侵入框架核心
- **代码生成**: 自动生成控制器、模型、任务类等，提升开发效率

## 技术栈

| 组件 | 版本要求 | 说明 |
|------|----------|------|
| PHP | 7.4+ | 目标 PHP 版本 |
| Swoole | 4.6.3+ | 高性能网络框架 |
| MySQL | 8.0+ | 关系数据库 |
| Redis | 6.0+ | 缓存与消息队列 |
| Composer | 2.x | PHP 包管理器 |

## 核心概念

### 框架架构

```
┌─────────────────────────────────────────────────────────────┐
│                      Framework (总指挥)                       │
│  - 生命周期编排                                              │
│  - 组件初始化                                                │
│  - 事件注册调度                                              │
├─────────────────────────────────────────────────────────────┤
│                      Server 组件                             │
│  - Swoole 实例创建                                           │
│  - EventRegister 事件管理                                    │
│  - 服务器启动/停止                                           │
├─────────────────────────────────────────────────────────────┤
│  RequestHandler │ TaskManager │ Crontab │ Router            │
│  - HTTP请求处理  │ - onTask    │ - 定时任务 │ - 路由分发      │
│                 │ - onFinish  │           │                │
└─────────────────────────────────────────────────────────────┘
```

### 生命周期

```
Framework.getInstance()
  │
  ├─ 加载常量和公共函数
  ├─ 解析运行环境
  ├─ 初始化容器和核心组件
  ├─ 初始化事件处理组件
  ├─ 加载路由配置
  └─ 加载 Hook（如果配置了）

Framework.run(mode)
  │
  ├─ Server.create(mode)           // 创建 Swoole 实例
  ├─ Framework.printStatus()      // 打印框架状态
  ├─ Hook.onInitialize()           // 【Hook】初始化阶段
  ├─ registerEvents()             // 注册默认事件回调
  │   └─ Hook.registerEvents()     // 【Hook】覆盖同名事件回调
  ├─ registerProcesses()           // 注册自定义进程
  │   └─ Hook.registerProcesses()  // 【Hook】返回进程列表
  ├─ Hook.onServerStart()          // 【Hook】启动前
  └─ Server.start()                // 启动服务器
```

### 组件交互

```php
// 获取框架实例
$app = Framework::getInstance();

// 获取核心组件
$container = $app->getContainer();   // 依赖注入容器
$config = $app->getConfig();         // 配置管理
$logger = $app->getLogger();         // 日志系统

// 获取功能组件
$router = $app->getRouter();         // 路由
$taskManager = $app->getTaskManager(); // 任务管理
$crontab = $app->getCrontab();       // 定时任务
$cache = $app->getCache();           // 缓存
$db = $app->getDb();                 // 数据库
$server = $app->getServer();         // 服务器
```

---

# 第二部分：快速入门

## 环境要求

- PHP 7.4+
- Swoole 4.6.3+（需编译安装）
- MySQL 8.0+
- Redis 6.0+

## 安装步骤

```bash
# 1. 克隆项目
git clone <repository-url>
cd whale

# 2. 安装依赖
composer install

# 3. 安装 Swoole 扩展
pecl install swoole-4.6.3

# 4. 复制环境变量配置
cp .env.example .env

# 5. 启动服务（HTTP 模式）
composer start
```

## 安装 Swoole（详细）

```bash
# 方式一：使用 PECL
pecl install swoole-4.6.3

# 方式二：源码编译
git clone https://github.com/swoole/swoole-src.git
cd swoole-src
git checkout v4.6.3
phpize
./configure --enable-coroutine --enable-openssl --enable-http2
make && make install

# 验证安装
php -m | grep swoole
# 输出: swoole
```

## 创建简单的 HTTP 服务

```php
// bin/start.php
use Sikelan\Framework;

// 获取框架单例
$app = Framework::getInstance();

// 启动 HTTP 服务器
$app->run('http');
```

访问 `http://localhost:9501` 即可看到响应。

## 完整示例

```php
// app/Controllers/HelloController.php
namespace App\Controllers;

use Sikelan\Http\Request;

class HelloController
{
    public function welcome(Request $request, $params)
    {
        return [
            'status' => 'success',
            'message' => 'Hello, Sikelan!',
            'time' => date('Y-m-d H:i:s'),
        ];
    }
}
```

```php
// config/router.php
return [
    [
        'method' => 'GET',
        'path' => '/hello',
        'handler' => 'App\Controllers\HelloController@welcome',
    ],
];
```

```bash
# 启动服务
php bin/start.php http

# 测试接口
curl http://localhost:9501/hello
# 输出: {"status":"success","message":"Hello, Sikelan!","time":"..."}
```

## 生产环境部署

### 启动方式

```bash
# 使用守护进程模式
php bin/sikelan server start --env=prod --daemon

# 或使用 nohup
nohup php bin/start.php http --env=prod > /dev/null 2>&1 &
```

### 使用 Supervisor 管理

```ini
# /etc/supervisor/conf.d/sikelan.conf
[program:sikelan]
command=php /path/to/project/bin/start.php --env=prod
directory=/path/to/project
user=www-data
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=/var/log/sikelan.log
```

```bash
# 重新加载配置
supervisorctl reread
supervisorctl update
```

### 使用 Systemd 管理

```ini
# /etc/systemd/system/sikelan.service
[Unit]
Description=Sikelan Framework
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/path/to/project
ExecStart=/usr/bin/php /path/to/project/bin/start.php --env=prod
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

```bash
# 启动服务
systemctl start sikelan
systemctl enable sikelan

# 查看状态
systemctl status sikelan
journalctl -u sikelan -f
```

---

# 第三部分：核心功能

## 路由系统

### 配置化路由

框架支持通过配置文件定义路由，所有路由集中管理在 `config/router.php` 中：

```php
// config/router.php
return [
    // 闭包路由
    [
        'method' => 'GET',
        'path' => '/',
        'handler' => function () {
            return ['status' => 'success', 'message' => 'Sikelan Framework is running'];
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

**路由配置格式：**

| 字段 | 类型 | 说明 |
|------|------|------|
| `method` | string \| array | HTTP 方法：`GET`、`POST`、`PUT`、`DELETE` 或数组 |
| `path` | string | 路由路径，支持参数如 `{id}` |
| `handler` | callable \| string | 处理器：闭包或 `Controller@method` 格式 |

### 路由方法（代码方式）

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

// 支持所有方法
$router->any('/api/test', function ($request) {
    return ['method' => $request->getMethod()];
});
```

### 路由参数

```php
// 单个参数
$router->get('/users/{id}', function ($request, $params) {
    return ['user_id' => $params['id']];
});

// 多个参数
$router->get('/users/{userId}/orders/{orderId}', function ($request, $params) {
    return ['user_id' => $params['userId'], 'order_id' => $params['orderId']];
});

// 带正则约束的参数
$router->get('/products/{id:\d+}', function ($request, $params) {
    return ['product_id' => (int)$params['id']];
});
```

### 路由分组

```php
$router->group('/api/v1', function ($r) {
    $r->get('/users', 'App\Controllers\UserController@index');
    $r->get('/users/{id}', 'App\Controllers\UserController@show');
    $r->post('/users', 'App\Controllers\UserController@store');
    
    // 嵌套分组
    $r->group('/admin', function ($admin) {
        $admin->get('/dashboard', function () {
            return ['dashboard' => 'admin'];
        });
    });
});
```

### 请求示例

```bash
# GET 请求
curl http://localhost:9501/users

# POST 请求
curl -X POST -H "Content-Type: application/json" \
  -d '{"name":"John","email":"john@example.com"}' \
  http://localhost:9501/users

# 带查询参数
curl "http://localhost:9501/users?page=1&limit=10"

# 路径参数
curl http://localhost:9501/users/123

# 分组路由
curl http://localhost:9501/api/v1/users
curl http://localhost:9501/api/v1/admin/dashboard
```

## 请求与响应

### 获取请求信息

```php
$router->get('/test', function ($request) {
    // 请求方法
    $method = $request->getMethod();
    
    // 请求 URI
    $uri = $request->getUri()->getPath();
    
    // 查询参数
    $query = $request->getQueryParams();
    
    // POST 参数
    $post = $request->getPostParams();
    
    // 单个参数（优先查询参数，其次 POST 参数）
    $id = $request->getParam('id', 1);
    
    // 服务器参数
    $server = $request->getServerParams();
    $ip = $server['remote_addr'];
    
    // 请求头
    $contentType = $request->getHeaderLine('Content-Type');
    
    // Cookie
    $cookies = $request->getCookies();
    
    return compact('method', 'uri', 'query', 'post', 'id', 'ip');
});
```

### 返回响应

```php
// 返回 JSON
return ['status' => 'success', 'data' => $data];

// 返回 HTML
return '<html><body><h1>Hello</h1></body></html>';

// 重定向
// 注意：需使用 Response 对象
// return (new Response())->withRedirect('/new-url');

// 错误状态码
http_response_code(404);
return ['error' => 'Not Found'];
```

### 使用控制器

```php
// app/Controllers/UserController.php
namespace App\Controllers;

use Sikelan\Http\Request;

class UserController
{
    public function index(Request $request, $params)
    {
        $page = $request->getParam('page', 1);
        $limit = $request->getParam('limit', 10);
        
        return [
            'users' => [],
            'pagination' => ['page' => $page, 'limit' => $limit]
        ];
    }
    
    public function show(Request $request, $params)
    {
        $userId = $params['id'];
        return ['user' => ['id' => $userId, 'name' => 'John Doe']];
    }
    
    public function store(Request $request, $params)
    {
        $data = $request->getPostParams();
        return ['message' => '用户创建成功', 'data' => $data];
    }
}
```

## 依赖注入

### 基本用法

```php
$app = Framework::getInstance();
$container = $app->getContainer();

// 设置实例
$container->set('config', $config);

// 设置工厂
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
// app/Services/UserService.php
namespace App\Services;

use Sikelan\Database\MysqlPool;
use Sikelan\Cache\RedisCache;

class UserService
{
    protected MysqlPool $db;
    protected RedisCache $cache;
    
    public function __construct(MysqlPool $db, RedisCache $cache)
    {
        $this->db = $db;
        $this->cache = $cache;
    }
    
    public function getUser(int $id)
    {
        // 先尝试从缓存获取
        $cached = $this->cache->get("user:{$id}");
        if ($cached) {
            return json_decode($cached, true);
        }
        
        // 从数据库查询
        $user = $this->db->select('SELECT * FROM users WHERE id = ?', [$id]);
        $user = $user[0] ?? null;
        
        // 存入缓存
        if ($user) {
            $this->cache->set("user:{$id}", json_encode($user), 3600);
        }
        
        return $user;
    }
}

// 自动解析依赖
$userService = $container->get(UserService::class);
$user = $userService->getUser(1);
```

---

# 第四部分：高级特性

## Hook 机制

Hook 机制允许用户在不修改框架核心代码的情况下，自定义事件回调和添加自定义进程。这是框架扩展的核心机制。

### 工作流程

```
Framework.run()
  │
  ├─ 1. 创建服务器实例
  │
  ├─ 2. Hook.onInitialize()        ← 【Hook】初始化阶段
  │     可在此做：环境检查、自定义组件初始化等
  │
  ├─ 3. 注册默认事件回调
  │     └─ Hook.registerEvents()  ← 【Hook】覆盖同名事件回调
  │        返回的事件会覆盖框架默认的同名事件
  │
  ├─ 4. 注册自定义进程
  │     └─ Hook.registerProcesses() ← 【Hook】返回进程列表
  │        进程会被绑定到 Swoole Server，由其管理生命周期
  │
  ├─ 5. Hook.onServerStart()       ← 【Hook】启动前
  │     可在此做：最终检查、日志记录等
  │
  └─ 6. Server.start()             ← 启动服务器
```

### 启用 Hook

#### 方式一：配置文件

```php
// config/app.php
return [
    // 其他配置...
    
    // 启用 Hook 类
    'hook' => \App\Hooks\AppHook::class,
];
```

#### 方式二：环境变量

```bash
# .env 文件
APP_HOOK=\App\Hooks\AppHook
```

不配置时，框架使用默认行为，不会加载任何 Hook。

### 配置参数说明

Hook 类支持以下四个方法（钩子），每个方法的用途和参数说明如下：

| 方法 | 触发时机 | 参数 | 返回值 | 说明 |
|------|----------|------|--------|------|
| `onInitialize(Server $server)` | 框架初始化阶段，在服务器创建后、事件注册前调用 | `$server` - 服务器组件实例 | `void` | 用于环境检查、自定义组件初始化、加载额外配置等 |
| `onServerStart(Server $server)` | 服务器即将启动前，在事件注册和进程绑定完成后调用 | `$server` - 服务器组件实例 | `void` | 用于健康检查、日志记录、预加载数据等启动前的最终准备工作 |
| `registerEvents(): array` | 注册事件回调阶段调用 | 无 | `array` - 事件名 => 回调函数 | 返回需要覆盖的事件回调，同名事件会替换框架默认回调；支持的事件见下方事件列表 |
| `registerProcesses(): array` | 注册自定义进程阶段调用 | 无 | `array` - 进程配置数组 | 返回需要绑定到 Swoole Server 的自定义进程列表 |

**`registerEvents()` 支持的事件列表：**

| 事件名称 | 说明 | 回调参数 |
|----------|------|----------|
| `request` | HTTP 请求事件 | `(Request $request, Response $response)` |
| `task` | 异步任务事件 | `(Server $server, int $taskId, int $workerId, string $data)` |
| `finish` | 任务完成事件 | `(Server $server, int $taskId, string $data)` |
| `workerStart` | Worker 启动事件 | `(Server $server, int $workerId)` |
| `workerStop` | Worker 停止事件 | `(Server $server, int $workerId)` |
| `connect` | TCP 连接事件 | `(Server $server, int $fd)` |
| `receive` | TCP 接收数据 | `(Server $server, int $fd, int $fromId, string $data)` |
| `close` | 连接关闭事件 | `(Server $server, int $fd)` |
| `open` | WebSocket 连接 | `(Server $server, Request $request)` |
| `message` | WebSocket 消息 | `(Server $server, Frame $frame)` |

**`registerProcesses()` 进程配置参数：**

| 参数 | 类型 | 必填 | 默认值 | 说明 |
|------|------|------|--------|------|
| `name` | string | 是 | - | 进程名称，用于日志标识，需唯一 |
| `callback` | callable | 是 | - | 进程执行的回调函数，接收 `Swoole\Process $worker` 参数 |
| `redirectStdinStdout` | bool | 否 | `false` | 是否重定向标准输入输出到管道 |
| `pipeType` | int | 否 | `2` | 管道类型：`0`=无, `1`=只读, `2`=读写 |

### 创建 Hook 类

#### 1. 创建基础 Hook 类

```php
// app/Hooks/AppHook.php
namespace App\Hooks;

use Sikelan\Hook\AbstractHook;
use Sikelan\Server\Server;

class AppHook extends AbstractHook
{
    /**
     * 框架初始化阶段钩子
     */
    public function onInitialize(Server $server): void
    {
        $this->logger->info('AppHook: onInitialize called');
        
        // 在此做初始化工作，比如：
        // - 检查环境
        // - 初始化自定义组件
        // - 加载额外配置
    }
    
    /**
     * 服务器启动前钩子
     */
    public function onServerStart(Server $server): void
    {
        $this->logger->info('AppHook: onServerStart called');
        
        // 在此做启动前的检查，比如：
        // - 健康检查
        // - 日志记录
        // - 预加载数据
    }
    
    /**
     * 注册自定义事件回调
     * 返回的事件会覆盖框架默认的同名事件
     */
    public function registerEvents(): array
    {
        return [
            // 示例：覆盖 request 事件，添加请求日志
            'request' => function ($request, $response) {
                $startTime = microtime(true);
                
                $this->logger->info('Request incoming', [
                    'method' => $request->server['request_method'] ?? 'GET',
                    'uri' => $request->server['request_uri'] ?? '/',
                    'ip' => $request->server['remote_addr'] ?? 'unknown',
                ]);
                
                // 处理请求...
                $response->header('Content-Type', 'application/json');
                $response->end(json_encode([
                    'code' => 200,
                    'message' => 'Handled by AppHook',
                ]));
                
                // 记录耗时
                $duration = round((microtime(true) - $startTime) * 1000, 2);
                $this->logger->info("Request completed in {$duration}ms");
            },
        ];
    }
    
    /**
     * 注册自定义进程
     * 返回的进程会绑定到 Swoole Server，由其管理生命周期
     */
    public function registerProcesses(): array
    {
        return [
            [
                'name' => 'heartbeat',
                'callback' => function (\Swoole\Process $worker) {
                    $this->logger->info('Heartbeat process started');
                    
                    // 每 60 秒输出一次心跳
                    while (true) {
                        sleep(60);
                        $this->logger->info('Heartbeat: ' . date('Y-m-d H:i:s'));
                    }
                },
                'redirectStdinStdout' => false,
                'pipeType' => 2,
            ],
        ];
    }
}
```

### 完整示例：实现请求中间件

```php
// app/Hooks/MiddlewareHook.php
namespace App\Hooks;

use Sikelan\Hook\AbstractHook;
use Sikelan\Server\Server;

class MiddlewareHook extends AbstractHook
{
    /**
     * 实现 CORS 跨域中间件
     */
    public function registerEvents(): array
    {
        return [
            'request' => function ($request, $response) {
                // CORS 处理
                $response->header('Access-Control-Allow-Origin', '*');
                $response->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
                $response->header('Access-Control-Allow-Headers', 'Content-Type, Authorization');
                
                // OPTIONS 预检请求直接返回
                $method = $request->server['request_method'] ?? 'GET';
                if ($method === 'OPTIONS') {
                    $response->status(200);
                    $response->end();
                    return;
                }
                
                // 认证检查（示例）
                $token = $request->header['authorization'] ?? '';
                if (empty($token) && $method !== 'GET') {
                    $response->status(401);
                    $response->header('Content-Type', 'application/json');
                    $response->end(json_encode(['error' => 'Unauthorized']));
                    return;
                }
                
                // 继续处理请求...
                // 注意：这里需要自行实现完整的请求处理逻辑
                // 因为覆盖了默认的 request 事件
            },
        ];
    }
}
```

### 完整示例：添加自定义监控进程

```php
// app/Hooks/MonitorHook.php
namespace App\Hooks;

use Sikelan\Hook\AbstractHook;
use Sikelan\Server\Server;

class MonitorHook extends AbstractHook
{
    public function registerProcesses(): array
    {
        return [
            // 系统监控进程
            [
                'name' => 'system_monitor',
                'callback' => function (\Swoole\Process $worker) {
                    $this->logger->info('System monitor started');
                    
                    while (true) {
                        $stats = [
                            'timestamp' => time(),
                            'memory' => memory_get_usage(true),
                            'connections' => $this->server->getServer() ? 
                                count($this->server->getServer()->connections) : 0,
                        ];
                        
                        // 记录监控数据
                        $this->logger->debug('System stats', $stats);
                        
                        // 如果内存超过 80%，触发警告
                        $memoryUsage = memory_get_usage(true) / memory_get_usage(true);
                        if ($memoryUsage > 0.8) {
                            $this->logger->warning('High memory usage detected');
                        }
                        
                        sleep(30); // 每 30 秒检查一次
                    }
                },
                'redirectStdinStdout' => false,
                'pipeType' => 2,
            ],
            
            // 数据同步进程
            [
                'name' => 'data_sync',
                'callback' => function (\Swoole\Process $worker) {
                    $this->logger->info('Data sync process started');
                    
                    while (true) {
                        $this->logger->info('Starting data sync...');
                        
                        try {
                            // 执行数据同步逻辑
                            $this->syncData();
                            $this->logger->info('Data sync completed');
                        } catch (\Throwable $e) {
                            $this->logger->error('Data sync failed: ' . $e->getMessage());
                        }
                        
                        // 每小时同步一次
                        sleep(3600);
                    }
                },
                'redirectStdinStdout' => false,
                'pipeType' => 2,
            ],
        ];
    }
    
    private function syncData(): void
    {
        // 数据同步逻辑
    }
}
```

### 事件回调说明

| 事件名称 | 说明 | 回调参数 |
|----------|------|----------|
| `request` | HTTP 请求事件 | `(Request $request, Response $response)` |
| `task` | 异步任务事件 | `(Server $server, int $taskId, int $workerId, string $data)` |
| `finish` | 任务完成事件 | `(Server $server, int $taskId, string $data)` |
| `workerStart` | Worker 启动事件 | `(Server $server, int $workerId)` |
| `workerStop` | Worker 停止事件 | `(Server $server, int $workerId)` |
| `connect` | TCP 连接事件 | `(Server $server, int $fd)` |
| `receive` | TCP 接收数据 | `(Server $server, int $fd, int $fromId, string $data)` |
| `close` | 连接关闭事件 | `(Server $server, int $fd)` |
| `open` | WebSocket 连接 | `(Server $server, Request $request)` |
| `message` | WebSocket 消息 | `(Server $server, Frame $frame)` |

### 注意事项

1. **覆盖 vs 追加**：使用 `registerEvents()` 返回的事件会**覆盖**默认回调。如果需要同时执行默认逻辑和自定义逻辑，需要在自定义回调中手动调用默认逻辑。

2. **进程生命周期**：通过 `registerProcesses()` 注册的进程由 Swoole Server 统一管理，会在服务器启动时自动创建，停止时自动销毁。

3. **异常处理**：在 Hook 回调中抛出的异常会被框架捕获并记录到日志中，不会导致服务器崩溃。

4. **性能影响**：覆盖 `request` 事件会接管所有 HTTP 请求的处理，需要确保实现高效，避免成为性能瓶颈。

5. **调试技巧**：使用 `$this->logger` 在 Hook 中记录日志，方便调试和监控。

## 异步任务

### 创建任务类

```php
// app/Tasks/SendEmailTask.php
namespace App\Tasks;

use Sikelan\Task\TaskInterface;
use Sikelan\Core\Logger;

class SendEmailTask implements TaskInterface
{
    protected Logger $logger;
    
    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }
    
    public function handle(array $args)
    {
        $to = $args['to'] ?? '';
        $subject = $args['subject'] ?? 'No Subject';
        
        $this->logger->info("Sending email to: {$to}");
        
        // 执行发送逻辑
        // ...
        
        return ['success' => true, 'message' => 'Email sent'];
    }
}
```

### 投递任务

```php
$app = Framework::getInstance();
$taskManager = $app->getTaskManager();

// 异步执行（不等待结果）
$taskManager->async(\App\Tasks\SendEmailTask::class, [
    'to' => 'user@example.com',
    'subject' => 'Welcome',
]);

// 异步执行（带回调）
$taskManager->async(\App\Tasks\SendEmailTask::class, [
    'to' => 'admin@example.com',
], function ($result) {
    if ($result['success']) {
        echo "Email sent successfully";
    }
});

// 同步执行（等待结果）
$result = $taskManager->sync(\App\Tasks\SendEmailTask::class, [
    'to' => 'sync@example.com'
]);
// $result = ['success' => true, 'message' => 'Email sent']
```

### 异常处理

任务执行中的异常会被框架自动捕获，返回错误信息：

```php
// 任务类中可以抛出异常
class RiskCalculationTask implements TaskInterface
{
    public function handle(array $args)
    {
        if (!isset($args['portfolio'])) {
            throw new \InvalidArgumentException('Portfolio is required');
        }
        
        // 执行风险计算...
    }
}

// 调用方会收到错误信息
$result = $taskManager->sync(RiskCalculationTask::class, []);
// $result = ['success' => false, 'error' => 'Portfolio is required']
```

## 定时任务

### 添加定时任务

```php
$app = Framework::getInstance();
$crontab = $app->getCrontab();

// 每分钟执行
$crontab->addTask('clean_temp', '* * * * *', function () {
    // 清理临时文件
});

// 每小时执行
$crontab->addTask('hourly_stats', '0 * * * *', function () {
    // 统计每小时数据
});

// 每天凌晨 2 点执行
$crontab->addTask('daily_report', '0 2 * * *', function () {
    // 生成日报表
});

// 每周一凌晨执行
$crontab->addTask('weekly_report', '0 0 * * 1', function () {
    // 生成周报表
});

// 每月 1 号凌晨执行
$crontab->addTask('monthly_report', '0 0 1 * *', function () {
    // 生成月报表
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

### 常用表达式

| 表达式 | 说明 |
|--------|------|
| `* * * * *` | 每分钟执行 |
| `*/5 * * * *` | 每 5 分钟执行 |
| `*/15 * * * *` | 每 15 分钟执行 |
| `0 * * * *` | 每小时整点执行 |
| `0 */2 * * *` | 每 2 小时执行 |
| `0 0 * * *` | 每天零点执行 |
| `0 2 * * *` | 每天凌晨 2 点 |
| `0 0 1 * *` | 每月 1 号零点 |
| `0 0 * * 1` | 每周一零点 |
| `0 0 0 1 1` | 每年 1 月 1 日零点 |

## 自定义进程

框架支持两种自定义进程注册方式：**数组配置式**（简单场景）和 **AbstractProcess 实例式**（推荐，支持完整生命周期管理）。

### 方式一：通过数组配置注册（简单场景）

适用于简单的回调式进程，无需复杂的生命周期管理。

```php
// 在 AppHook 的 registerProcesses() 中
public function registerProcesses(): array
{
    return [
        [
            'name' => 'data_collector',
            'callback' => function (\Swoole\Process $worker) {
                while (true) {
                    // 数据采集逻辑
                    echo "Collecting data...\n";
                    sleep(5);
                }
            },
        ],
        [
            'name' => 'log_rotator',
            'callback' => function (\Swoole\Process $worker) {
                while (true) {
                    // 日志轮转逻辑
                    $this->rotateLogs();
                    sleep(3600); // 每小时执行
                }
            },
        ],
    ];
}
```

### 方式二：通过 AbstractProcess 实例注册（推荐）

继承 `AbstractProcess` 类，封装了完整的生命周期管理，包括优雅退出、管道通信、定时器、异常兜底。

#### 1. 创建进程类

```php
// app/Process/HeartbeatProcess.php
namespace App\Process;

use Sikelan\Process\AbstractProcess;

/**
 * 心跳进程
 *
 * 演示定时器和优雅退出
 */
class HeartbeatProcess extends AbstractProcess
{
    protected string $processName = 'heartbeat';

    // 进程主逻辑（必须实现）
    protected function run($arg): void
    {
        // 使用 addTick 添加定时器，进程退出时会自动清除
        $this->addTick(60000, function () {
            echo "Heartbeat: " . date('Y-m-d H:i:s') . "\n";
        });
    }

    // 进程退出时的清理逻辑（可选）
    protected function onShutDown(): void
    {
        echo "Heartbeat process shutting down gracefully\n";
    }
}
```

```php
// app/Process/DataSyncProcess.php
namespace App\Process;

use Sikelan\Process\AbstractProcess;
use Swoole\Process;

/**
 * 数据同步进程
 *
 * 演示定时器、管道通信和异常处理
 */
class DataSyncProcess extends AbstractProcess
{
    protected string $processName = 'data_sync';

    // 退出最大等待时间（秒），超时强制退出
    protected int $maxExitWaitTime = 5;

    protected function run($arg): void
    {
        // 每 5 秒执行一次数据同步
        $this->addTick(5000, function () {
            try {
                echo "Data sync running at " . date('Y-m-d H:i:s') . "\n";
            } catch (\Throwable $e) {
                $this->callOnException($e);
            }
        });
    }

    // 优雅退出清理
    protected function onShutDown(): void
    {
        echo "Data sync process shutting down, cleaning up...\n";
    }

    // 接收主进程通过管道发送的消息
    protected function onPipeReadable(Process $process): void
    {
        $msg = $process->read();
        echo "Data sync received: {$msg}\n";
    }

    // 异常统一处理
    protected function onException(\Throwable $throwable): void
    {
        fwrite(STDERR, "DataSync error: {$throwable->getMessage()}\n");
    }
}
```

#### 2. 注册进程实例

在 Hook 的 `registerProcesses()` 中直接 `new` 实例化并返回：

```php
// app/Hooks/AppHook.php
use App\Process\HeartbeatProcess;
use App\Process\DataSyncProcess;

public function registerProcesses(): array
{
    return [
        // 心跳进程：每 60 秒输出一次心跳
        new HeartbeatProcess(),

        // 数据同步进程：每 5 秒执行一次同步
        new DataSyncProcess(),

        // 也支持传入构造参数
        // new DataSyncProcess('custom_name', $arg, false, 2, true),
    ];
}
```

#### AbstractProcess 可重写的方法

| 方法 | 说明 | 是否必须实现 |
|------|------|--------------|
| `run($arg)` | 进程主逻辑 | 是 |
| `onShutDown()` | 退出时的清理逻辑 | 否，默认空实现 |
| `onPipeReadable(Process $process)` | 接收主进程管道消息 | 否，默认空实现 |
| `onException(\Throwable $throwable)` | 异常统一处理 | 否，默认输出到 stderr |

#### AbstractProcess 可配置的属性

| 属性 | 类型 | 默认值 | 说明 |
|------|------|--------|------|
| `$processName` | string | `''` | 进程名称，用于日志标识和系统进程名 |
| `$redirectStdinStdout` | bool | `false` | 是否重定向标准输入输出到管道 |
| `$pipeType` | int | `2` | 管道类型：`0`=无, `1`=只读, `2`=读写 |
| `$enableCoroutine` | bool | `true` | 是否在协程中执行 `run()` |
| `$maxExitWaitTime` | int | `3` | 优雅退出最大等待时间（秒），超时强制退出 |

#### AbstractProcess 内置方法

| 方法 | 说明 |
|------|------|
| `addTick(int $ms, callable $cb): int` | 添加定时器（毫秒级），退出时自动清除 |
| `addAfter(int $ms, callable $cb): int` | 添加一次性定时器（毫秒级，仅执行一次） |
| `clearTick(int $tickId): void` | 清除指定定时器 |
| `clearAllTicks(): void` | 清除所有定时器 |
| `writeToMain(string $data): int\|false` | 通过管道向主进程发送消息 |
| `getSwooleProcess(): Process` | 获取 Swoole Process 实例 |
| `getProcessName(): string` | 获取进程名称 |

#### 主进程向子进程发送消息

```php
// 在 Worker 进程中，通过 Server 组件向指定进程发送消息
$app = Framework::getInstance();
$server = $app->getServer();

// 发送消息到 data_sync 进程
$server->sendMessage('data_sync', 'sync_now');
```

### 两种方式对比

| 特性 | 数组配置式 | AbstractProcess 实例式 |
|------|------------|------------------------|
| 复杂度 | 低，适合简单场景 | 中，适合复杂业务 |
| 优雅退出 | 不支持 | 支持，可配置超时时间 |
| 定时器管理 | 需手动管理 | 自动管理，退出时自动清除 |
| 管道通信 | 需自行实现 | 内置支持 |
| 异常兜底 | 需自行处理 | 统一交给 `onException` |
| 协程支持 | 不支持 | 支持 |

### 注意事项

1. 进程会随 Swoole Server 一起启动和停止
2. 进程异常退出时，框架会记录日志
3. 数组配置式使用 `while (true)` 保持进程运行；AbstractProcess 实例式推荐使用 `addTick()` 替代 `while + sleep`
4. 合理使用 `sleep()` 或定时器间隔，避免 CPU 空转

## 服务器类型

### HTTP 服务器

```php
// bin/start.php
use Sikelan\Framework;

$app = Framework::getInstance();
$app->run('http');
```

### WebSocket 服务器

```php
// bin/websocket.php
use Sikelan\Framework;

$app = Framework::getInstance();

// 在 Hook 中注册 WebSocket 事件
// 或者直接在代码中注册
$server = $app->getServer();
$server->on('open', function ($server, $request) {
    echo "Client connected: {$request->fd}\n";
});
$server->on('message', function ($server, $frame) {
    $server->push($frame->fd, "Server received: {$frame->data}");
});
$server->on('close', function ($server, $fd) {
    echo "Client disconnected: {$fd}\n";
});

$app->run('websocket');
```

### TCP 服务器

```php
// bin/tcp.php
use Sikelan\Framework;

$app = Framework::getInstance();

$server = $app->getServer();
$server->on('connect', function ($server, $fd) {
    echo "Client connected: {$fd}\n";
});
$server->on('receive', function ($server, $fd, $fromId, $data) {
    $server->send($fd, "Server echo: {$data}");
});
$server->on('close', function ($server, $fd) {
    echo "Client disconnected: {$fd}\n";
});

$app->run('tcp');
```

### 客户端连接示例

**JavaScript (WebSocket)：**

```javascript
const ws = new WebSocket('ws://localhost:9501');

ws.onopen = function() {
    console.log('Connected');
    ws.send('Hello Server');
};

ws.onmessage = function(event) {
    console.log('Received:', event.data);
};

ws.onclose = function() {
    console.log('Disconnected');
};
```

**命令行 (TCP)：**

```bash
# 使用 telnet
telnet localhost 9501

# 或使用 nc
nc localhost 9501

# 发送测试数据
echo "Hello" | nc localhost 9501
```

---

# 第五部分：基础设施

## 缓存操作

### Redis 缓存

```php
$app = Framework::getInstance();
$cache = $app->getCache();

// 字符串操作
$cache->set('user:1', json_encode(['name' => 'John']), 3600); // 有效期 1 小时
$value = $cache->get('user:1');
$cache->del('user:1');
$exists = $cache->exists('user:1');
$cache->expire('user:1', 7200); // 延长有效期到 2 小时

// 哈希操作
$cache->hSet('user:1', 'name', 'John');
$cache->hSet('user:1', 'email', 'john@example.com');
$user = $cache->hGetAll('user:1'); // ['name' => 'John', 'email' => '...']
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
$cache->incr('counter', 5); // 6
$cache->decr('counter', 2); // 4
```

### 缓存最佳实践

```php
// 读写分离
public function getUser(int $id): ?array
{
    $cacheKey = "user:{$id}";
    
    // 1. 先查缓存
    $cached = $this->cache->get($cacheKey);
    if ($cached) {
        return json_decode($cached, true);
    }
    
    // 2. 查数据库
    $user = $this->db->select('SELECT * FROM users WHERE id = ?', [$id]);
    $user = $user[0] ?? null;
    
    // 3. 写入缓存
    if ($user) {
        $this->cache->set($cacheKey, json_encode($user), 3600);
    }
    
    return $user;
}

// 缓存更新时同步
public function updateUser(int $id, array $data): bool
{
    $result = $this->db->update('users', $data, ['id' => $id]);
    
    if ($result) {
        // 清除缓存，下次请求会重新从数据库加载
        $this->cache->del("user:{$id}");
    }
    
    return $result;
}
```

## 数据库操作

### MySQL 连接池

```php
$app = Framework::getInstance();
$db = $app->getDb();

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
]);

// 更新数据
$affected = $db->update('users', [
    'name' => 'Jane Doe',
], ['id' => $userId]);

// 删除数据
$affected = $db->delete('users', ['id' => $userId]);
```

### 事务处理

```php
try {
    // 开始事务
    $db->beginTransaction();
    
    // 执行多个操作
    $db->insert('orders', ['user_id' => 1, 'total' => 100]);
    $db->update('users', ['balance' => 'balance - 100'], ['id' => 1]);
    
    // 提交事务
    $db->commit();
    
} catch (\Throwable $e) {
    // 回滚事务
    $db->rollBack();
    throw $e;
}
```

### 原生查询

```php
// 执行原生 SQL
$result = $db->query('UPDATE users SET views = views + 1 WHERE id = ?', [1]);

// 批量插入
$rows = [
    ['name' => 'User1', 'email' => 'user1@test.com'],
    ['name' => 'User2', 'email' => 'user2@test.com'],
];
$db->insertBatch('users', $rows);
```

### 安全规范

```php
// ✅ 正确：使用参数化查询
$users = $db->select('SELECT * FROM users WHERE id = ?', [$id]);

// ❌ 错误：禁止字符串拼接 SQL
// $users = $db->select("SELECT * FROM users WHERE id = {$id}");
```

## 日志系统

### 使用日志

```php
use Sikelan\Core\Logger;

$app = Framework::getInstance();
$logger = $app->getLogger();

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
    'time' => date('Y-m-d H:i:s'),
]);
```

### 日志配置

```php
// config/app.php
return [
    'log_level' => env('APP_LOG_LEVEL', 'debug'), // debug, info, warning, error
    'log_path' => env('APP_LOG_PATH', LOG_PATH),
    'log_channel' => env('APP_LOG_CHANNEL', 'app'),
];
```

### 日志级别

| 级别 | 说明 | 用途 |
|------|------|------|
| `emergency` | 系统不可用 | 系统级错误 |
| `alert` | 需要立即处理 | 紧急情况 |
| `critical` | 严重错误 | 数据库连接失败等 |
| `error` | 普通错误 | 业务逻辑错误 |
| `warning` | 警告信息 | 非预期但可处理的情况 |
| `notice` | 重要信息 | 系统状态变化 |
| `info` | 常规信息 | 业务流程记录 |
| `debug` | 调试信息 | 开发调试用 |

日志文件按日期分割，例如 `app_2024-01-15.log`。

## 配置管理

### 配置格式

框架支持 PHP、YAML、JSON 三种配置格式。

**PHP 配置：**
```php
// config/database.php
return [
    'mysql' => [
        'host' => env('DB_HOST', '127.0.0.1'),
        'port' => env('DB_PORT', 3306),
        'username' => env('DB_USERNAME', 'root'),
        'password' => env('DB_PASSWORD', ''),
        'database' => env('DB_DATABASE', 'sikelan'),
    ],
];
```

**YAML 配置：**
```yaml
# config/app.yaml
name: Sikelan
env: development
debug: true
```

**JSON 配置：**
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
$app = Framework::getInstance();
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

### 多环境配置

框架支持多环境配置，可以为不同环境创建独立的配置文件。

#### 目录结构

```
config/
├── app.php                 # 基础配置
├── database.php            # 基础数据库配置
├── server.php              # 基础服务器配置
├── dev/                    # 开发环境配置
│   ├── app.php
│   └── database.php
├── prod/                   # 生产环境配置
│   ├── app.php
│   └── database.php
└── testing/                # 测试环境配置
    └── app.php
```

#### 加载顺序

1. 首先加载 `config/` 目录下的基础配置
2. 再加载 `config/{env}/` 目录下的环境配置
3. 环境配置会**递归合并覆盖**基础配置

#### 启动时指定环境

```bash
# 方式一：命令行参数
php bin/start.php http --env=dev
php bin/start.php http -e prod

# 方式二：.env 文件
# APP_ENV=dev
php bin/start.php http
```

#### 环境配置示例

**config/app.php** (基础配置)
```php
return ['name' => 'Sikelan', 'debug' => false];
```

**config/dev/app.php** (开发环境覆盖)
```php
return ['debug' => true, 'log_level' => 'debug'];
```

**config/prod/app.php** (生产环境覆盖)
```php
return ['debug' => false, 'log_level' => 'error'];
```

### 环境变量

框架通过 `.env` 文件管理环境变量。

**.env.example：**
```bash
APP_ENV=development
APP_NAME=Sikelan
APP_DEBUG=true
APP_LOG_LEVEL=debug
APP_HOOK=

DB_HOST=127.0.0.1
DB_PORT=3306
DB_USERNAME=root
DB_PASSWORD=
DB_DATABASE=sikelan

REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=
REDIS_DATABASE=0
```

**使用环境变量：**
```php
// 在配置文件中
return [
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => env('DB_PORT', 3306),
];

// 在代码中
$host = env('DB_HOST');
```

## 系统常量与公用函数

### 系统常量

框架启动时会自动定义以下常量：

| 常量名 | 说明 | 示例 |
|--------|------|------|
| `BASE_PATH` | 项目根目录 | `/path/to/whale` |
| `APP_PATH` | 应用代码目录 | `BASE_PATH/app` |
| `CONFIG_PATH` | 配置文件目录 | `BASE_PATH/config` |
| `RUNTIME_PATH` | 运行时文件目录 | `BASE_PATH/runtime` |
| `LOG_PATH` | 日志文件目录 | `BASE_PATH/logs` |
| `FRAMEWORK_PATH` | 框架核心目录 | `BASE_PATH/sikelan` |
| `VENDOR_PATH` | 第三方依赖目录 | `BASE_PATH/vendor` |

**使用示例：**

```php
// 在配置文件中使用
return [
    'log_path' => LOG_PATH,
    'pid_file' => RUNTIME_PATH . '/server.pid',
];

// 在代码中使用
$logFile = LOG_PATH . '/app.log';
```

### 公用函数

| 函数名 | 说明 | 示例 |
|--------|------|------|
| `env($key, $default)` | 获取环境变量 | `env('DB_HOST', '127.0.0.1')` |

```php
// 获取环境变量（自动解析类型）
$host = env('DB_HOST', '127.0.0.1');  // string
$port = env('DB_PORT', 3306);         // int
$debug = env('APP_DEBUG', false);     // bool
$nullValue = env('NOT_SET', null);    // null
```

---

# 第六部分：命令与运维

## 命令控制

框架提供了强大的命令行工具，可以通过命令来启动框架、管理配置、自动生成代码等。

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

### 查看命令列表

```bash
# 显示所有可用命令
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

# 强制覆盖已存在的文件
php bin/sikelan make:controller Product -f
```

**生成的控制器：**

```php
// app/Controllers/UserController.php
namespace App\Controllers;

use Sikelan\Http\Request;

class UserController
{
    public function index(Request $request, $params) { }
    public function show(Request $request, $params) { }
    public function store(Request $request, $params) { }
    public function update(Request $request, $params) { }
    public function destroy(Request $request, $params) { }
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

#### 生成任务类

```bash
# 创建异步任务类
php bin/sikelan make:task SendEmail

# 强制覆盖
php bin/sikelan make:task ProcessData -f
```

**生成的任务类：**

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

    public function handle(array $args)
    {
        $this->logger?->info("Task SendEmail started", $args);
    }
}
```

### 配置管理

```bash
# 显示所有配置
php bin/sikelan config show

# 显示指定配置项（支持点号分隔）
php bin/sikelan config show app
php bin/sikelan config show app.name

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

**方式一：通过代码注册**

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
```

#### 3. 执行自定义命令

```bash
php bin/sikelan hello          # 输出: Hello, World!
php bin/sikelan hello Sikelan  # 输出: Hello, Sikelan!
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
| `help` | 查看命令帮助 | `php sikelan help server` |
| `list` | 列出所有命令 | `php sikelan list` |

## 性能优化

### 服务器配置

```php
// config/server.php
return [
    'settings' => [
        'worker_num' => swoole_cpu_num() * 4,
        'max_request' => 50000,
        'task_worker_num' => swoole_cpu_num() * 2,
        'enable_coroutine' => true,
        'open_tcp_nodelay' => true,
        'reuse_port' => true,
        'buffer_output_size' => 2 * 1024 * 1024,
        'log_file' => LOG_PATH . '/swoole.log',
    ],
];
```

### 连接池配置

```php
// config/database.php
return [
    'mysql' => [
        'pool_size' => 50,
        'timeout' => 3,
    ],
    'redis' => [
        'pool_size' => 20,
        'timeout' => 2,
    ],
];
```

### 缓存策略

```php
// 多级缓存
public function getProduct(int $id): ?array
{
    $cacheKey = "product:{$id}";
    
    // L1: 内存缓存
    $cached = $this->cache->get($cacheKey);
    if ($cached) {
        return json_decode($cached, true);
    }
    
    // L2: 数据库查询
    $product = $this->db->select('SELECT * FROM products WHERE id = ?', [$id]);
    $product = $product[0] ?? null;
    
    // 写入缓存（带随机过期时间避免缓存雪崩）
    if ($product) {
        $ttl = 3600 + rand(0, 600);
        $this->cache->set($cacheKey, json_encode($product), $ttl);
    }
    
    return $product;
}
```

### 协程安全

- 使用 Swoole 协程安全的客户端
- 避免全局变量和静态变量
- 使用连接池管理资源
- 注意协程上下文隔离

## 测试与代码检查

### 运行测试

```bash
# 运行所有测试
composer test

# 运行 stest 目录下的单例测试
composer test:stest

# 运行指定测试
./vendor/bin/phpunit tests/stest/FrameworkTest.php

# 生成覆盖率报告
./vendor/bin/phpunit --coverage-html coverage/
```

### 代码检查

```bash
# 运行代码检查
composer lint

# 自动修复代码风格问题
composer lint:fix
```

### 代码规范

项目遵循 PSR-12 标准，并在 [global-style.md](file:///Users/wmc/data/trae/project/whale/global-style.md) 中定义了更详细的规范：

- 组件化设计、依赖注入、单一职责
- 类名 PascalCase、方法名 camelCase
- 缩进 4 空格、行宽 120 字符
- 强制使用类型声明
- SQL 注入防护、XSS 防护

---

# 第七部分：附录

## 目录结构

```
whale/
├── app/                    # 应用代码
│   ├── Commands/           # 自定义命令
│   ├── Controllers/        # 控制器
│   ├── Hooks/              # Hook 类（新增）
│   ├── Services/           # 服务层
│   └── Tasks/              # 任务类
├── bin/                    # 启动脚本
├── config/                 # 配置文件
│   ├── app.php             # 应用配置
│   ├── database.php        # 数据库配置
│   ├── cache.php           # 缓存配置
│   ├── server.php          # 服务器配置
│   ├── router.php          # 路由配置
│   ├── dev/                # 开发环境配置
│   └── prod/               # 生产环境配置
├── logs/                   # 日志目录
├── runtime/                # 运行时文件
├── sikelan/                # 框架核心
│   ├── Cache/              # 缓存组件
│   ├── Command/            # 命令控制
│   ├── Core/               # 核心组件
│   ├── Crontab/            # 定时任务
│   ├── Database/           # 数据库组件
│   ├── Hook/               # Hook 机制（新增）
│   ├── Http/               # HTTP 组件
│   ├── Process/            # 进程管理
│   ├── Server/             # 服务器管理
│   ├── Task/               # 任务系统
│   └── Framework.php       # 框架入口
├── tests/                  # 测试用例
├── .env                    # 环境变量
├── composer.json           # 依赖配置
└── phpunit.xml             # 测试配置
```

## 常见问题

### 如何关闭 Hook？

在 `config/app.php` 中将 `hook` 设置为空或删除该配置项即可。

```php
return [
    // 'hook' => \App\Hooks\AppHook::class,  // 注释掉或删除
];
```

### Hook 和默认事件如何共存？

`registerEvents()` 返回的事件会**完全覆盖**默认回调。如果需要在自定义逻辑后继续执行默认逻辑，需要在自定义回调中手动实现。

### 自定义进程什么时候启动和停止？

进程会随 Swoole Server 一起启动和停止。在 `Framework::run()` 调用后自动启动，在 Server 停止时自动终止。

### 如何调试 Hook？

在 Hook 中使用 `$this->logger` 记录日志：

```php
public function onInitialize(Server $server): void
{
    $this->logger->info('Hook initialized');
    // 检查配置
    $this->logger->debug('Config', $this->config->all());
}
```

查看日志文件 `logs/app_*.log` 即可。

### 框架是否支持 HTTPS？

是的。在 `config/server.php` 中配置 SSL 证书：

```php
return [
    'settings' => [
        'ssl_cert_file' => '/path/to/cert.pem',
        'ssl_key_file' => '/path/to/key.pem',
    ],
];
```

然后在代码中使用：

```php
$server = new \Swoole\Http\Server('0.0.0.0', 443, SWOOLE_PROCESS, SWOOLE_SOCK_TCP | SWOOLE_SSL);
```

### 如何使用 WebSocket + HTTP 混合服务器？

```php
// 启动 WebSocket 模式（同时支持 HTTP）
$app->run('websocket');

// HTTP 路由正常使用
$router->get('/api/status', function () {
    return ['status' => 'ok'];
});

// WebSocket 事件通过 Hook 或代码注册
$server->on('message', function ($server, $frame) {
    $server->push($frame->fd, "Hello");
});
```

---

## License

MIT License