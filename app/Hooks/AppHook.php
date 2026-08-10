<?php

namespace App\Hooks;

use Sikelan\Hook\AbstractHook;
use Sikelan\Server\Server;
use Swoole\Process;

/**
 * 应用 Hook 示例
 * 
 * 演示如何通过 Hook 机制：
 * 1. 覆盖框架默认的事件回调
 * 2. 注册自定义进程到 Swoole Server
 * 
 * 启用方式：在 config/app.php 中配置
 *   'hook' => \App\Hooks\AppHook::class,
 * 或在 .env 中配置
 *   APP_HOOK=\App\Hooks\AppHook
 */
class AppHook extends AbstractHook
{
    /**
     * 框架初始化阶段钩子
     */
    public function onInitialize(Server $server): void
    {
        $this->logger->info('AppHook: onInitialize called');
    }

    /**
     * 服务器启动前钩子
     */
    public function onServerStart(Server $server): void
    {
        $this->logger->info('AppHook: onServerStart called');
    }

    /**
     * 注册自定义事件回调
     * 
     * 返回的事件会覆盖框架默认的同名事件回调
     * 例如这里覆盖了 'request' 事件，自定义请求处理逻辑
     */
    public function registerEvents(): array
    {
        return [
            // 示例：覆盖默认的 request 事件，添加请求日志
            'request' => function ($request, $response) {
                $startTime = microtime(true);

                // 记录请求开始
                $this->logger->info('Request incoming', [
                    'method' => $request->server['request_method'] ?? 'GET',
                    'uri' => $request->server['request_uri'] ?? '/',
                ]);

                // 这里可以添加自定义的请求处理逻辑
                // 如果不需要覆盖请求处理，可以不注册此事件

                $response->header('Content-Type', 'application/json');
                $response->end(json_encode([
                    'code' => 200,
                    'message' => 'Handled by AppHook',
                    'data' => null,
                ]));

                // 记录请求耗时
                $duration = round((microtime(true) - $startTime) * 1000, 2);
                $this->logger->info("Request completed in {$duration}ms");
            },
        ];
    }

    /**
     * 注册自定义进程
     * 
     * 返回的进程会被绑定到 Swoole Server，由 Swoole Server 管理生命周期
     */
    public function registerProcesses(): array
    {
        return [
            [
                'name' => 'heartbeat',
                'callback' => function (Process $worker) {
                    $this->logger->info('Heartbeat process started');

                    // 每 60 秒输出一次心跳
                    while (true) {
                        sleep(60);
                        $this->logger->info('Heartbeat: ' . date('Y-m-d H:i:s'));
                    }
                },
                'redirectStdinStdout' => false,
                'pipeType' => 2,
            ],
            [
                'name' => 'data_sync',
                'callback' => function (Process $worker) {
                    $this->logger->info('Data sync process started');

                    // 每 300 秒执行一次数据同步
                    while (true) {
                        sleep(300);
                        $this->logger->info('Data sync running...');
                        // 在这里实现数据同步逻辑
                    }
                },
                'redirectStdinStdout' => false,
                'pipeType' => 2,
            ],
        ];
    }
}
