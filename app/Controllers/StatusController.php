<?php

namespace App\Controllers;

use Sikelan\Framework;
use Sikelan\Http\Response;

class StatusController
{
    public function show(): Response
    {
        $app = Framework::getInstance();

        // 统一格式：code=0 / data=状态信息
        return (new Response())->ret($app->getStatus());
    }
}
