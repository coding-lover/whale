<?php

namespace App\Controllers;

use App\Process\DataSyncProcess;
use App\Services\Exchanges\Adapters\BinanceExchange;
use App\Services\Exchanges\ExchangeManager;
use App\Services\Exchanges\TradingSymbol;
use Sikelan\Framework;
use Sikelan\Http\Request;
use Sikelan\Http\Response;

class IndexController
{
    public function index(Request $request, $params)
    {
        //$Manager = Framework::getInstance()->getContainer()->get(ExchangeManager::class);
        $Manager = container()->get(ExchangeManager::class);
        $realSymbol = $Manager->exchange('binance')->formatSymbol('BTC/USDT:quarter');
        /** @var BinanceExchange $exchange */
        $exchange = $Manager->exchange('binance');
        $ticker = $exchange->getTicker('BTC/USDT:quarter');

        foreach (['BTC/USDT:quarter', 'BTC/USDT:swap', 'BTC/USDT'] as $symbol) {
            go(static function () use ($exchange, $symbol) {
                var_dump('start: ' . $symbol);
                $ticker = $exchange->getTicker($symbol);
                $ticker['raw_symbol'] = $symbol;
                var_dump($ticker);
            });
        }
//        go(static function () use ($exchange) {
//            $ticker = $exchange->getTicker('BTC/USDT:quarter');
//            var_dump($ticker);
//        });
//
//        go(static function () use ($exchange) {
//            $ticker = $exchange->getTicker('BTC/USDT:swap');
//        });
//
//        go(static function () use ($exchange) {
//            $ticker = $exchange->getTicker('BTC/USDT');
//        });



        $val = 9 * 1_000_000;

        return (new Response())->withJson([
            'message' => 'Welcome to QuantTrade',
            'method' => $request->getMethod(),
            'uri' => $request->getUri()->getPath(),
            'test' => $realSymbol,
            'val' => $val
        ]);
    }

    public function hello(Request $request, $params)
    {
        $name = $params['name'] ?? 'Guest';
        return [
            'message' => "Hello, {$name}!",
            'time' => date('Y-m-d H:i:s')
        ];
    }

    public function testSendMsg(Request $request, $params)
    {
        $Server = Framework::getInstance()->getServer();
        $Server->sendMessage('data_sync', json_encode(['action' => 'sync', 'table' => 'users']));
        var_dump('send ok!!');
    }
}
