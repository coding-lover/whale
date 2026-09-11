<?php

namespace Sikelan\Cache;

use Sikelan\Core\Config;
use Swoole\Coroutine\Redis;

class RedisCache
{
    protected $redis;
    protected $config;

    public function __construct(Config $config)
    {
        $this->config = $config->get('cache.redis', []);
        $this->redis = new Redis();
    }

    protected function connect()
    {
        if (!$this->redis->connected) {
            $host = $this->config['host'] ?? '127.0.0.1';
            $port = $this->config['port'] ?? 6379;
            $auth = $this->config['password'] ?? '';
            $db = $this->config['database'] ?? 0;
            $timeout = $this->config['timeout'] ?? 5;

            $result = $this->redis->connect($host, $port, $timeout);

            if (!$result) {
                throw new \RuntimeException('Failed to connect to Redis: ' . $this->redis->errMsg);
            }

            if ($auth) {
                $this->redis->auth($auth);
            }

            if ($db !== 0) {
                $this->redis->select($db);
            }
        }
    }

    public function get($key)
    {
        $this->connect();
        return $this->redis->get($key);
    }

    public function set($key, $value, $ttl = null)
    {
        $this->connect();

        if ($ttl !== null) {
            return $this->redis->setex($key, $ttl, $value);
        }

        return $this->redis->set($key, $value);
    }

    public function del($key)
    {
        $this->connect();
        return $this->redis->del($key);
    }

    public function exists($key)
    {
        $this->connect();
        return $this->redis->exists($key);
    }

    public function expire($key, $seconds)
    {
        $this->connect();
        return $this->redis->expire($key, $seconds);
    }

    public function hGet($key, $field)
    {
        $this->connect();
        return $this->redis->hGet($key, $field);
    }

    public function hSet($key, $field, $value)
    {
        $this->connect();
        return $this->redis->hSet($key, $field, $value);
    }

    public function hGetAll($key)
    {
        $this->connect();
        return $this->redis->hGetAll($key);
    }

    public function hDel($key, $field)
    {
        $this->connect();
        return $this->redis->hDel($key, $field);
    }

    public function lPush($key, $value)
    {
        $this->connect();
        return $this->redis->lPush($key, $value);
    }

    public function rPush($key, $value)
    {
        $this->connect();
        return $this->redis->rPush($key, $value);
    }

    public function lPop($key)
    {
        $this->connect();
        return $this->redis->lPop($key);
    }

    public function rPop($key)
    {
        $this->connect();
        return $this->redis->rPop($key);
    }

    public function lLen($key)
    {
        $this->connect();
        return $this->redis->lLen($key);
    }

    public function incr($key)
    {
        $this->connect();
        return $this->redis->incr($key);
    }

    public function decr($key)
    {
        $this->connect();
        return $this->redis->decr($key);
    }

    public function keys($pattern)
    {
        $this->connect();
        return $this->redis->keys($pattern);
    }

    public function flushDb()
    {
        $this->connect();
        return $this->redis->flushDb();
    }

    public function getClient()
    {
        $this->connect();
        return $this->redis;
    }
}
