<?php

namespace Sikelan\Database;

use Illuminate\Container\Container as EloquentContainer;
use Illuminate\Database\Capsule\Manager as Capsule;
use Sikelan\Core\Config;

/**
 * Eloquent ORM 管理器
 *
 * 负责初始化 Eloquent Capsule，将框架的 PdoPool 接入 Eloquent 连接层，
 * 使 Model 层在 Swoole 协程环境下安全工作。
 *
 * 使用示例：
 *   $eloquent = app(EloquentManager::class);
 *   $eloquent->boot();
 *
 *   // 之后即可正常使用 Eloquent Model
 *   $user = User::find(1);
 */
class EloquentManager
{
    protected Config $config;

    protected PdoPool $pool;

    protected ?Capsule $capsule = null;

    public function __construct(Config $config, PdoPool $pool)
    {
        $this->config = $config;
        $this->pool = $pool;
    }

    /**
     * 启动 Eloquent，配置连接并设为全局可解析
     *
     * @return void
     */
    public function boot(): void
    {
        if ($this->capsule !== null) {
            return;
        }

        $this->capsule = new Capsule();

        $db = $this->config->get('database.mysql', []);

        // 注册自定义连接（使用协程连接池）
        $this->capsule->getContainer()->bind('db.connection', function () use ($db) {
            return new CoroutineMySqlConnection(
                $this->pool,
                $db['database'] ?? '',
                '',
                $db
            );
        });

        // 添加默认连接配置（供 Eloquent 内部使用）
        $this->capsule->addConnection([
            'driver' => 'mysql',
            'host' => $db['host'] ?? '127.0.0.1',
            'port' => $db['port'] ?? 3306,
            'database' => $db['database'] ?? '',
            'username' => $db['username'] ?? 'root',
            'password' => $db['password'] ?? '',
            'charset' => $db['charset'] ?? 'utf8mb4',
            'prefix' => '',
            'strict' => true,
        ], 'default');

        // 设为全局可解析，让 Model 静态方法可用
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();
    }

    /**
     * 获取 Eloquent Capsule 实例
     *
     * @return Capsule|null
     */
    public function getCapsule(): ?Capsule
    {
        return $this->capsule;
    }

    /**
     * 获取数据库连接（用于原生查询等场景）
     *
     * @return \Illuminate\Database\Connection
     */
    public function connection()
    {
        return $this->capsule->getConnection();
    }
}
