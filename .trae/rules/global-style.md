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
- 🔴 硬编码依赖 `new Redis()` 在业务类里（构造注入）
- 🔴 循环依赖（A↔B 互相构造注入）
- 🔴 PHP < 7.4 语法：`create_function()` / `each()` / `$str{0}`

## 4. 依赖注入
- 所有依赖走 `__construct(IFoo $foo)`，禁止业务类里 `new X()`
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

---

## 详细参考（按需读取，不在 system prompt 常驻）

需要测试目录约定 → 读 `.trae/rules/test-rules.md`  
需要运行时目录约定 → 读 `.trae/rules/runtime-rules.md`  
需要框架层修改规范 → 读 `.trae/rules/sikelan-modify.md`
