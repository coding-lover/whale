<?php

namespace App\Controllers;

use Sikelan\Http\Request;
use Sikelan\Http\Response;

class Test1Controller
{
    public function index(Request $request): Response
    {
        unset($request);

        // 统一成功响应：code=0 / data=空列表
        return (new Response())->ret([]);
    }

    public function show(Request $request): Response
    {
        $id = $request->getInt('id');

        return (new Response())->ret(['id' => $id]);
    }

    public function store(Request $request): Response
    {
        $data = $request->getPostParams();

        return (new Response())->ret($data);
    }

    public function update(Request $request): Response
    {
        $id = $request->getInt('id');
        $data = $request->getPostParams();

        return (new Response())->ret([
            'id'   => $id,
            'data' => $data,
        ]);
    }

    public function destroy(Request $request): Response
    {
        $id = $request->getInt('id');

        return (new Response())->ret(['id' => $id]);
    }
}
