<?php

namespace Sikelan\Database;

use Sikelan\Core\Config;
use Swoole\Coroutine\MySQL;

class MysqlPool
{
    protected $pool = [];
    protected $config;
    protected $maxConnections = 10;
    protected $currentConnections = 0;

    public function __construct(Config $config)
    {
        $this->config = $config->get('database.mysql', []);
        $this->maxConnections = $this->config['pool_size'] ?? 10;
    }

    public function get()
    {
        if (!empty($this->pool)) {
            return array_pop($this->pool);
        }

        if ($this->currentConnections < $this->maxConnections) {
            return $this->createConnection();
        }

        throw new \RuntimeException('Database connection pool is full');
    }

    public function release(MySQL $connection)
    {
        if (count($this->pool) < $this->maxConnections) {
            $this->pool[] = $connection;
        } else {
            $connection->close();
            $this->currentConnections--;
        }
    }

    protected function createConnection()
    {
        $connection = new MySQL();

        $config = [
            'host' => $this->config['host'] ?? '127.0.0.1',
            'port' => $this->config['port'] ?? 3306,
            'user' => $this->config['username'] ?? 'root',
            'password' => $this->config['password'] ?? '',
            'database' => $this->config['database'] ?? '',
            'charset' => $this->config['charset'] ?? 'utf8mb4',
            'timeout' => $this->config['timeout'] ?? 5,
        ];

        $result = $connection->connect($config);

        if (!$result) {
            throw new \RuntimeException('Failed to connect to MySQL: ' . $connection->error);
        }

        $this->currentConnections++;
        return $connection;
    }

    public function query($sql, $params = [])
    {
        $connection = $this->get();

        try {
            if (!empty($params)) {
                $stmt = $connection->prepare($sql);
                $result = $stmt->execute($params);
            } else {
                $result = $connection->query($sql);
            }

            return $result;
        } finally {
            $this->release($connection);
        }
    }

    public function select($sql, $params = [])
    {
        $result = $this->query($sql, $params);
        return $result ? $result->fetchAll(MYSQLI_ASSOC) : [];
    }

    public function insert($table, $data)
    {
        $columns = array_keys($data);
        $placeholders = array_fill(0, count($columns), '?');
        $values = array_values($data);

        $sql = "INSERT INTO {$table} (" . implode(',', $columns) . ") VALUES (" . implode(',', $placeholders) . ")";

        $connection = $this->get();

        try {
            $stmt = $connection->prepare($sql);
            $result = $stmt->execute($values);

            if ($result) {
                return $connection->insertId;
            }

            return false;
        } finally {
            $this->release($connection);
        }
    }

    public function update($table, $data, $where)
    {
        $setClause = [];
        $values = [];

        foreach ($data as $key => $value) {
            $setClause[] = "{$key} = ?";
            $values[] = $value;
        }

        foreach ($where as $key => $value) {
            $values[] = $value;
        }

        $whereClause = implode(' AND ', array_map(function ($key) {
            return "{$key} = ?";
        }, array_keys($where)));

        $sql = "UPDATE {$table} SET " . implode(',', $setClause) . " WHERE {$whereClause}";

        $connection = $this->get();

        try {
            $stmt = $connection->prepare($sql);
            return $stmt->execute($values);
        } finally {
            $this->release($connection);
        }
    }

    public function delete($table, $where)
    {
        $values = [];
        $whereClause = implode(' AND ', array_map(function ($key) use (&$values, $where) {
            $values[] = $where[$key];
            return "{$key} = ?";
        }, array_keys($where)));

        $sql = "DELETE FROM {$table} WHERE {$whereClause}";

        $connection = $this->get();

        try {
            $stmt = $connection->prepare($sql);
            return $stmt->execute($values);
        } finally {
            $this->release($connection);
        }
    }

    public function beginTransaction()
    {
        $connection = $this->get();
        $connection->begin();
        return $connection;
    }

    public function commit(MySQL $connection)
    {
        $result = $connection->commit();
        $this->release($connection);
        return $result;
    }

    public function rollback(MySQL $connection)
    {
        $result = $connection->rollback();
        $this->release($connection);
        return $result;
    }
}
