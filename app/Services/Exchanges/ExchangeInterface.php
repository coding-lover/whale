<?php

namespace App\Services\Exchanges;

/**
 * 交易所统一接口
 *
 * 定义所有交易所适配器必须实现的方法，
 * 上层调用方只需面向此接口编程，无需关心底层交易所差异。
 *
 * 统一交易对格式：BTC/USDT（基准/计价 中间用 / 分隔）
 * 统一K线周期格式：1m, 5m, 15m, 30m, 1h, 4h, 1d, 1w
 * 统一时间戳格式：毫秒级整数
 */
interface ExchangeInterface
{
    // ==================== 市场数据（公开接口，无需认证） ====================

    /**
     * 获取最新行情
     *
     * @param string $symbol 交易对，如 BTC/USDT
     * @return array 统一格式：[symbol, price, timestamp]
     */
    public function getTicker(string $symbol): array;

    /**
     * 获取深度数据
     *
     * @param string $symbol 交易对
     * @param int $limit 档位数量（默认 100）
     * @return array 统一格式：[bids => [[price, qty], ...], asks => [[price, qty], ...]]
     */
    public function getOrderBook(string $symbol, int $limit = 100): array;

    /**
     * 获取K线数据
     *
     * @param string $symbol 交易对
     * @param string $interval 周期，如 1m, 5m, 15m, 1h, 4h, 1d
     * @param int $limit 数量（默认 100，最大 1000）
     * @return array 统一格式：[[timestamp, open, high, low, close, volume], ...]
     */
    public function getKlines(string $symbol, string $interval, int $limit = 100): array;

    /**
     * 获取最近成交记录
     *
     * @param string $symbol 交易对
     * @param int $limit 数量（默认 100）
     * @return array 统一格式：[[id, price, qty, time, side], ...]
     */
    public function getTrades(string $symbol, int $limit = 100): array;

    /**
     * 获取服务器时间
     *
     * @return int 毫秒级时间戳
     */
    public function getServerTime(): int;

    // ==================== 账户数据（私有接口，需认证） ====================

    /**
     * 获取账户余额
     *
     * @return array 统一格式：[asset => [free, used, total], ...]
     */
    public function getBalance(): array;

    // ==================== 交易接口（私有接口，需认证） ====================

    /**
     * 创建订单
     *
     * @param array $params 订单参数
     *   - symbol:    string  交易对，如 BTC/USDT
     *   - side:      string  买卖方向 buy|sell
     *   - type:      string  订单类型 limit|market
     *   - amount:    float   数量
     *   - price:     float   价格（limit 类型必填）
     *   - clientOrderId: string 客户自定义订单号（可选）
     * @return array 统一格式：[id, clientOrderId, symbol, status, ...]
     */
    public function createOrder(array $params): array;

    /**
     * 撤销订单
     *
     * @param string $orderId 交易所订单 ID
     * @param string $symbol 交易对
     * @return array 统一格式：[id, symbol, status, ...]
     */
    public function cancelOrder(string $orderId, string $symbol): array;

    /**
     * 查询订单详情
     *
     * @param string $orderId 交易所订单 ID
     * @param string $symbol 交易对
     * @return array 统一格式：[id, symbol, status, type, side, price, amount, filled, ...]
     */
    public function getOrder(string $orderId, string $symbol): array;

    /**
     * 获取当前挂单列表
     *
     * @param string $symbol 交易对（空字符串表示查询所有）
     * @return array 订单列表
     */
    public function getOpenOrders(string $symbol = ''): array;

    // ==================== 元信息 ====================

    /**
     * 获取交易所名称
     *
     * @return string 如 binance, okx
     */
    public function getName(): string;
}
