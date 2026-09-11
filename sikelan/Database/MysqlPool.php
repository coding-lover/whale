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

    /**
     * 转义 SQL 标识符（表名 / 列名）
     *
     * 用反引号包裹，并校验白名单字符（仅允许字母、数字、下划线）。
     * 防止表名/列名注入：值已用 prepare 参数化，但表名/列名不能参数化，
     * 必须显式校验。
     *
     * @param string $name 标识符（表名或列名）
     * @return string 反引号包裹的标识符
     * @throws \InvalidArgumentException 名字含非法字符（如 ; -- 空格等）
     */
    public function quoteIdentifier(string $name): string
    {
        // 只允许字母数字下划线，首字符不能是数字（MySQL 标识符规则）
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException(
                "Invalid SQL identifier: {$name} (only letters, digits and underscores are allowed,"
                . " and the first character must be a letter or underscore)"
            );
        }
        // 双反引号转义内部反引号（虽然白名单已禁止反引号，仍按规范处理）
        return '`' . str_replace('`', '``', $name) . '`';
    }

    public function insert($table, $data)
    {
        $columns = array_keys($data);
        $placeholders = array_fill(0, count($columns), '?');
        $values = array_values($data);

        // 安全修复：表名和列名用 quoteIdentifier 转义，防止 SQL 注入
        $quotedColumns = array_map([$this, 'quoteIdentifier'], $columns);
        $sql = "INSERT INTO " . $this->quoteIdentifier($table)
            . " (" . implode(',', $quotedColumns) . ") VALUES (" . implode(',', $placeholders) . ")";

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
            // 列名用 quoteIdentifier 转义
            $setClause[] = $this->quoteIdentifier($key) . " = ?";
            $values[] = $value;
        }

        foreach ($where as $key => $value) {
            $values[] = $value;
        }

        // WHERE 子句的列名也转义
        $whereClause = implode(' AND ', array_map(function ($key) {
            return $this->quoteIdentifier($key) . " = ?";
        }, array_keys($where)));

        $sql = "UPDATE " . $this->quoteIdentifier($table)
            . " SET " . implode(',', $setClause)
            . " WHERE {$whereClause}";

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
            return $this->quoteIdentifier($key) . " = ?";
        }, array_keys($where)));

        $sql = "DELETE FROM " . $this->quoteIdentifier($table) . " WHERE {$whereClause}";

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
