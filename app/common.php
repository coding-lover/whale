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
     * 获取 Framework 单例
     *
     * @return Framework
     */
    function app(): Framework
    {
        return Framework::getInstance();
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
