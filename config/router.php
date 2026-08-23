<?php

use Sikelan\Http\Response;

return [
    [
        'method' => 'GET',
        'path' => '/api/indexes/testSendMsg',
        'handler' => 'App\Controllers\IndexController@testSendMsg',
    ],
    [
        'method' => 'GET',
        'path' => '/api/indexes/index',
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
            return (new Response())->withJson([
                'status' => 'success',
                'message' => 'Sikelan Framework is running',
                'version' => '1.0.0',
            ]);
        },
    ],
    [
        'method' => 'GET',
        'path' => '/api/health',
        'handler' => function () {
            return [
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

                'status' => 'healthy',
                'timestamp' => time(),
            ];
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
