<?php

namespace App\Controllers;

use App\Process\DataSyncProcess;
use App\Services\Exchanges\Adapters\BinanceExchange;
use App\Services\Exchanges\ExchangeManager;
use App\Services\Exchanges\TradingSymbol;
use App\Services\Test\TestServicesProvider;
use Sikelan\Framework;
use Sikelan\Http\Request;
use Sikelan\Http\Response;

class IndexController
{
    public function index(Request $request, TestServicesProvider $testServicesProvider)
    {
        //$Manager = Framework::getInstance()->getContainer()->get(ExchangeManager::class);

        //$testData = app(TestServicesProvider::class)->test();
        $p = $request->all();
        var_dump($p);

        $p = $request->getRouteParams();
        var_dump($p);

        $testData = $testServicesProvider->test();
        var_dump($testData);

        /** @var ExchangeManager $Manager */
        $Manager = app(ExchangeManager::class);
        $realSymbol = $Manager->exchange('binance')->formatSymbol('BTC/USDT:quarter');
        /** @var BinanceExchange $exchange */
        $exchange = $Manager->exchange('binance');
        $ticker = $exchange->getTicker('BTC/USDT:quarter');

        foreach (['BTC/USDT:quarter', 'BTC/USDT:swap', 'BTC/USDT'] as $symbol) {
            go(static function () use ($exchange, $symbol) {
                //var_dump('start: ' . $symbol);
                $ticker = $exchange->getTicker($symbol);
                $ticker['raw_symbol'] = $symbol;
                //var_dump($ticker);
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
            'val' => $val,
            'html' => '<a>' . $realSymbol . '</a>',
        ], true);
    }

    public function hello(Request $request)
    {
        // 安全示范：HTML 转义用户输入防反射型 XSS
        // 原写法 "Hello, {$name}!" 直接拼字符串 → ?name=<script>alert(1)</script> 会注入
        // 改用 e() 转义 → <script> 会被转为 &lt;script&gt;，安全渲染为文本
        $name = e($request->input('name', 'Guest'));
        return [
            'message' => "Hello, {$name}!",
            'time' => date('Y-m-d H:i:s'),
        ];
    }

    public function testSendMsg(Request $request)
    {
        $Server = Framework::getInstance()->getServer();
        $Server->sendMessage('data_sync', json_encode(['action' => 'sync', 'table' => 'users']));
        var_dump('send ok!!');
    }
}
