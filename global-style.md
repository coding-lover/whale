# Sikelan Framework 项目规则

## 0. 框架代码（ sikelan 文件夹里的代码）发生变更时，要同时更新 README.md 文件

## 1. 架构设计约束

### 1.1 架构原则

- **组件化设计**: 框架采用组件化架构，每个组件职责单一，通过依赖注入容器进行解耦
- **依赖倒置**: 高层模块不应依赖低层模块，两者应依赖抽象接口
- **单一职责**: 每个类/方法只负责一个功能
- **开闭原则**: 对扩展开放，对修改关闭
- **接口隔离**: 使用细粒度接口，客户端不应依赖不需要的接口

### 1.2 组件分层

```
┌─────────────────────────────────────────────────────────────┐
│                      Application Layer                      │
│  Controllers / Services / Tasks / Domain Models             │
├─────────────────────────────────────────────────────────────┤
│                      Framework Layer                        │
│  Http / Database / Cache / Task / Crontab / Process        │
├─────────────────────────────────────────────────────────────┤
│                       Core Layer                            │
│  Container / Config / Logger                                │
├─────────────────────────────────────────────────────────────┤
│                      Infrastructure Layer                    │
│  Swoole / MySQL / Redis                                    │
└─────────────────────────────────────────────────────────────┘
```

### 1.3 依赖管理

- **禁止循环依赖**: 组件之间禁止循环依赖
- **最小依赖**: 每个组件只依赖必要的其他组件
- **依赖注入**: 所有依赖通过构造函数注入，禁止硬编码依赖

---

## 2. 命名规范

### 2.1 命名原则

- **语义化**: 命名应清晰表达其用途和含义
- **一致性**: 相同概念使用相同命名风格
- **简洁性**: 在保证语义清晰的前提下尽可能简洁

### 2.2 类命名

| 类型 | 规则 | 示例 |
|------|------|------|
| 类名 | PascalCase，名词或名词短语 | `UserController`, `RedisCache`, `MysqlPool` |
| 接口名 | PascalCase，以 `Interface` 结尾 | `TaskInterface`, `LoggerInterface` |
| 抽象类 | PascalCase，以 `Abstract` 开头 | `AbstractController` |
| trait | PascalCase，以 `Trait` 结尾 | `LoggerTrait` |

### 2.3 方法命名

| 类型 | 规则 | 示例 |
|------|------|------|
| 普通方法 | camelCase，动词或动词短语 | `getUser`, `saveData`, `handleRequest` |
| 构造方法 | `__construct` | `__construct()` |
| 魔术方法 | 双下划线开头和结尾 | `__get`, `__set`, `__toString` |
| 私有方法 | camelCase | `parseConfig`, `validateInput` |

### 2.4 变量命名

| 类型 | 规则 | 示例 |
|------|------|------|
| 普通变量 | camelCase | `userId`, `cacheKey`, `requestParams` |
| 类属性 | camelCase | `$config`, `$logger`, `connection` |
| 常量 | UPPER_CASE，下划线分隔 | `MAX_CONNECTION`, `DEFAULT_TIMEOUT` |
| 全局变量 | 禁止使用全局变量 | - |

### 2.5 文件命名

| 类型 | 规则 | 示例 |
|------|------|------|
| 类文件 | PascalCase，与类名一致 | `UserController.php`, `RedisCache.php` |
| 配置文件 | snake_case | `database.php`, `cache.php` |
| 测试文件 | 类名 + `Test` | `ConfigTest.php`, `RouterTest.php` |

### 2.6 命名空间

- 根命名空间: `Sikelan\`
- 组件命名空间: `Sikelan\Http\`, `Sikelan\Database\`, `Sikelan\Cache\`
- 应用命名空间: `App\Controllers\`, `App\Services\`, `App\Tasks\`

---

## 3. 代码风格

### 3.1 缩进与换行

- **缩进**: 使用 4 个空格（禁止使用 Tab）
- **行宽**: 每行不超过 120 个字符
- **空行**: 
  - 类定义前后各空一行
  - 方法定义前后各空一行
  - 逻辑块之间空一行
  - 文件末尾保留一个空行

### 3.2 括号风格

采用 **Allman 风格**（换行括号）：

```php
// 正确
if ($condition) {
    // code here
} else {
    // code here
}

// 正确
function getName()
{
    return $this->name;
}

