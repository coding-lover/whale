<?php

namespace Sikelan\Server;

/**
 * 事件注册器
 * 
 * 用于注册和管理 Swoole Server 的事件回调，
 * 将事件管理逻辑与 Swoole 实例解耦
 */
class EventRegister
{
    /**
     * 已注册的事件列表
     * 
     * @var array<string, array<callable>>
     */
    protected array $events = [];

    /**
     * 注册事件回调
     * 
     * @param string $event 事件名称（如 request, task, workerStart 等）
     * @param callable $callback 回调函数
     * @return self
     */
    public function on(string $event, callable $callback): self
    {
        $this->events[$event][] = $callback;
        return $this;
    }

    /**
     * 设置（覆盖）指定事件回调
     * 
     * 与 on() 不同，set() 会先清除该事件已有的所有回调，再注册新的回调
     * 用于 Hook 机制中覆盖框架默认的事件回调
     * 
     * @param string $event 事件名称
     * @param callable $callback 回调函数
     * @return self
     */
    public function set(string $event, callable $callback): self
    {
        $this->events[$event] = [$callback];
        return $this;
    }

    /**
     * 移除指定事件的所有回调
     * 
     * @param string $event 事件名称
     * @return self
     */
    public function remove(string $event): self
    {
        unset($this->events[$event]);
        return $this;
    }

    /**
     * 获取指定事件的所有回调
     * 
     * @param string $event 事件名称
     * @return array<callable>
     */
    public function get(string $event): array
    {
        return $this->events[$event] ?? [];
    }

    /**
     * 获取所有已注册的事件
     * 
     * @return array<string, array<callable>>
     */
    public function all(): array
    {
        return $this->events;
    }

    /**
     * 获取所有事件名称
     * 
     * @return array<string>
     */
    public function getEventNames(): array
    {
        return array_keys($this->events);
    }

    /**
     * 检查事件是否已注册
     * 
     * @param string $event 事件名称
     * @return bool
     */
    public function has(string $event): bool
    {
        return isset($this->events[$event]) && !empty($this->events[$event]);
    }

    /**
     * 清空所有事件
     * 
     * @return self
     */
    public function clear(): self
    {
        $this->events = [];
        return $this;
    }

    /**
     * 批量注册事件
     * 
     * @param array<string, callable|array<callable>> $events 事件配置
     * @return self
     */
    public function register(array $events): self
    {
        foreach ($events as $event => $callbacks) {
            if (is_array($callbacks)) {
                foreach ($callbacks as $callback) {
                    $this->on($event, $callback);
                }
            } elseif (is_callable($callbacks)) {
                $this->on($event, $callbacks);
            }
        }

        return $this;
    }
}
