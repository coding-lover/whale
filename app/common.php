<?php

/**
 * 应用层公共函数
 *
 * 封装常用的服务获取操作，避免到处写 container->get() 的冗长代码。
 * 使用 function_exists 防止重复定义。
 *
 * 使用示例：
 *   $symbol = exchange('binance')->formatSymbol('BTC/USDT:quarter');
 *   $value  = cache()->get('key');
 *   $row    = db()->query('SELECT * FROM users WHERE id = ?', [1]);
 *   logger()->info('Hello');
 *   config('app.name');
 */

use Sikelan\Core\Container;
use Sikelan\Core\Config;
use Sikelan\Core\Logger;
use Sikelan\Database\MysqlPool;
use Sikelan\Cache\RedisCache;
use Sikelan\Framework;
use App\Services\Exchanges\ExchangeManager;

if (!function_exists('app')) {
    /**
     * 获取 Framework 单例，或从容器解析指定服务
     *
     * - 不传参数：返回 Framework 单例（兼容旧用法）
     * - 传入类名/接口名/别名：从容器解析服务（等价于 container()->get($abstract)）
     *
     * 使用示例：
     *   app();                            // 获取 Framework 实例
     *   app(OrderService::class);         // 从容器解析 OrderService
     *   app('exchange_manager');          // 按别名解析服务
     *
     * @param string|null $abstract 服务标识（类名/接口名/别名）
     * @return Framework|mixed
     */
    function app(?string $abstract = null)
    {
        $framework = Framework::getInstance();

        if ($abstract === null) {
            return $framework;
        }

        return $framework->getContainer()->get($abstract);
    }
}

if (!function_exists('container')) {
    /**
     * 获取依赖注入容器
     *
     * @return Container
     */
    function container(): Container
    {
        return Framework::getInstance()->getContainer();
    }
}

if (!function_exists('config')) {
    /**
     * 获取配置实例或配置值
     *
     * 不传参数返回 Config 实例；传 key 返回配置值。
     *
     * @param string|null $key   配置键，支持点号分隔（如 app.name）
     * @param mixed       $default 默认值
     * @return Config|mixed
     */
    function config(?string $key = null, $default = null)
    {
        $config = Framework::getInstance()->getConfig();
        if ($key === null) {
            return $config;
        }
        return $config->get($key, $default);
    }
}

if (!function_exists('logger')) {
    /**
     * 获取日志实例
     *
     * @return Logger
     */
    function logger(): Logger
    {
        return Framework::getInstance()->getLogger();
    }
}

if (!function_exists('cache')) {
    /**
     * 获取 Redis 缓存实例
     *
     * @return RedisCache
     */
    function cache(): RedisCache
    {
        return Framework::getInstance()->getCache();
    }
}

if (!function_exists('db')) {
    /**
     * 获取数据库连接池
     *
     * @return MysqlPool
     */
    function db(): MysqlPool
    {
        return Framework::getInstance()->getDb();
    }
}

if (!function_exists('exchange_manager')) {
    /**
     * 获取交易所服务管理器
     *
     * @return ExchangeManager
     */
    function exchange_manager(): ExchangeManager
    {
        return Framework::getInstance()->getContainer()->get(ExchangeManager::class);
    }
}

if (!function_exists('exchange')) {
    /**
     * 获取指定交易所适配器实例
     *
     * 使用示例：
     *   $binance = exchange('binance');
     *   $symbol  = $binance->formatSymbol('BTC/USDT:quarter');
     *   $ticker  = $binance->getTicker('BTC/USDT');
     *
     * @param string $name 交易所名称 binance|okx
     * @return \App\Services\Exchanges\ExchangeInterface
     */
    function exchange(string $name)
    {
        return exchange_manager()->exchange($name);
    }
}

// ========================================================================
//  安全模块便利函数（Sikelan\Security 全局包装器）
// ========================================================================

