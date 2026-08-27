<?php

namespace App\Hooks;

use App\Process\DataSyncProcess;
use App\Process\HeartbeatProcess;
use Sikelan\Hook\AbstractHook;
use Sikelan\Server\Server;

/**
 * 应用 Hook 示例
 * 
 * 演示如何通过 Hook 机制：
 * 1. 覆盖框架默认的事件回调
 * 2. 注册自定义进程到 Swoole Server（使用 AbstractProcess 基类）
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
     * 返回的事件会覆盖框架默认的同名事件回调。
     * 注意：覆盖 'request' 事件会完全替换框架的路由分发，
     * 除非你需要完全自定义请求处理，否则不要覆盖此事件。
     */
    public function registerEvents(): array
    {
        // 不覆盖任何事件，使用框架默认的路由分发
        // 如需自定义事件回调，在此返回对应的事件映射
        return [];
    }

    /**
     * 注册自定义进程
     * 
     * 使用 AbstractProcess 基类，支持优雅退出、管道通信、定时器、异常兜底
     */
    public function registerProcesses(): array
    {
        return [
            // 心跳进程：每 60 秒输出一次心跳
            //new HeartbeatProcess(),

            // 数据同步进程：每 300 秒执行一次同步
            //new DataSyncProcess(),
        ];
    }
}
