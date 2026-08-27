---
alwaysApply: true
---
# Sikelan 项目规则（AI 生成代码时强制执行）
---
## 1. Framework Versions & Dependencies（框架版本 & 依赖）

| 项目 | 版本/约束 | 说明 |
|------|----------|------|
| **PHP** | ≥ 7.4（target 8.0+） | 允许使用类型声明、箭头函数 `fn() =>`、`??`、`?Type` 属性 |
| **Swoole 扩展** | ≥ 4.6.3 | 协程客户端用 `Swoole\Coroutine\MySQL / Redis`，禁止非协程版 |
| **Symfony Console** | ^5.4 | CLI 命令基类 |
| **Symfony EventDispatcher** | ^5.4 | 事件总线 |
| **Symfony YAML** | ^5.4 | 配置解析 |
| **nikic/fast-route** | ^1.3 | HTTP 路由 |
| **psr/container** | ^1.1 | DI 容器契约 |
| **psr/log** | ^1.1 | 日志契约 |
| **psr/http-message** | ^1.0 | HTTP 消息契约 |
| **Composer 脚本** | `composer test` | 全量 PHPUnit（勿手动造轮子） |
| **根命名空间** | `Sikelan\` → `sikelan/`；`App\` → `app/` | PSR-4 自动加载 |
| **测试命名空间** | `Sikelan\Tests\` → `tests/` | 子目录名映射：`tests/xxx_test/` → `Sikelan\Tests\xxx_test` |

**PSR 必选（MUST）**：PSR-1 / PSR-2 / PSR-3 / PSR-4 / PSR-12  
**PSR 推荐（SHOULD）**：PSR-7（HTTP 消息）

---

## 2. Testing Framework Details（测试框架）

| 项目 | 约定 |
|------|------|
| **测试框架** | PHPUnit ^9.5，入口 `composer test` |
| **Bootstrap** | `tests/bootstrap.php`（已加载 autoload + 常量 + `env()` 兜底） |
| **配置** | `phpunit.xml`，扫描目录 `<directory>tests/</directory>` |
| **目录约定（按被测代码归属严格划分）** | `tests/stest/` → **框架核心 `sikelan/`**（Container/Config/Logger/Http/DB/Cache/Task/Crontab 等框架层）；`tests/atest/` → **应用层 `app/`**（Controllers/Services/Tasks/Models 等业务逻辑 + 需要真实网络/进程/交易所适配器的集成测试，缺环境会 skip）；`tests/trader_test/` → **Services 下 `app/Services/Trader/`（含 Trader 相关 `App\Services\Exchanges\`）** 交易/回测/策略注册表/TradingSymbol 等 Trader 专用模块 |
| **文件命名** | `{被测类名}Test.php`，如 `StrategyRegistrationTest.php` |
| **方法命名** | `test{场景}`（camelCase，多个单词描述场景） |
| **覆盖率目标** | ≥ 80%（核心组件 100%）；**新增功能必须配测试** |
| **三原则** | 隔离（用例独立）· 可重复（同输入同输出）· 可读（描述性命名） |

**单独跑指定子目录**：
```bash
composer test:stest     # 仅系统单元
./vendor/bin/phpunit tests/trader_test/ --testdox
```

---

## 3. Prohibited APIs & Anti-Patterns（禁止清单）

**🔴 绝对禁止（FAIL CI）**
- 全局变量：`$GLOBALS` / 顶层 `global $x`
- `@` 错误抑制符：`@mysql_query(...)` → 用 try/catch 或显式判空
- SQL 字符串拼接：`"WHERE id={$id}"` → **必须参数化** `WHERE id = ?` + `[$id]`
- 循环依赖：组件 A↔B 互相构造注入
- `Tab` 缩进：只用 4 空格
- 硬编码依赖：`new Redis()` 直接在业务类里 → 构造函数注入
- 控制结构省花括号：`if ($x) foo();` → 必须 `if ($x) { foo(); }`
- PHP < 7.4 语法：`create_function()` / `each()` / `string{0}` 下标

**🟡 强不推荐（WARN）**
- 嵌套三元 `$a ? $b : $c ? $d : $e` → 拆 if
- 循环里 DB 查询 N+1 → 批量 IN
- `var_dump`/`echo` 留在业务代码（调试完必须删）
- 日志里写密码、Token、密钥明文
- K&R 花括号（和项目 Allman 风格冲突）

---

## 4. Architecture（架构原则）

**五原则（SOLID 简化版）**：
1. **组件化 & 单一职责**：一个类/方法只做一件事
2. **依赖倒置**：高层依赖抽象接口，不依赖低层实现细节
3. **开闭**：对扩展开放（新功能加 Strategy/Adapter），对修改关闭（少改核心）
4. **接口隔离**：接口细分，客户端不应被迫实现不用的方法
5. **最小依赖 + 构造注入**：禁止硬编码 `new X()`，所有依赖 `__construct(IFoo $foo)`

**四层分层（上层只能依赖下层/同层）**：
- `Application Layer`：`app/Controllers`, `app/Services`, `app/Tasks`, `app/Models`
- `Framework Layer`：`sikelan/Http`, `Database`, `Cache`, `Task`, `Crontab`, `Process`
- `Core Layer`：`sikelan/Core` → `Container`, `Config`, `Logger`
- `Infrastructure Layer`：Swoole / MySQL / Redis（底层实现）

**目录结构（必须遵循）**：
```
project/
├── app/Controllers · Services · Tasks · Models · Middleware   # 业务
├── bin/ bootstrap/ config/ logs/ runtime/                     # 启动/配置
├── sikelan/Core · Http · Database · Cache · Task · Crontab · Process · Server  # 框架核心
└── tests/stest · atest · trader_test                          # 测试（按归属：stest=sikelan框架 / atest=app应用 / trader_test=App\Services\Trader+Exchanges）
```
---
## 5. Naming（命名规范）
**统一原则**：语义化 · 一致性 · 简洁

| 对象 | 规则 + 正反例 |
|------|---------------|
| 类名 | PascalCase · 名词 ✔`UserController` ✘`user_controller` ✘`UserCtrl` |
| 接口 | PascalCase + `Interface` 结尾 ✔`TaskInterface` ✘`ITask` |
| 抽象类 | `Abstract` 前缀 ✔`AbstractController` |
| Trait | `Trait` 后缀 ✔`LoggerTrait` |
| 方法 | camelCase · 动词短语 ✔`getUser()` ✘`GetUser()` ✘`get_user()` |
| 私有方法 | 同上 camelCase ✔`parseConfig()` |
| 魔术方法 | 双下划线 `__construct / __get / __toString` |
| 变量/属性 | camelCase ✔`$userId` ✘`user_id`（除配置键 snake） |
| 常量/Enum | UPPER_SNAKE_CASE ✔`MAX_CONNECTION` |
| 配置文件 | snake_case ✔`database.php` ✘`Database.php` |
| 测试文件 | 被测类 + `Test` ✔`BacktestServiceProviderTest.php` |
| **全局变量** | 🔴 禁止 |

**命名空间（严格对齐目录）**：
- 框架：`Sikelan\Http\Request` ↔ `sikelan/Http/Request.php`
- 应用：`App\Controllers\UserController` ↔ `app/Controllers/UserController.php`
- 测试：`Sikelan\Tests\trader_test\StrategyRegistrationTest` ↔ `tests/trader_test/StrategyRegistrationTest.php`
---

## 6. Code Style（代码风格）

### 6.1 排版
- **缩进 4 空格**，禁止 Tab
- **每行 ≤ 120 字符**，超了就折行对齐
- **空行**：类前后 1 行；方法前后 1 行；逻辑块间 1 行；**文件末尾 1 空行**

### 6.2 括号：**Allman 风格**（换行括号，和 Java/Go 不同，强制）
```php
// ✔ 正确（换行）
if ($condition)
{
    doA();
}
else
{
    doB();
}
function getName(): string
{
    return $this->name;
}
// ✘ 错误（K&R 挂行）
if ($x) {
    foo();
}
```

### 6.3 空格（运算符/逗号/控制结构两侧 1 空格，禁止 `a  =  1`）
```php
// ✔
$sum = $a + $b;
if ($x > 0 && $x < 100) { }
$arr = [1, 2, 3];
foo(1, 2);
```

### 6.4 类型声明（PHP 7.4+ 必须全开）
```php
// ✔ 参数 + 返回值（除 void）都写类型；属性建议写
public function findUser(int $id): ?User
{
    return $this->users[$id] ?? null;
}
protected ?Logger $logger = null;
protected array $config = [];
```

### 6.5 控制结构
- 即使 1 行也必须 `{}`：`if ($x) { return; }`
- 三元只用于简单赋值，禁止嵌套
- `switch` 每个 `case` 必须 `break/return`，**必须有 `default` 分支**

---

## 7. Coding（编码规范）

- **UTF-8 无 BOM**，禁止其他编码
- 错误处理：用 try/catch；抛异常带上下文（SQL、参数、inner 异常），禁止吞异常不打日志
- 日志通道：交易所 → `exchange-service_{Y-m-d}.log`；回测 → `trader.log`；用 `Logger.withChannel()` 切通道
- SSL 验证可配置：环境变量 `BINANCE_SSL_VERIFY / OKX_SSL_VERIFY`，测试环境允许关闭

---

## 8. Comments（注释）

**原则**：必要才加，禁止冗余。代码自解释优先。
**文档注释（PHPDoc）**：类职责、方法 `@param @return @throws`、属性 `@var`——**对外公开 API 必须写**，内部私有方法可省略。

---

## 9. Security（安全规范）

1. **输入验证**：所有外部输入校验（类型、范围、长度、格式）；类型声明强约束
2. **SQL 注入**：参数化查询 / 框架 DB 抽象层；🔴 禁止字符串拼接
3. **XSS**：输出 HTML 前转义 `htmlspecialchars(ENT_QUOTES)`；用视图引擎
4. **敏感信息**：日志里 **绝不** 写密码、JWT、API Key、私钥；配置用 `env()` 从 .env 读，禁止硬编码
5. **依赖安全**：定期 `composer audit`；限依赖版本范围，禁止 `dev-main` 直接锁死

---

## 10. Performance（性能 & 协程）

- **协程安全**：禁止全局变量/静态变量存请求态；用 `Swoole\Coroutine\MySQL / Redis` 客户端
- **资源管理**：连接池复用 DB/Redis；用完释放；设置合理 connect / read 超时
- **DB 优化**：禁止循环内查询 → 批量 IN；一次取够需要字段（SELECT * 慎用）
- **缓存**：热点数据读 Redis，减少重复计算；设置合理 TTL + 防击穿

---

## 11. Git 规范

### 11.1 分支
- `main` 稳定、`develop` 集成、`feature/xxx` 新功能、`bugfix/xxx` 修 bug

### 11.2 Commit Message：**Conventional Commits**（强制前缀）
```
<类型>(<范围>): <描述>
```
| 类型 | 含义 |
|------|------|
| `feat` | 新功能 |
| `fix` | 修复 bug |
| `docs` | 文档 |
| `style` | 代码风格（格式化，不影响逻辑） |
| `refactor` | 重构（不增功能不修 bug） |
| `test` | 新增/修复测试 |
| `chore` | 构建/脚本/工具 |

示例：
```
feat(trader): 策略别名注册表 + createStrategyByName
- 支持字符串 & class+construct 两种注册形式
- 未注册时异常打印当前已注册别名列表
---

## 代码审查 Checklist（PR 自检）
- [ ] 命名是否符合规范
- [ ] 代码风格是否符合 PSR-12
- [ ] 是否有未使用的变量/导入
- [ ] 是否有 SQL 注入风险
- [ ] 是否有 XSS 风险
- [ ] 是否进行了适当的错误处理
- [ ] 新增功能配了对应测试；命名 `test{场景}`
- [ ] 是否有单元测试覆盖
- [ ] 注释是否清晰准确
- [ ] 是否有性能优化空间
- [ ] 符合四层分层 & 依赖方向（高层不依赖低层实现）
- [ ] 是否符合架构设计约束