if (!function_exists('e')) {
    /**
     * HTML 转义（防 XSS）
     *
     * 把任意值转为安全的 HTML 文本。等价于 htmlspecialchars(ENT_QUOTES|ENT_SUBSTITUTE)。
     *
     * 使用示例：
     *   echo e($userInput);                  // <script> → &lt;script&gt;
     *   echo "Hello, " . e($name) . "!";     // 字符串拼接前先转义
     *
     * @param mixed    $value  任意值（非字符串会被强转）
     * @param int|null $flags htmlspecialchars flags，默认 ENT_QUOTES|ENT_SUBSTITUTE
     * @return string 转义后的安全 HTML 文本
     */
    function e($value, ?int $flags = null): string
    {
        return \Sikelan\Security\HtmlEncoder::encode($value, $flags);
    }
}

if (!function_exists('bcrypt')) {
    /**
     * 生成 bcrypt 密码哈希
     *
     * 使用示例：
     *   $hash = bcrypt($plainPassword);
     *   // 存入数据库 users.password 字段
     *
     * @param string $plain 明文密码
     * @return string 60 字符的 bcrypt 哈希
     * @throws \RuntimeException 哈希失败
     */
    function bcrypt(string $plain): string
    {
        return \Sikelan\Security\Hasher::make($plain);
    }
}

if (!function_exists('bcrypt_verify')) {
    /**
     * 校验明文密码与哈希是否匹配
     *
     * 使用示例：
     *   if (bcrypt_verify($inputPassword, $user->password)) {
     *       // 登录成功
     *   }
     *
     * @param string $plain 明文密码
     * @param string $hash  bcrypt 哈希
     * @return bool 匹配返回 true
     */
    function bcrypt_verify(string $plain, string $hash): bool
    {
        return \Sikelan\Security\Hasher::verify($plain, $hash);
    }
}

if (!function_exists('encrypt')) {
    /**
     * AES-256-GCM 对称加密
     *
     * 使用 APP_KEY 作为密钥（通过 `php bin/sikelan security:key` 生成）。
     *
     * 使用示例：
     *   $cipher = encrypt('sensitive data');
     *   // 存入数据库或 cookie
     *
     * @param string $plain 明文
     * @return string base64(nonce + tag + cipher)
     * @throws \RuntimeException APP_KEY 未配置或格式错
     */
    function encrypt(string $plain): string
    {
        return \Sikelan\Security\Crypto::encrypt($plain);
    }
}

if (!function_exists('decrypt')) {
    /**
     * AES-256-GCM 对称解密
     *
     * 使用示例：
     *   $plain = decrypt($cipher) ?? '';  // 解密失败返回 null
     *
     * @param string $payload base64(nonce + tag + cipher)
     * @return string|null 解密成功返回明文；payload 非法或被篡改返回 null
     * @throws \RuntimeException APP_KEY 未配置或格式错
     */
    function decrypt(string $payload): ?string
    {
        return \Sikelan\Security\Crypto::decrypt($payload);
    }
}

if (!function_exists('validator')) {
    /**
     * 创建数据验证器
     *
     * 使用示例：
     *   $v = validator($_POST, [
     *       'email' => 'required|email|max:255',
     *       'name'  => 'required|string|min:2|max:50',
     *       'age'   => 'nullable|int|between:0,150',
     *   ]);
     *   if ($v->fails()) {
     *       return response()->withJson(['errors' => $v->errors()], 422);
     *   }
     *   $data = $v->validated();
     *
     * @param array  $data     待验证数据
     * @param array  $rules    规则 [字段 => 'rule1|rule2:arg' | ['rule1', 'rule2:arg']]
     * @param array  $messages 自定义错误消息 [字段.规则 => 消息]
     */
    function validator(array $data, array $rules, array $messages = []): \Sikelan\Security\Validator
    {
        return \Sikelan\Security\Validator::make($data, $rules, $messages);
    }
}
