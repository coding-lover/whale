<?php

use Sikelan\Http\Response;

return [
    [
        'method' => 'GET',
        'path' => '/api/strategies',
        'handler' => 'App\Controllers\StrategyController@index',
    ],
    [
        'method' => 'GET',
        'path' => '/api/strategies/{id}',
        'handler' => 'App\Controllers\StrategyController@show',
    ],
    [
        'method' => 'POST',
        'path' => '/api/strategies',
        'handler' => 'App\Controllers\StrategyController@store',
    ],
    [
        'method' => 'PUT',
        'path' => '/api/strategies/{id}',
        'handler' => 'App\Controllers\StrategyController@update',
    ],
    [
        'method' => 'DELETE',
        'path' => '/api/strategies/{id}',
        'handler' => 'App\Controllers\StrategyController@destroy',
    ],
    [
        'method' => 'GET',
        'path' => '/api/test1',
        'handler' => 'App\Controllers\Test1Controller@index',
    ],
    [
        'method' => 'GET',
        'path' => '/api/test1/{id}',
        'handler' => 'App\Controllers\Test1Controller@show',
    ],
    [
        'method' => 'POST',
        'path' => '/api/test1',
        'handler' => 'App\Controllers\Test1Controller@store',
    ],
    [
        'method' => 'PUT',
        'path' => '/api/test1/{id}',
        'handler' => 'App\Controllers\Test1Controller@update',
    ],
    [
        'method' => 'DELETE',
        'path' => '/api/test1/{id}',
        'handler' => 'App\Controllers\Test1Controller@destroy',
    ],
    [
        'method' => 'GET',
        'path' => '/api/orders',
        'handler' => 'App\Controllers\OrderController@index',
    ],
    [
        'method' => 'GET',
        'path' => '/api/orders/{id}',
        'handler' => 'App\Controllers\OrderController@show',
    ],
    [
        'method' => 'POST',
        'path' => '/api/orders',
        'handler' => 'App\Controllers\OrderController@store',
    ],
    [
        'method' => 'PUT',
        'path' => '/api/orders/{id}',
        'handler' => 'App\Controllers\OrderController@update',
    ],
    [
        'method' => 'DELETE',
        'path' => '/api/orders/{id}',
        'handler' => 'App\Controllers\OrderController@destroy',
    ],
    [
        'method' => 'GET',
        'path' => '/api/backtests',
        'handler' => 'App\Controllers\BackTestController@index',
    ],

    [
        'method' => 'GET',
        'path' => '/api/indexes/testSendMsg',
        'handler' => 'App\Controllers\IndexController@testSendMsg',
    ],
    [
        'method' => 'GET',
        'path' => '/api/indexes/index/{orderId}',
        'handler' => 'App\Controllers\IndexController@index',
    ],

    [
        'method' => 'GET',
        'path' => '/api/users',
        'handler' => 'App\Controllers\UserController@index',
    ],
    [
        'method' => 'GET',
        'path' => '/api/users/{id}',
        'handler' => 'App\Controllers\UserController@show',
    ],
    [
        'method' => 'POST',
        'path' => '/api/users',
        'handler' => 'App\Controllers\UserController@store',
    ],
    [
        'method' => 'PUT',
        'path' => '/api/users/{id}',
        'handler' => 'App\Controllers\UserController@update',
    ],
    [
        'method' => 'DELETE',
        'path' => '/api/users/{id}',
        'handler' => 'App\Controllers\UserController@destroy',
    ],

    [
        'method' => 'GET',
        'path' => '/api/testdemos',
        'handler' => 'App\Controllers\TestDemoController@index',
    ],
    [
        'method' => 'GET',
        'path' => '/api/testdemos/{id}',
        'handler' => 'App\Controllers\TestDemoController@show',
    ],
    [
        'method' => 'POST',
        'path' => '/api/testdemos',
        'handler' => 'App\Controllers\TestDemoController@store',
    ],
    [
        'method' => 'PUT',
        'path' => '/api/testdemos/{id}',
        'handler' => 'App\Controllers\TestDemoController@update',
    ],
    [
        'method' => 'DELETE',
        'path' => '/api/testdemos/{id}',
        'handler' => 'App\Controllers\TestDemoController@destroy',
    ],

    [
        'method' => 'GET',
        'path' => '/api/tests',
        'handler' => 'App\Controllers\TestController@index',
    ],
    [
        'method' => 'GET',
        'path' => '/api/tests/{id}',
        'handler' => 'App\Controllers\TestController@show',
    ],
    [
        'method' => 'POST',
        'path' => '/api/tests',
        'handler' => 'App\Controllers\TestController@store',
    ],
    [
        'method' => 'PUT',
        'path' => '/api/tests/{id}',
        'handler' => 'App\Controllers\TestController@update',
    ],
    [
        'method' => 'DELETE',
        'path' => '/api/tests/{id}',
        'handler' => 'App\Controllers\TestController@destroy',
    ],

    [
        'method' => 'GET',
        'path' => '/',
        'handler' => function () {
            return (new Response())->ret([
                'message' => 'Sikelan Framework is running',
                'version' => '1.0.0',
            ]);
        },
    ],
    [
        'method' => 'GET',
        'path' => '/api/health',
        'handler' => function () {
            return (new Response())->ret([
                'status'    => 'healthy',
                'timestamp' => time(),
            ]);
        },
    ],
    [
        'method' => 'GET',
        'path' => '/api/status',
        'handler' => 'App\Controllers\StatusController@show',
    ],
    [
        'method' => 'GET',
        'path' => '/api/test/{id}',
        'handler' => function ($request, $params) {
            return [
                'id' => $params['id'],
                'query' => $request->getQueryParams(),
            ];
        },
    ],
    [
        'method' => 'GET',
        'path' => '/api/task/test',
        'handler' => 'App\Controllers\TaskController@testNormalTask',
    ],
    [
        'method' => 'GET',
        'path' => '/api/task/exception',
        'handler' => 'App\Controllers\TaskController@testExceptionTask',
    ],
    [
        'method' => 'GET',
        'path' => '/api/task/async',
        'handler' => 'App\Controllers\TaskController@testAsyncExceptionTask',
    ],
    [
        'method' => 'GET',
        'path' => '/api/task/backtest',
        'handler' => 'App\Controllers\TaskController@testBackTestTask',
    ],

    // 交易所接口
    [
        'method' => 'GET',
        'path' => '/api/exchanges/{exchange}/ticker/{symbol}',
        'handler' => 'App\Controllers\ExchangeController@getTicker',
    ],
    [
        'method' => 'GET',
        'path' => '/api/exchanges/{exchange}/orderbook/{symbol}',
        'handler' => 'App\Controllers\ExchangeController@getOrderBook',
    ],
    [
        'method' => 'GET',
        'path' => '/api/exchanges/{exchange}/klines/{symbol}',
        'handler' => 'App\Controllers\ExchangeController@getKlines',
    ],
    [
        'method' => 'GET',
        'path' => '/api/exchanges/{exchange}/trades/{symbol}',
        'handler' => 'App\Controllers\ExchangeController@getTrades',
    ],
    [
        'method' => 'GET',
        'path' => '/api/exchanges/{exchange}/time',
        'handler' => 'App\Controllers\ExchangeController@getServerTime',
    ],
];
