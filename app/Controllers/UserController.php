<?php

namespace App\Controllers;

use Sikelan\Http\Request;
use Sikelan\Http\Response;

class UserController
{
    public function index(Request $request, $params)
    {
        return [
            'status' => 'success',
            'data' => [],
        ];
    }

    public function show(Request $request, $params)
    {
        $id = $params['id'] ?? 0;

        return [
            'status' => 'success',
            'data' => [
                'id' => $id,
            ],
        ];
    }

    public function store(Request $request, $params)
    {
        $data = $request->getPostParams();

        return (new Response(201))->withJson([
            'status' => 'success',
            'message' => 'Created successfully',
            'data' => $data,
        ]);
    }

    public function update(Request $request, $params)
    {
        $id = $params['id'] ?? 0;
        $data = $request->getPostParams();

        return [
            'status' => 'success',
            'message' => 'Updated successfully',
            'data' => [
                'id' => $id,
                'data' => $data,
            ],
        ];
    }

    public function destroy(Request $request, $params)
    {
        $id = $params['id'] ?? 0;

        return [
            'status' => 'success',
            'message' => 'Deleted successfully',
            'data' => [
                'id' => $id,
            ],
        ];
    }
}
