<?php

namespace App\Controllers;

use Sikelan\Framework;

class StatusController
{
    public function show($request, $params)
    {
        $app = Framework::getInstance();

        return [
            'status' => 'success',
            'data' => $app->getStatus(),
        ];
    }
}
