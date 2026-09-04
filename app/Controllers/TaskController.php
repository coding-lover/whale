<?php

namespace App\Controllers;

use Sikelan\Http\Request;
use Sikelan\Http\Response;
use Sikelan\Framework;
use Sikelan\Core\Logger;

class TaskController
{
    private $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    public function testExceptionTask(Request $request, $params)
    {
        $taskManager = Framework::getInstance()->getTaskManager();

        try {
            $result = $taskManager->sync(\App\Tasks\ExceptionDemoTask::class, [
                'should_throw' => true,
                'message' => 'This task will intentionally throw an exception'
            ]);

            if ($result['success']) {
                return (new Response())->withJson([
                    'status' => 'success',
                    'data' => $result['data']
                ]);
            } else {
                return (new Response(500))->withJson([
                    'status' => 'error',
                    'message' => 'Task execution failed',
                    'error' => $result['error']
                ]);
            }
        } catch (\RuntimeException $e) {
            return (new Response(500))->withJson([
                'status' => 'error',
                'message' => 'Server not running',
                'error' => $e->getMessage()
            ]);
        }
    }

    public function testNormalTask(Request $request, $params)
    {
        $taskManager = Framework::getInstance()->getTaskManager();

        try {
            $result = $taskManager->sync(\App\Tasks\ExceptionDemoTask::class, [
                'should_throw' => false,
                'message' => 'This task will execute normally'
            ]);

            if ($result['success']) {
                return (new Response())->withJson([
                    'status' => 'success',
                    'data' => $result['data']
                ]);
            } else {
                return (new Response(500))->withJson([
                    'status' => 'error',
                    'message' => 'Task execution failed',
                    'error' => $result['error']
                ]);
            }
        } catch (\RuntimeException $e) {
            return (new Response(500))->withJson([
                'status' => 'error',
                'message' => 'Server not running',
                'error' => $e->getMessage()
            ]);
        }
    }

    public function testAsyncExceptionTask(Request $request, $params)
    {
        $taskManager = Framework::getInstance()->getTaskManager();

        try {
            $callbackResult = null;

            $taskManager->async(\App\Tasks\ExceptionDemoTask::class, [
                'should_throw' => true,
                'message' => 'Async exception demo'
            ], function ($result) use (&$callbackResult) {
                $callbackResult = $result;
            });

            return (new Response())->withJson([
                'status' => 'accepted',
                'message' => 'Async task submitted',
                'callback_received' => $callbackResult !== null,
                'callback_data' => $callbackResult
            ]);
        } catch (\RuntimeException $e) {
            return (new Response(500))->withJson([
                'status' => 'error',
                'message' => 'Server not running',
                'error' => $e->getMessage()
            ]);
        }
    }

    public function testBackTestTask(Request $request, $params)
    {
        $taskManager = Framework::getInstance()->getTaskManager();

        try {
            $callbackResult = null;

            $taskManager->async(\App\Tasks\BackTestTask::class, [
                'should_throw' => true,
                'message' => 'Async exception demo'
            ], function ($result) use (&$callbackResult) {
                $callbackResult = $result;
            });

            return (new Response())->withJson([
                'status' => 'accepted',
                'message' => 'Async task submitted',
                'callback_received' => $callbackResult !== null,
                'callback_data' => $callbackResult
            ]);
        } catch (\RuntimeException $e) {
            return (new Response(500))->withJson([
                'status' => 'error',
                'message' => 'Server not running',
                'error' => $e->getMessage()
            ]);
        }
    }
}