// 错误（禁止 K&R 风格）
if ($condition) {
    // code here }
```

### 3.3 空格规范

```php
// 运算符两侧空格
$sum = $a + $b;
$isValid = $value > 0 && $value < 100;

// 逗号后空格
$array = [1, 2, 3];
function test($a, $b, $c) {}

// 函数调用空格
$result = calculate(1, 2);

// 控制结构空格
if ($condition) {}
while ($loop) {}
for ($i = 0; $i < 10; $i++) {}

// 花括号前空格
if ($x) {
}

// 禁止多余空格
// 错误: $a  =  1;
// 正确: $a = 1;
```

### 3.4 类型声明

- **函数参数**: 必须声明类型
- **返回值**: 必须声明返回类型（`void` 除外）
- **类属性**: 建议声明类型（PHP 7.4+）

```php
// 正确
public function getUser(int $id): ?User
{
    return $this->db->select('SELECT * FROM users WHERE id = ?', [$id])[0] ?? null;
}

// 正确（PHP 7.4+）
protected ?Logger $logger = null;
protected array $config = [];
```

### 3.5 控制结构

- **条件语句**: 即使只有一行也必须使用花括号
- **三元运算符**: 只用于简单判断，禁止嵌套
- **switch 语句**: 每个 case 必须有 break/return，default 分支必须存在

```php
// 正确
if ($condition) {
    doSomething();
}

// 错误（禁止省略花括号）
if ($condition) doSomething();

// 三元运算符
$value = $condition ? $a : $b;

// switch 语句
switch ($type) {
    case 'http':
        $server = new HttpServer();
        break;
    case 'websocket':
        $server = new WebSocketServer();
        break;
    default:
        throw new InvalidArgumentException('Unknown server type');
}
```

---

## 4. 文件组织规范

### 4.1 目录结构

```
project/
├── app/                    # 应用代码（业务逻辑）
│   ├── Controllers/        # 控制器（处理 HTTP 请求）
│   ├── Services/           # 服务层（业务逻辑）
│   ├── Tasks/              # 异步任务
│   ├── Models/             # 领域模型（可选）
│   └── Middleware/         # 中间件（可选）
├── bin/                    # 启动脚本
├── bootstrap/              # 启动引导
├── config/                 # 配置文件（PHP/YAML/JSON）
├── logs/                   # 日志文件
├── runtime/                # 运行时文件（缓存、临时文件）
├── sikelan/                # 框架核心代码
│   ├── Core/               # 核心组件（Container, Config, Logger）
│   ├── Http/               # HTTP 组件（Request, Response, Router）
│   ├── Database/           # 数据库组件
│   ├── Cache/              # 缓存组件
│   ├── Task/               # 任务系统
│   ├── Crontab/            # 定时任务
│   ├── Process/            # 进程管理
│   └── Server/             # 服务器管理
└── tests/                  # 测试用例
```

### 4.2 文件组织规则

- **单一职责**: 每个文件只包含一个类/接口/trait
- **命名一致性**: 文件名与类名完全一致（含大小写）
- **目录映射**: 命名空间与目录结构一致

---

## 5. 编码规范

### 5.1 字符编码

- 所有 PHP 文件使用 **UTF-8** 编码（无 BOM）
- 禁止在文件中使用其他编码格式

### 5.2 PHP 版本

- 最低支持版本: PHP 7.4
- 目标版本: PHP 8.0+
- 使用 PHP 7.4+ 特性（类型声明、箭头函数、空合并运算符等）

### 5.3 PSR 标准

| 标准 | 状态 | 说明 |
|------|------|------|
| PSR-1 | 必须 | 基础编码标准 |
| PSR-2 | 必须 | 编码风格指南 |
| PSR-3 | 必须 | 日志接口 |
| PSR-4 | 必须 | 自动加载规范 |
| PSR-7 | 推荐 | HTTP 消息接口 |
| PSR-12 | 必须 | 扩展编码风格 |

### 5.4 错误处理

- **禁止使用 `@` 错误抑制符**
- 使用 try-catch 处理异常
- 自定义异常类继承 `\Exception`
- 异常信息应包含足够的调试信息

```php
// 正确
try {
    $result = $this->db->query($sql);
} catch (\PDOException $e) {
    $this->logger->error("Database query failed", [
        'sql' => $sql,
        'error' => $e->getMessage()
    ]);
    throw new DatabaseException("Failed to execute query", 0, $e);
}
```

---

## 6. 注释规范

### 6.1 文档注释

- **类注释**: 描述类的用途、职责
- **方法注释**: 描述方法功能、参数、返回值、异常
- **属性注释**: 描述属性的用途

```php
/**
 * Redis 缓存组件
 * 
 * 基于 Swoole 协程 Redis 实现的缓存服务，支持常用缓存操作
 */
class RedisCache
{
    /**
     * Redis 客户端实例
     * 
     * @var \Swoole\Coroutine\Redis
     */
    protected $client;

    /**
     * 设置缓存值
     * 
     * @param string $key 缓存键
     * @param mixed $value 缓存值
     * @param int $expire 过期时间（秒），0 表示永不过期
     * @return bool 是否设置成功
     */
    public function set(string $key, $value, int $expire = 0): bool
    {
        // ...
    }
}
```

### 6.2 代码注释

- **必要时添加**: 复杂逻辑、业务规则、非直观代码
- **禁止冗余**: 不要注释显而易见的代码
- **保持更新**: 代码修改时同步更新注释

```php
// 错误（冗余注释）
// 设置用户 ID
$userId = 1;

// 正确（解释业务规则）
// 使用雪花算法生成唯一订单号（17位：41位时间戳 + 10位机器ID + 12位序列号）
$orderId = Snowflake::generate();
```

---

## 7. 安全规范

### 7.1 输入验证

- 所有外部输入必须进行验证和过滤
- 使用类型声明强制类型检查
- 对用户输入进行 sanitize 处理

### 7.2 SQL 注入防护

- **禁止字符串拼接 SQL**
- 使用参数化查询（Prepared Statement）
- 使用框架提供的数据库抽象层

```php
// 正确
$users = $db->select('SELECT * FROM users WHERE status = ?', [1]);

// 错误（禁止）
$users = $db->query("SELECT * FROM users WHERE status = {$status}");
```

### 7.3 XSS 防护

- 输出到 HTML 前进行转义
- 使用框架提供的视图模板引擎
- 对用户输入进行 HTML 实体编码

### 7.4 敏感信息保护

- 禁止在日志中记录密码、Token 等敏感信息
- 使用环境变量存储敏感配置（数据库密码、API Key 等）
- 定期轮换敏感凭证

### 7.5 依赖安全

- 使用 `composer audit` 定期检查依赖漏洞
- 及时更新有安全漏洞的依赖包
- 限制依赖版本范围，避免自动升级到不稳定版本

---

## 8. 性能规范

### 8.1 协程安全

- 避免使用全局变量和静态变量
- 使用协程安全的客户端（`Swoole\Coroutine\MySQL`, `Swoole\Coroutine\Redis`）
- 注意协程上下文隔离，避免数据竞争

### 8.2 资源管理

- 使用连接池复用数据库/缓存连接
- 及时释放不再使用的资源
- 设置合理的连接超时时间

### 8.3 代码优化

- 避免在循环中进行数据库查询
- 使用批量操作减少网络开销
- 合理使用缓存减少重复计算

---

## 9. 测试规范

### 9.1 测试覆盖

- 单元测试覆盖率目标: ≥ 80%
- 核心组件必须有单元测试
- 新增功能必须配套测试用例

### 9.2 测试命名

- 测试类名: `{ClassName}Test`
- 测试方法名: `test{MethodName}` 或 `test{Scenario}`

```php
class UserControllerTest extends TestCase
{
    public function testIndexReturnsUsers()
    {
        // 测试代码
    }

    public function testShowReturnsUserById()
    {
        // 测试代码
    }
}
```

### 9.3 测试原则

- **隔离性**: 测试用例之间相互独立
- **可重复性**: 相同输入应产生相同输出
- **可读性**: 测试代码应易于理解和维护

---

## 10. Git 规范

### 10.1 分支管理

- `main`: 主分支，稳定版本
- `develop`: 开发分支，集成新功能
- `feature/*`: 功能分支，开发新功能
- `bugfix/*`: 修复分支，修复 Bug

### 10.2 Commit 消息

遵循 **Conventional Commits** 规范：

```
<类型>(<范围>): <描述>

<正文>

<页脚>
```

| 类型 | 说明 |
|------|------|
| `feat` | 新增功能 |
| `fix` | 修复 Bug |
| `docs` | 文档更新 |
| `style` | 代码风格（不影响功能） |
| `refactor` | 重构（不新增功能也不修复 Bug） |
| `test` | 测试相关 |
| `chore` | 构建/工具类变更 |

示例：
```
feat(router): 添加路由分组功能

- 支持路由前缀配置
- 支持嵌套分组

fix(database): 修复连接池连接泄漏问题

- 在连接释放时正确归还连接到池
- 添加连接超时自动回收机制
```

---

## 附录：检查清单

### 代码审查检查项

- [ ] 命名是否符合规范
- [ ] 代码风格是否符合 PSR-12
- [ ] 是否有未使用的变量/导入
- [ ] 是否有 SQL 注入风险
- [ ] 是否有 XSS 风险
- [ ] 是否进行了适当的错误处理
- [ ] 是否有单元测试覆盖
- [ ] 注释是否清晰准确
- [ ] 是否有性能优化空间
- [ ] 是否符合架构设计约束