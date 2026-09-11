<?php

namespace App\Controllers;

use Sikelan\Http\Request;
use Sikelan\Http\Response;

class OrderController
{
    public function index(Request $request)
    {
        return [
            'status' => 'success',
            'data' => [],
        ];
    }

    public function show(Request $request)
    {
        $id = $request->getInt('id');

        return [
            'status' => 'success',
            'data' => [
                'id' => $id,
            ],
        ];
    }

    public function store(Request $request)
    {
        $data = $request->getPostParams();

        return (new Response(201))->withJson([
            'status' => 'success',
            'message' => 'Created successfully',
            'data' => $data,
        ]);
    }

    public function update(Request $request)
    {
        $id = $request->getInt('id');
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

    public function destroy(Request $request)
    {
        $id = $request->getInt('id');

        return [
            'status' => 'success',
            'message' => 'Deleted successfully',
            'data' => [
                'id' => $id,
            ],
        ];
    }
}
