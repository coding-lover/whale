<?php

namespace Sikelan\Database;

use Illuminate\Database\MySqlConnection;

/**
 * 协程安全的 MySQL 连接
 *
 * 继承 Eloquent 的 MySqlConnection，重写 getPdo()/getReadPdo()，
 * 让所有查询从 PdoPool 获取连接，保证 Swoole 协程环境下的连接隔离。
 */
class CoroutineMySqlConnection extends MySqlConnection
{
    /** @var PdoPool */
    protected PdoPool $pool;

    /**
     * @param PdoPool $pool PDO 连接池
     * @param string  $database 数据库名
     * @param string  $tablePrefix 表前缀
     * @param array   $config 连接配置
     */
    public function __construct(PdoPool $pool, string $database = '', string $tablePrefix = '', array $config = [])
    {
        $this->pool = $pool;
        parent::__construct(null, $database, $tablePrefix, $config);
    }

    /**
     * 获取 PDO 连接（从连接池取，协程内复用）
     *
     * @return \PDO
     */
    public function getPdo()
    {
        return $this->pool->get();
    }

    /**
     * 读连接也走同一个池（暂不做读写分离）
     *
     * @return \PDO
     */
    public function getReadPdo()
    {
        return $this->pool->get();
    }
}
