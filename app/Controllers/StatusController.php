<?php

namespace App\Controllers;

use Sikelan\Framework;

class StatusController
{
    public function show()
    {
        $app = Framework::getInstance();

        return [
            'status' => 'success',
            'data' => $app->getStatus(),
        ];
    }
}
