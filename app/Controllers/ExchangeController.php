<?php

namespace App\Controllers;

use Sikelan\Http\Request;
use Sikelan\Http\Response;
use Sikelan\Framework;
use App\Services\Exchanges\ExchangeException;

/**
 * 交易所接口控制器
 *
 * 提供交易所行情、深度、K线等数据的 HTTP 接口
 */
class ExchangeController
{
    /**
     * 获取指定交易所的交易对行情
     *
     * GET /api/exchanges/{exchange}/ticker/{symbol}
     *
     * @param Request $request HTTP 请求对象
     * @param array $params 路由参数（exchange, symbol）
     * @return Response JSON 响应
     */
    public function getTicker(Request $request, array $params): Response
    {
        $exchangeName = $params['exchange'] ?? 'binance';
        $symbol = $params['symbol'] ?? '';

        if ($symbol === '') {
            return (new Response(400))->withJson([
                'status' => 'error',
                'message' => 'Symbol parameter is required',
            ]);
        }

        try {
            $exchange = Framework::getInstance()->getExchange();
            $ticker = $exchange->exchange($exchangeName)->getTicker($symbol);

            return (new Response())->withJson([
                'status' => 'success',
                'data' => $ticker,
            ]);
        } catch (ExchangeException $e) {
            return (new Response(500))->withJson([
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 获取指定交易所的深度数据
     *
     * GET /api/exchanges/{exchange}/orderbook/{symbol}
     *
     * @param Request $request HTTP 请求对象
     * @param array $params 路由参数
     * @return Response JSON 响应
     */
    public function getOrderBook(Request $request, array $params): Response
    {
        $exchangeName = $params['exchange'] ?? 'binance';
        $symbol = $params['symbol'] ?? '';
        $limit = (int) ($request->getQueryParams()['limit'] ?? 100);

        if ($symbol === '') {
            return (new Response(400))->withJson([
                'status' => 'error',
                'message' => 'Symbol parameter is required',
            ]);
        }

        try {
            $exchange = Framework::getInstance()->getExchange();
            $book = $exchange->exchange($exchangeName)->getOrderBook($symbol, $limit);

            return (new Response())->withJson([
                'status' => 'success',
                'data' => $book,
            ]);
        } catch (ExchangeException $e) {
            return (new Response(500))->withJson([
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 获取指定交易所的 K 线数据
     *
     * GET /api/exchanges/{exchange}/klines/{symbol}
     *
     * @param Request $request HTTP 请求对象
     * @param array $params 路由参数
     * @return Response JSON 响应
     */
    public function getKlines(Request $request, array $params): Response
    {
        $exchangeName = $params['exchange'] ?? 'binance';
        $symbol = $params['symbol'] ?? '';
        $query = $request->getQueryParams();
        $interval = $query['interval'] ?? '1m';
        $limit = (int) ($query['limit'] ?? 100);

        if ($symbol === '') {
            return (new Response(400))->withJson([
                'status' => 'error',
                'message' => 'Symbol parameter is required',
            ]);
        }

        try {
            $exchange = Framework::getInstance()->getExchange();
            $klines = $exchange->exchange($exchangeName)->getKlines($symbol, $interval, $limit);

            return (new Response())->withJson([
                'status' => 'success',
                'data' => $klines,
            ]);
        } catch (ExchangeException $e) {
            return (new Response(500))->withJson([
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 获取指定交易所的最近成交记录
     *
     * GET /api/exchanges/{exchange}/trades/{symbol}
     *
     * @param Request $request HTTP 请求对象
     * @param array $params 路由参数
     * @return Response JSON 响应
     */
    public function getTrades(Request $request, array $params): Response
    {
        $exchangeName = $params['exchange'] ?? 'binance';
        $symbol = $params['symbol'] ?? '';
        $limit = (int) ($request->getQueryParams()['limit'] ?? 100);

        if ($symbol === '') {
            return (new Response(400))->withJson([
                'status' => 'error',
                'message' => 'Symbol parameter is required',
            ]);
        }

        try {
            $exchange = Framework::getInstance()->getExchange();
            $trades = $exchange->exchange($exchangeName)->getTrades($symbol, $limit);

            return (new Response())->withJson([
                'status' => 'success',
                'data' => $trades,
            ]);
        } catch (ExchangeException $e) {
            return (new Response(500))->withJson([
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 获取交易所服务器时间（用于时间同步校验）
     *
     * GET /api/exchanges/{exchange}/time
     *
     * @param Request $request HTTP 请求对象
     * @param array $params 路由参数
     * @return Response JSON 响应
     */
    public function getServerTime(Request $request, array $params): Response
    {
        $exchangeName = $params['exchange'] ?? 'binance';

        try {
            $exchange = Framework::getInstance()->getExchange();
            $time = $exchange->exchange($exchangeName)->getServerTime();

            return (new Response())->withJson([
                'status' => 'success',
                'data' => [
                    'exchange' => $exchangeName,
                    'server_time' => $time,
                    'local_time' => (int) (microtime(true) * 1000),
                ],
            ]);
        } catch (ExchangeException $e) {
            return (new Response(500))->withJson([
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
        }
    }
}
