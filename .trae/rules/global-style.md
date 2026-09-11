---
alwaysApply: true
---
# Sikelan 铁律（AI 必须遵守）

## 1. 技术栈
- PHP ≥ 7.4（target 8.0+），允许类型声明、箭头函数、`??`、`?Type` 属性
- Swoole ≥ 4.6.3 协程扩展，协程客户端用 `Swoole\Coroutine\MySQL / Redis`
- 根命名空间：`Sikelan\` → `sikelan/`，`App\` → `app/`（PSR-4）

## 2. 代码风格（强制）
- Allman 换行括号（禁止 K&R 挂行）、4 空格缩进、每行 ≤ 120 字符
- PHP 7.4+ 类型声明全开：参数 + 返回值 + 属性都写类型
- 控制结构即使 1 行也必须 `{}`；`switch` 必须有 `default`
- 禁止 Tab、禁止嵌套三元、禁止 `var_dump/echo` 留业务代码
- 文件末尾 1 空行、UTF-8 无 BOM

## 3. 禁止清单（红线）
- 🔴 全局变量 `$GLOBALS` / 顶层 `global $x`
- 🔴 `@` 错误抑制符（用 try/catch 或显式判空）
- 🔴 SQL 字符串拼接（必须参数化：`WHERE id = ?` + `[$id]`）
- 🔴 **服务类直接 `new` 实例化**（必须走容器，见第 4 节）
- 🔴 硬编码依赖 `new Redis()` / `new MysqlPool()` 在业务类里（构造注入）
- 🔴 循环依赖（A↔B 互相构造注入）
- 🔴 PHP < 7.4 语法：`create_function()` / `each()` / `$str{0}`
- 🔴 **Controller / Command / Task / Process 直接操作 Model 或数据库**（数据访问只允许在 Service 层，见第 11 节）
- 🔴 **新建 Repository / DAO 层**（本系统不设该层，Service 直接调用 Model，见第 11 节）

## 4. 依赖注入
- **服务类只能通过容器实例化，禁止在业务代码中直接 `new ServiceClass()`**
- 允许的实例化方式（仅限以下四种）：
  1. **构造函数注入**（推荐）：`public function __construct(private OrderService $orderService) {}`
  2. **属性注入**：声明类型化成员变量，容器自动注入（`private ExchangeManager $exchangeManager;`）
  3. **方法注入**：控制器方法参数上类型提示（`public function store(Request $request, OrderService $svc)`）
  4. **手动从容器取**：`app(OrderService::class)` 或 `container()->get(OrderService::class)`
- 例外：DTO / ValueObject / 纯数据结构（无业务依赖）可直接 `new`，但必须无容器依赖
- 分层：`Application` → `Framework` → `Core` → `Infrastructure`（上层只能依赖下层/同层）

## 5. 命名规范
- 类 PascalCase、方法/变量 camelCase、常量 UPPER_SNAKE_CASE
- 接口 `Interface` 结尾、抽象类 `Abstract` 前缀、Trait `Trait` 后缀
- 配置文件 snake_case（`database.php`）

## 6. 协程安全
- 禁止 static / 全局变量存请求态
- 多协程共享对象的可变状态必须用原子操作或按 cid 隔离

## 7. 安全红线
- 参数化查询防 SQL 注入；日志绝不写密码/Token/密钥
- 敏感配置走 `env()` 从 .env 读，禁止硬编码
- 输入强校验（类型声明 + 范围/长度/格式）

## 8. 编码规范
- 错误处理用 try/catch，抛异常带上下文（SQL、参数、inner 异常），禁止吞异常不打日志
- 日志通道：交易所 → `exchange-service_{Y-m-d}.log`，回测 → `trader.log`
- SSL 验证可配置：环境变量 `BINANCE_SSL_VERIFY / OKX_SSL_VERIFY`
- PHPDoc 对外公开 API 必须写（类职责、方法 `@param @return @throws`），私有方法可省略

## 9. 测试
- 新增功能必须配测试，`composer test` 跑全绿
- 覆盖率目标 ≥ 80%，核心组件 100%
- 三原则：隔离、可重复、可读

## 10. 性能
- 禁止循环内 DB 查询 → 批量 IN
- 热点数据读 Redis 缓存，设置 TTL + 防击穿
- 连接池复用 DB/Redis，用完释放

## 11. 数据访问分层（强制）
- 应用层固定链路：`Controller / Command / Task / Process → Service → Model`，**严禁跨层**
- 本系统**不设 Repository / DAO 层**：禁止新建 `app/Repositories`、`app/Dao` 等目录或类；数据访问代码直接写在 Service 中
- **Service 是数据库操作的唯一出口**：
  - 所有 Eloquent 调用（`Model::find() / where() / create() / update() / delete()`、关联预加载、`DB::transaction()`、原生查询）只能出现在 `app/Services/` 下
  - Model 是无状态数据层，Service 中直接用其静态方法（`Backtest::query()`、`BacktestTrade::create()`），Model 不走容器注入
  - Service 本身必须走容器（第 4 节），由上层构造/方法注入使用
- **Controller / Command / Task / Process / Hook 的边界**：
  - 只做：HTTP 入参校验与提取、调用一个或多个 Service、组装 HTTP/CLI 响应
  - 禁止 `use App\Models\*`，禁止 `DB::` / `Capsule::` / `Model::xxx()` 等任何持久化代码
- **Model 层职责**（`app/Models`）：只声明表结构（`$table` / `$fillable` / `$casts`）、关联关系、查询作用域 scope、访问器/修改器；不写业务流程与事务编排
- Service 之间允许同层调用以复用逻辑，但禁止循环依赖（第 3 节红线）
- 表结构变更一律走 `database/migrations/*.sql`，禁止在 Model 或业务代码中做 schema 变更
- 正确示例：
  ```php
  // Controller：只注入并调用 Service
  public function index(Request $request, BacktestService $svc)
  {
      return $svc->paginate($request->getInt('page', 1));
  }

  // Service：数据访问唯一出口，直接调 Model（无 Repository）
  public function paginate(int $page): array
  {
      return Backtest::with('strategy')->orderByDesc('id')
          ->forPage($page, 20)->get()->toArray();
  }
  ```

---

## 详细参考（按需读取，不在 system prompt 常驻）

需要测试目录约定 → 读 `.trae/rules/test-rules.md`  
需要运行时目录约定 → 读 `.trae/rules/runtime-rules.md`  
需要框架层修改规范 → 读 `.trae/rules/sikelan-modify.md`
