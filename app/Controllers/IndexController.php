<?php

namespace App\Controllers;

use Sikelan\Http\Request;
use Sikelan\Http\Response;

class IndexController
{
    public function index(Request $request, $params)
    {
        return (new Response())->withJson([
            'message' => 'Welcome to QuantTrade',
            'method' => $request->getMethod(),
            'uri' => $request->getUri()->getPath()
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
}
