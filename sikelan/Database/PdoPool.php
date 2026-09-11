<?php

namespace Sikelan\Database;

use Sikelan\Core\Config;
use Swoole\Coroutine;

/**
 * 协程感知的 PDO 连接池（懒加载）
 *
 * 为 Eloquent ORM 提供 Swoole 协程安全的 PDO 连接：
 *  - 懒加载：连接按需创建，不在构造函数中预建，避免无 DB 环境启动失败
 *  - 每个协程独占一个 PDO（存放在协程上下文），同一协程内复用
 *  - 协程结束时自动归还连接到池中
 *
 * 依赖 Swoole 的 PDO Hook 让 PDO 查询非阻塞，
 * 需在服务启动时调用 Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_PDO)。
 */
class PdoPool
{
    /** @var array 空闲 PDO 连接栈 */
    protected array $available = [];

    /** @var int 已创建的连接总数 */
    protected int $active = 0;

    /** @var int 最大连接数 */
    protected int $maxConnections;

    /** @var array PDO 构造配置 */
    protected array $config;

    public function __construct(Config $config)
    {
        $db = $config->get('database.mysql', []);

        $this->config = [
            'host' => $db['host'] ?? '127.0.0.1',
            'port' => $db['port'] ?? 3306,
            'username' => $db['username'] ?? 'root',
            'password' => $db['password'] ?? '',
            'database' => $db['database'] ?? '',
            'charset' => $db['charset'] ?? 'utf8mb4',
            'timeout' => $db['timeout'] ?? 5,
        ];

        $this->maxConnections = (int) ($db['pool_size'] ?? 10);
    }

    /**
     * 获取一个 PDO 连接
     *
     * 每个协程缓存自己的 PDO，避免同一协程内多次取还；
     * 协程结束时通过 defer 自动归还。
     *
     * @return \PDO
     */
    public function get(): \PDO
    {
        // 非协程环境（如 PHPUnit / CLI 脚本）：直接新建连接
        if (Coroutine::getCid() === -1) {
            return $this->createPdo();
        }

        $context = Coroutine::getContext();

        if (isset($context['pdo_connection'])) {
            return $context['pdo_connection'];
        }

        $pdo = $this->acquire();
        $context['pdo_connection'] = $pdo;

        // 协程结束时归还连接
        Coroutine::defer(function () use ($pdo) {
            $this->release($pdo);
        });

        return $pdo;
    }

    /**
     * 从池中获取或新建一个 PDO 连接
     *
     * @return \PDO
     */
    protected function acquire(): \PDO
    {
        // 优先复用空闲连接
        if (!empty($this->available)) {
            return array_pop($this->available);
        }

        // 未达上限则新建
        if ($this->active < $this->maxConnections) {
            $this->active++;
            return $this->createPdo();
        }

        // 已达上限：临时创建，归还时关闭（不放入池中）
        $this->active++;
        return $this->createPdo();
    }

    /**
     * 归还 PDO 连接到池中
     *
     * @param \PDO $pdo
     */
    public function release(\PDO $pdo): void
    {
        if (count($this->available) < $this->maxConnections) {
            $this->available[] = $pdo;
        } else {
            // 池已满，关闭该连接
            $this->active--;
        }
    }

    /**
     * 创建一个新的 PDO 连接
     *
     * @return \PDO
     * @throws \RuntimeException 连接失败时抛出
     */
    protected function createPdo(): \PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $this->config['host'],
            $this->config['port'],
            $this->config['database'],
            $this->config['charset']
        );

        try {
            $pdo = new \PDO(
                $dsn,
                $this->config['username'],
                $this->config['password'],
                [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                    \PDO::ATTR_TIMEOUT => $this->config['timeout'],
                    \PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (\PDOException $e) {
            throw new \RuntimeException(
                "Failed to connect to MySQL: {$e->getMessage()}",
                (int) $e->getCode(),
                $e
            );
        }

        return $pdo;
    }
}
