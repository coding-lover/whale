<?php

namespace Sikelan\Hook;

use Sikelan\Server\Server;

/**
 * Hook 接口
 * 
 * 定义框架生命周期中的钩子方法，
 * 允许用户在框架启动过程中自定义事件回调和自定义进程
 */
interface HookInterface
{
    /**
     * 框架初始化阶段钩子
     * 
     * 在框架核心组件初始化完成后、服务器启动前调用
     * 可用于注册自定义事件回调、添加自定义进程等
     * 
     * @param Server $server 服务器组件实例
     */
    public function onInitialize(Server $server): void;

    /**
     * 服务器启动前钩子
     * 
     * 在服务器即将启动前调用，此时事件已注册完毕
     * 可用于做最后的准备工作
     * 
     * @param Server $server 服务器组件实例
     */
    public function onServerStart(Server $server): void;

    /**
     * 注册自定义事件回调
     * 
     * 允许用户覆盖或追加框架默认的事件回调
     * 返回的事件配置会覆盖默认的同名事件回调
     * 
     * @return array<string, callable> 事件名称 => 回调函数
     */
    public function registerEvents(): array;

    /**
     * 注册自定义进程
     * 
     * 返回的自定义进程列表会被绑定到 Swoole Server，
     * 由 Swoole Server 统一管理生命周期
     * 
     * @return array<array{name: string, callback: callable, redirectStdinStdout: bool, pipeType: int}>
     */
    public function registerProcesses(): array;
}
