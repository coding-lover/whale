# Sikelan 框架输入输出安全处理实施计划

## Context（背景与目标）

### 触发动机

Sikelan 是基于 Swoole 的 PHP 框架，目前 [sikelan/Http/Request.php](file:///Users/wmc/data/trae/project/whale/sikelan/Http/Request.php) 直接把 Swoole 的 GET/POST/Cookie 原值透传给控制器，没有类型转换、XSS 净化或参数校验。[Response.php](file:///Users/wmc/data/trae/project/whale/sikelan/Http/Response.php) 的 `withJson` 不带任何安全 JSON flag、`withHtml` 不转义；[IndexController::hello](file:///Users/wmc/data/trae/project/whale/app/Controllers/IndexController.php#L58-L65) 用 `"Hello, {$name}!"` 直接拼字符串回写客户端，存在反射型 XSS 风险。[MysqlPool::insert](file:///Users/wmc/data/trae/project/whale/sikelan/Database/MysqlPool.php#L92-L114) 虽然值已用 prepare 参数化，但 `$table` 和列名 `$key` 直接拼进 SQL 字符串，存在表名/列名注入面。

### 设计目标

参考 Laravel（`e()` / `Hash::make` / 流式 Validator / `Request::input`）和 Symfony（`ParameterBag::filter` / `HtmlSanitizer`）的优点，取长补短，给 Sikelan 框架补齐：

1. **输入侧**：统一 `input()` 入口 + 类型转换 + null byte/控制字符净化
2. **输出侧**：HTML 转义 `e()` + JSON 安全编码（`JSON_HEX_TAG` 等防 `<script>` 注入）+ 默认安全响应头
3. **SQL 侧**：`quoteIdentifier` 包反引号并校验白名单字符，禁止列名带 `;--` 等危险字符
4. **凭证**：`bcrypt` 密码哈希 + AES-256-GCM 对称加解密（基于 `APP_KEY`）
5. **验证**：流式 `Validator::make($data, $rules)` API，纯 PHP 实现不引入依赖
6. **CLI 工具**：`security:key` 命令一键生成 APP\_KEY 并写入 .env

### 范围决策

* ✅ 含输入净化、输出转义、SQL 转义、密码哈希、AES 加解密、流式 Validator、`security:key` 命令

* ✅ 默认安全优先（配置开关默认开启，开发者需要时才关闭）

* ✅ 顺手修改 `IndexController::hello` 作为安全使用示范

* ❌ 暂不做 CSRF + Session（业务侧多用 token 鉴权）

* ❌ 暂不做 Middleware 框架层（在 RequestHandler 内直接注入安全头）

* ❌ 不引入新 composer 依赖（全用 PHP 内置 `filter_var` / `htmlspecialchars` / `password_hash` / `openssl_encrypt`）

***

## 关键文件清单

### 新建文件

| 路径                                                      | 职责                                            |
| ------------------------------------------------------- | --------------------------------------------- |
| `sikelan/Security/InputSanitizer.php`                   | 值净化：去 null byte/控制字符、类型转换                     |
| `sikelan/Security/HtmlEncoder.php`                      | HTML 转义（`htmlspecialchars` 封装 + 递归）           |
| `sikelan/Security/Hasher.php`                           | bcrypt 密码哈希（make/verify/needsRehash）          |
| `sikelan/Security/Crypto.php`                           | AES-256-GCM 加解密 + APP\_KEY 解析                 |
| `sikelan/Security/Validator.php`                        | 流式验证器（静态 `make` + 链式规则）                       |
| `sikelan/Security/SecurityHeaders.php`                  | 默认安全响应头（合并配置）                                 |
| `sikelan/Command/DefaultCommand/SecurityKeyCommand.php` | `security:key` 生成 APP\_KEY 并写 .env（框架级默认命令）   |
| `config/security.php`                                   | 安全模块统一配置                                      |
| `tests/stest/InputSanitizerTest.php`                    | 净化 + 类型转换覆盖                                   |
| `tests/stest/HtmlEncoderTest.php`                       | HTML 转义                                       |
| `tests/stest/HasherTest.php`                            | make/verify/needsRehash                       |
| `tests/stest/CryptoTest.php`                            | encrypt/decrypt/key 校验                        |
| `tests/stest/ValidatorTest.php`                         | 内置规则 + 自定义消息 + 边界                             |
| `tests/stest/SecurityHeadersTest.php`                   | 默认头 + 配置覆盖                                    |
| `tests/stest/RequestSecurityTest.php`                   | input/getInt/has/only/except/all + 路由参数注入     |
| `tests/stest/ResponseSecurityTest.php`                  | withJson 安全 flag + withSecurityHeaders        |
| `tests/stest/MysqlPoolSecurityTest.php`                 | quoteIdentifier + insert/update/delete 拒绝非法表名 |

### 修改文件

| 路径                                                                                                                   | 改动概述                                                                                                                             |
| -------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------- |
| [sikelan/Http/Request.php](file:///Users/wmc/data/trae/project/whale/sikelan/Http/Request.php)                       | 新增 `input/getInt/getString/getBool/getFloat/getEmail/getUrl/getAlpha/getAlnum/has/only/except/all/setRouteParams/getRouteParams` |
| [sikelan/Http/Response.php](file:///Users/wmc/data/trae/project/whale/sikelan/Http/Response.php)                     | `withJson` 加 `JSON_HEX_*` 默认 flag + 可选 HTML 转义；`withHtml` 可选自动转义；新增 `withSecurityHeaders()`                                      |
| [sikelan/Http/RequestHandler.php](file:///Users/wmc/data/trae/project/whale/sikelan/Http/RequestHandler.php)         | `executeHandler` 注入路由参数到 Request；`sendResponse` 默认合并安全响应头                                                                        |
| [sikelan/Database/MysqlPool.php](file:///Users/wmc/data/trae/project/whale/sikelan/Database/MysqlPool.php)           | 新增 `quoteIdentifier()`；`insert/update/delete` 用它转义表名和列名                                                                          |
| [app/common.php](file:///Users/wmc/data/trae/project/whale/app/common.php)                                           | 新增 `e/bcrypt/bcrypt_verify/encrypt/decrypt/validator` 全局函数（带 `function_exists` 守卫）                                               |
| [app/Controllers/IndexController.php](file:///Users/wmc/data/trae/project/whale/app/Controllers/IndexController.php) | `hello()` 改用 `e()` 转义示范                                                                                                          |
| [sikelan/Command/CommandRunner.php](file:///Users/wmc/data/trae/project/whale/sikelan/Command/CommandRunner.php)     | `registerDefaultCommands()` 数组内追加 `new DefaultCommand\SecurityKeyCommand()`（框架级默认命令）                                             |

***

## 模块详细设计

### 1. InputSanitizer（输入净化器）

**职责**：对 GET/POST/Cookie/路由参数做净化（去 null byte、控制字符），并提供类型转换。

```php
class InputSanitizer
{
    // 净化：递归处理数组；字符串去 null byte + 控制字符（保留 \t \n \r）
    public static function clean($value)
    {
        if (is_array($value)) {
            return array_map([self::class, 'clean'], $value);
        }
        if (!is_string($value)) {
            return $value;
        }
        $value = str_replace("\0", '', $value);
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
    }

    // 类型转换：校验失败返回 null
    public static function cast($value, string $type)
    {
        switch ($type) {
            case 'int':    return is_numeric($value) ? (int) $value : null;
            case 'float':  return is_numeric($value) ? (float) $value : null;
            case 'bool':   return in_array(strtolower((string) $value), ['1','true','yes','on'], true);
            case 'string': return is_array($value) ? null : (string) $value;
            case 'email':
                $v = filter_var($value, FILTER_SANITIZE_EMAIL);
                return filter_var($v, FILTER_VALIDATE_EMAIL) !== false ? $v : null;
            case 'url':
                $v = filter_var($value, FILTER_SANITIZE_URL);
                return filter_var($v, FILTER_VALIDATE_URL) !== false ? $v : null;
            case 'alpha': return preg_replace('/[^a-zA-Z]/', '', (string) $value);
            case 'alnum': return preg_replace('/[^a-zA-Z0-9]/', '', (string) $value);
        }
        return $value;
    }
}
```

### 2. Request 增强

新增便利方法（保持 PSR-7 接口不变）：

```php
private ?array $routeParams = null;

public function setRouteParams(array $params): void { $this->routeParams = $params; }
public function getRouteParams(): array { return $this->routeParams ?? []; }

// 统一入口：合并 query + post + 路由参数，先净化，可选类型转换
public function input(string $key, $default = null, ?string $cast = null)
{
    $value = $this->queryParams[$key]
        ?? $this->postParams[$key]
        ?? $this->routeParams[$key]
        ?? $default;
    if ($value === $default && $default === null) return null;
    $value = InputSanitizer::clean($value);
    return $cast !== null ? InputSanitizer::cast($value, $cast) : $value;
}

public function getInt(string $key, int $default = 0): int  { /* input + cast int */ }
public function getString(string $key, string $default = ''): string { /* input + cast string */ }
public function getBool(string $key, bool $default = false): bool { /* input + cast bool */ }
public function getFloat(string $key, float $default = 0.0): float { /* input + cast float */ }
public function getEmail(string $key): ?string { /* input + cast email，失败返回 null */ }
public function getUrl(string $key): ?string { /* input + cast url */ }
public function getAlpha(string $key, string $default = ''): string { /* alpha */ }
public function getAlnum(string $key, string $default = ''): string { /* alnum */ }

public function has(string $key): bool
{
    return isset($this->queryParams[$key])
        || isset($this->postParams[$key])
        || isset($this->routeParams[$key]);
}

public function only(array $keys): array  { /* 只取指定键 */ }
public function except(array $keys): array { /* 全部 - 指定键 */ }
public function all(): array { /* 合并三源 → InputSanitizer::clean */ }
```

### 3. RequestHandler 改造

```php
protected function executeHandler(array $route, Request $request)
{
    $params = $route['params'] ?? [];
    
    // 把路由参数注入 Request，便于统一通过 input() 访问
    if (method_exists($request, 'setRouteParams')) {
        $request->setRouteParams($params);
    }
    
    // 后续闭包/Controller@method 调用保持不变，但 controller 收到的 $params 不变（兼容）
    // ...
}

protected function sendResponse(SwooleResponse $response, $data): void
{
    // 数组自动包成带安全响应头的 JSON Response
    if (is_array($data)) {
        $data = (new Response())->withJson($data)->withSecurityHeaders();
    }
    // Response 对象：若未显式设置安全头，自动合并默认头
    if ($data instanceof Response && !$data->hasHeader('X-Content-Type-Options')) {
        $data = $data->withSecurityHeaders();
    }
    // 后续 $data->send() 调用不变
}
```

### 4. HtmlEncoder（HTML 转义）

```php
class HtmlEncoder
{
    public static function encode($value, int $flags = null): string
    {
        $flags = $flags ?? (ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401);
        return htmlspecialchars((string) $value, $flags, 'UTF-8', true);
    }

    // 用于 withJson 选项：递归把字符串字段做 HTML 转义
    public static function encodeJsonStrings($data, int $flags = null)
    {
        if (is_string($data)) return self::encode($data, $flags);
        if (is_array($data)) return array_map([self::class, 'encodeJsonStrings'], $data);
        if (is_object($data)) return (object) self::encodeJsonStrings(get_object_vars($data));
        return $data;
    }
}
```

### 5. Response 增强

```php
// 默认安全 JSON flags（防止 <script> 标签注入到 JSON 字符串）
public const DEFAULT_JSON_FLAGS = JSON_UNESCAPED_UNICODE
    | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;

// 第二参数 $escapeHtmlStrings 默认 false：JSON 是数据格式，默认不转义字符串字段
// 但传 true 时可彻底防 XSS（适合输出给前端直接渲染的场景）
public function withJson($data, bool $escapeHtmlStrings = false)
{
    $new = clone $this;
    $new->headers['Content-Type'] = ['application/json; charset=utf-8'];
    if ($escapeHtmlStrings) {
        $data = HtmlEncoder::encodeJsonStrings($data);
    }
    $new->body = json_encode($data, self::DEFAULT_JSON_FLAGS);
    return $new;
}

public function withHtml($html, bool $autoEscape = false)
{
    $new = clone $this;
    $new->headers['Content-Type'] = ['text/html; charset=utf-8'];
    $new->body = $autoEscape ? HtmlEncoder::encode($html) : $html;
    return $new;
}

// 默认安全响应头（X-Content-Type-Options / X-Frame-Options / Referrer-Policy / X-XSS-Protection）
public function withSecurityHeaders(array $overrides = []): self
{
    $new = clone $this;
    $defaults = SecurityHeaders::defaults();
    $new->headers = array_merge($defaults, $overrides, $new->headers);
    return $new;
}
```

### 6. Hasher（bcrypt 密码哈希）

```php
class Hasher
{
    public static function make(string $plain, array $options = []): string
    {
        $cost = $options['cost'] ?? (int) config('security.bcrypt_cost', 10);
        return password_hash($plain, PASSWORD_BCRYPT, ['cost' => $cost]);
    }

    public static function verify(string $plain, string $hash): bool
    {
        return password_verify($plain, $hash);
    }

    public static function needsRehash(string $hash, array $options = []): bool
    {
        $cost = $options['cost'] ?? (int) config('security.bcrypt_cost', 10);
        return password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => $cost]);
    }
}
```

### 7. Crypto（AES-256-GCM 对称加解密）

**关键点**：用 `openssl_encrypt`/`openssl_decrypt` + `aes-256-gcm` 算法（PHP 7.1+ openssl 内置，不依赖 sodium 扩展）。GCM 标准的 nonce=12 字节、tag=16 字节，用固定常量避免扩展依赖。

```php
class Crypto
{
    private const NONCE_LEN = 12;   // GCM 标准 96-bit nonce
    private const TAG_LEN = 16;     // GCM 标准 128-bit tag

    public static function encrypt(string $plain, ?string $key = null): string
    {
        $key = $key ?: self::getKey();
        $nonce = random_bytes(self::NONCE_LEN);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($cipher === false) {
            throw new \RuntimeException('Encrypt failed: ' . openssl_error_string());
        }
        return base64_encode($nonce . $tag . $cipher);
    }

    public static function decrypt(string $payload, ?string $key = null): ?string
    {
        $key = $key ?: self::getKey();
        $decoded = base64_decode($payload, true);
        if ($decoded === false || strlen($decoded) < self::NONCE_LEN + self::TAG_LEN) {
            return null;
        }
        $nonce = substr($decoded, 0, self::NONCE_LEN);
        $tag = substr($decoded, self::NONCE_LEN, self::TAG_LEN);
        $cipher = substr($decoded, self::NONCE_LEN + self::TAG_LEN);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
        return $plain === false ? null : $plain;
    }

    public static function getKey(): string
    {
        $key = env('APP_KEY', '');
        if ($key === '') {
            throw new \RuntimeException(
                'APP_KEY not set. Run `php bin/sikelan security:key` to generate.'
            );
        }
        $decoded = base64_decode($key, true);
        if ($decoded === false || strlen($decoded) !== 32) {
            throw new \RuntimeException('APP_KEY format invalid. Expected base64 of 32 bytes.');
        }
        return $decoded;
    }

    public static function generateKey(): string
    {
        return base64_encode(random_bytes(32));
    }
}
```

### 8. Validator（流式验证器）

API 风格参考 Laravel：

```php
$v = Validator::make($_POST, [
    'email' => 'required|email|max:255',
    'name'  => 'required|string|min:2|max:50',
    'age'   => 'nullable|int|between:0,150',
    'role'  => 'required|in:admin,user,guest',
]);

if ($v->fails()) {
    return response()->withJson(['errors' => $v->errors()], 422);
}
$data = $v->validated();
```

**内置规则**：`required` `nullable` `int` `string` `email` `url` `regex:/pattern/` `min:n` `max:n` `between:min,max` `in:a,b,c` `not_in:a,b` `alpha` `alnum` `confirmed`（需要 `<field>_confirmation` 配对值）。

**错误消息**：默认中文，可通过 `Validator::make($data, $rules, ['email.required' => '邮箱必填'])` 自定义。

**实现要点**：

* 静态 `make` 工厂 + 实例 `passes()/fails()/errors()/validated()`

* 规则解析用 `explode('|', $ruleStr)`，参数用 `explode(':', $rule, 2)`

* `nullable` + `null` 值后续规则跳过；`required` 失败立即停止该字段其他规则

* 多错误聚合：每字段返回 `['msg1', 'msg2']` 数组

### 9. SecurityHeaders（默认安全响应头）

```php
class SecurityHeaders
{
    public static function defaults(): array
    {
        $config = function_exists('config') ? config('security.headers', []) : [];
        $defaults = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'no-referrer-when-downgrade',
            'X-XSS-Protection' => '1; mode=block',
        ];
        // 过滤掉值为 null/false 的项（让用户能通过配置关闭某项）
        $merged = array_merge($defaults, $config ?? []);
        return array_filter($merged, fn($v) => $v !== null && $v !== false);
    }
}
```

### 10. SecurityKeyCommand

参考 [TraderBacktestCommand](file:///Users/wmc/data/trae/project/whale/app/Commands/TraderBacktestCommand.php) 的 `CommandInterface` 实现：

```php
class SecurityKeyCommand implements CommandInterface
{
    public function commandName(): string { return 'security:key'; }
    public function desc(): string { return '生成 APP_KEY 并写入 .env（已存在则覆盖）'; }

    public function exec(array $args): ?string
    {
        $key = Crypto::generateKey();
        $envFile = BASE_PATH . '/.env';

        if (!file_exists($envFile)) {
            return "Error: .env file not found at {$envFile}";
        }

        $content = file_get_contents($envFile);
        $newLine = "APP_KEY={$key}";

        if (preg_match('/^APP_KEY=.*$/m', $content)) {
            $content = preg_replace('/^APP_KEY=.*$/m', $newLine, $content);
        } else {
            $content = rtrim($content) . "\n" . $newLine . "\n";
        }

        file_put_contents($envFile, $content);
        return "✓ APP_KEY generated and written to .env\n  Key: base64, 32 bytes\n  Length: " . strlen($key);
    }
}
```

### 11. MysqlPool 增强

新增 `quoteIdentifier`：

```php
public function quoteIdentifier(string $name): string
{
    // 只允许字母数字下划线（不允许 `;--` 等危险字符）
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
        throw new \InvalidArgumentException("Invalid SQL identifier: {$name}");
    }
    return '`' . str_replace('`', '``', $name) . '`';
}
```

修改 `insert`（同样改 `update`/`delete`）：

```php
public function insert($table, $data)
{
    $columns = array_keys($data);
    $placeholders = array_fill(0, count($columns), '?');
    $values = array_values($data);

    // 修复：用 quoteIdentifier 转义表名和列名
    $quotedColumns = array_map([$this, 'quoteIdentifier'], $columns);
    $sql = "INSERT INTO " . $this->quoteIdentifier($table)
        . " (" . implode(',', $quotedColumns) . ") VALUES (" . implode(',', $placeholders) . ")";

    // 后续 prepare + execute 逻辑不变
}
```

### 12. config/security.php

```php
<?php

return [
    // 安全响应头（在 RequestHandler 默认注入；设为 false 可关闭某项）
    'headers' => [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'SAMEORIGIN',
        'Referrer-Policy' => 'no-referrer-when-downgrade',
        'X-XSS-Protection' => '1; mode=block',
    ],

    // 是否启用 JSON 安全编码（JSON_HEX_TAG 等防止 <script> 注入到 JSON 字符串）
    'json_safety' => env('SECURITY_JSON_SAFETY', true),

    // 是否自动净化输入（null byte / 控制字符）
    'input_sanitizer' => env('SECURITY_INPUT_SANITIZER', true),

    // bcrypt cost (4-31，建议 10-12，过高会拖慢请求)
    'bcrypt_cost' => env('BCRYPT_COST', 10),

    // 默认加密算法
    'cipher' => 'aes-256-gcm',
];
```

### 13. 全局便利函数（追加到 app/common.php）

全部带 `function_exists` 守卫，符合现有 [app/common.php](file:///Users/wmc/data/trae/project/whale/app/common.php) 风格：

```php
if (!function_exists('e')) {
    function e($value, int $flags = ENT_QUOTES | ENT_SUBSTITUTE): string {
        return \Sikelan\Security\HtmlEncoder::encode($value, $flags);
    }
}

if (!function_exists('bcrypt')) {
    function bcrypt(string $plain): string {
        return \Sikelan\Security\Hasher::make($plain);
    }
}

if (!function_exists('bcrypt_verify')) {
    function bcrypt_verify(string $plain, string $hash): bool {
        return \Sikelan\Security\Hasher::verify($plain, $hash);
    }
}

if (!function_exists('encrypt')) {
    function encrypt(string $plain): string {
        return \Sikelan\Security\Crypto::encrypt($plain);
    }
}

if (!function_exists('decrypt')) {
    function decrypt(string $payload): ?string {
        return \Sikelan\Security\Crypto::decrypt($payload);
    }
}

if (!function_exists('validator')) {
    function validator(array $data, array $rules, array $messages = []): \Sikelan\Security\Validator {
        return \Sikelan\Security\Validator::make($data, $rules, $messages);
    }
}
```

### 14. IndexController::hello 示范

```php
public function hello(Request $request, $params)
{
    $name = e($params['name'] ?? 'Guest');  // ← HTML 转义防 XSS
    return [
        'message' => "Hello, {$name}!",
        'time' => date('Y-m-d H:i:s'),
    ];
}
```

### 15. 命令注册

`SecurityKeyCommand` 是框架级默认命令（生成 APP\_KEY 给框架本身用），放在 `sikelan/Command/DefaultCommand/`，在 [CommandRunner::registerDefaultCommands()](file:///Users/wmc/data/trae/project/whale/sikelan/Command/CommandRunner.php#L26-L41) 的 `$defaultCommands` 数组末尾追加一项：

```php
private function registerDefaultCommands(): void
{
    $defaultCommands = [
        new DefaultCommand\ServerCommand(),
        new DefaultCommand\HelpCommand(),
        new DefaultCommand\MakeControllerCommand(),
        new DefaultCommand\MakeModelCommand(),
        new DefaultCommand\MakeTaskCommand(),
        new DefaultCommand\ConfigCommand(),
        new DefaultCommand\RouteCommand(),
        new DefaultCommand\SecurityKeyCommand(),  // ← 新增
    ];
    // ... 后续不变
}
```

说明：`CommandRunner` 已有自动扫描 `app/Commands/*.php` 注册应用层命令的机制，但 `SecurityKeyCommand` 属于框架基础工具（生成 APP\_KEY 给框架本身使用），所以归入 `DefaultCommand` 而非 `App\Commands`，不依赖应用层是否创建了 `app/Commands` 目录。

***

## 验证步骤

### 单元测试

```bash
# 跑新增的安全模块测试（9 个测试文件）
./vendor/bin/phpunit tests/stest/ --testdox --filter 'InputSanitizerTest|HtmlEncoderTest|HasherTest|CryptoTest|ValidatorTest|SecurityHeadersTest|RequestSecurityTest|ResponseSecurityTest|MysqlPoolSecurityTest'

# 跑全量回归（确认未破坏现有功能，应保持现有 518+ 用例全绿）
composer test
```

### 端到端验证

```bash
# 1. 生成 APP_KEY 并验证 .env 写入
php bin/sikelan security:key
grep "^APP_KEY=" .env  # 应能看到 base64 字符串

# 2. 用 PHP 一行脚本验证加解密对称性
php -r "require 'vendor/autoload.php'; require 'sikelan/Core/Bootstrap.php'; Sikelan\Core\Bootstrap::core(__DIR__); \$c = \Sikelan\Security\Crypto::encrypt('hello'); echo \$c, PHP_EOL; echo \Sikelan\Security\Crypto::decrypt(\$c), PHP_EOL;"

# 3. 验证密码哈希
php -r "require 'vendor/autoload.php'; require 'sikelan/Core/Bootstrap.php'; Sikelan\Core\Bootstrap::core(__DIR__); \$h = bcrypt('mypwd'); var_dump(bcrypt_verify('mypwd', \$h), bcrypt_verify('wrong', \$h));"

# 4. 启动 HTTP 服务后用 curl 验证默认安全响应头
php bin/sikelan server start -e=dev &
sleep 2
curl -i http://127.0.0.1:9502/api/users/1
# 应看到：X-Content-Type-Options: nosniff、X-Frame-Options: SAMEORIGIN 等
# 应看到：响应 body 中的 < > 被 \u003C \u003E 转义（JSON_HEX_TAG 效果）

# 5. 验证 XSS 反射场景已修复
curl 'http://127.0.0.1:9502/api/indexes/hello?name=<script>alert(1)</script>'
# 应看到响应里 <script> 被转义为 &lt;script&gt;（e() 效果）

# 6. 验证 SQL 表名注入被拒绝
php -r "require 'vendor/autoload.php'; \$p = new \Sikelan\Database\MysqlPool(new \Sikelan\Core\Config('')); try { \$p->quoteIdentifier('users;--'); echo 'FAIL'; } catch (\InvalidArgumentException \$e) { echo 'OK: ' . \$e->getMessage(); }"
```

### 验证清单

* [ ] 新增 9 个测试文件全部通过

* [ ] 现有 518+ 用例无回归

* [ ] `security:key` 命令成功写入 .env

* [ ] AES 加解密对称性通过

* [ ] bcrypt 哈希/校验通过

* [ ] HTTP 响应默认带 4 个安全响应头

* [ ] JSON 响应中 `<script>` 被转义为 `\u003Cscript\u003E`

* [ ] `IndexController::hello` 反射型 XSS 已修复

* [ ] `MysqlPool::quoteIdentifier` 拒绝 `users;--` 等非法表名

***

## 兼容性保证

1. **PSR-7 接口不变**：`Request`/`Response` 仅新增方法，保留全部 `with*` 不变签名。
2. **控制器签名不变**：`Controller@method(Request $request, $params)` 调用约定保持。
3. **`withJson($data)`** **单参数调用兼容**：新增第二参数 `$escapeHtmlStrings = false`，默认 false 时与旧行为完全一致（除 JSON flag 增加，但 JSON flag 不破坏 JSON 解析）。
4. **`MysqlPool::insert/update/delete`** **签名不变**：调用方代码零改动；行为差异仅在表名/列名非白名单字符时抛 `InvalidArgumentException`，正常业务表名不受影响。
5. **配置可选**：未配置 `config/security.php` 时，所有默认值在 `SecurityHeaders` / `Response` 内部硬编码兜底。
6. **Bootstrap 不变**：所有新模块走 PSR-4 自动加载，无需修改 [Bootstrap.php](file:///Users/wmc/data/trae/project/whale/sikelan/Core/Bootstrap.php)。

***

## 重点关注

1. *JSON\_HEX\_* 不破坏现有 JSON 响应\*：`JSON_HEX_TAG` 把 `<` 编码为 `\u003C`，前端 `JSON.parse` 后仍是 `<`，不影响 JS 业务逻辑；仅防止 `<script>` 字符串被浏览器误解析为标签（防 XSS）。
2. **AES-256-GCM 不依赖 sodium 扩展**：用 `openssl_encrypt`/`openssl_decrypt` + `aes-256-gcm` 算法（PHP 7.1+ openssl 内置），nonce/tag 长度用固定常量 `12/16`。
3. **协程安全**：`Hasher`/`Crypto`/`InputSanitizer`/`HtmlEncoder`/`Validator` 全部纯静态方法、无静态可变状态，多协程并发安全。
4. **错误处理遵循** **[global-style.md](file:///Users/wmc/data/trae/project/whale/.trae/rules/global-style.md)** **§8**：加解密失败抛 `RuntimeException` 带上下文；非破坏性失败（decrypt 输入非法）返回 `null` 不抛异常，避免吞异常。
5. **配置走** **`env()`** **不硬编码**：`APP_KEY`、`BCRYPT_COST`、`SECURITY_JSON_SAFETY` 全部走环境变量。

