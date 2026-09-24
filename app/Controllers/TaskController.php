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

    public function testExceptionTask(Request $request): Response
    {
        unset($request);

        $taskManager = Framework::getInstance()->getTaskManager();

        try {
            $result = $taskManager->sync(\App\Tasks\ExceptionDemoTask::class, [
                'should_throw' => true,
                'message'      => 'This task will intentionally throw an exception',
            ]);

            if ($result['success']) {
                return (new Response())->ret($result['data']);
            }

            return (new Response())->err(
                'Task execution failed',
                Response::CODE_SERVER_ERROR,
                ['error' => $result['error']]
            );
        } catch (\RuntimeException $e) {
            return (new Response())->err(
                'Server not running',
                Response::CODE_SERVER_ERROR,
                ['error' => $e->getMessage()]
            );
        }
    }

    public function testNormalTask(Request $request): Response
    {
        unset($request);

        $taskManager = Framework::getInstance()->getTaskManager();

        try {
            $result = $taskManager->sync(\App\Tasks\ExceptionDemoTask::class, [
                'should_throw' => false,
                'message'      => 'This task will execute normally',
            ]);

            if ($result['success']) {
                return (new Response())->ret($result['data']);
            }

            return (new Response())->err(
                'Task execution failed',
                Response::CODE_SERVER_ERROR,
                ['error' => $result['error']]
            );
        } catch (\RuntimeException $e) {
            return (new Response())->err(
                'Server not running',
                Response::CODE_SERVER_ERROR,
                ['error' => $e->getMessage()]
            );
        }
    }

    public function testAsyncExceptionTask(Request $request): Response
    {
        unset($request);

        $taskManager = Framework::getInstance()->getTaskManager();

        try {
            $callbackResult = null;

            $taskManager->async(\App\Tasks\ExceptionDemoTask::class, [
                'should_throw' => true,
                'message'      => 'Async exception demo',
            ], function ($result) use (&$callbackResult) {
                $callbackResult = $result;
            });

            return (new Response())->ret([
                'callback_received' => $callbackResult !== null,
                'callback_data'     => $callbackResult,
            ], ['message_hint' => 'Async task submitted']);
        } catch (\RuntimeException $e) {
            return (new Response())->err(
                'Server not running',
                Response::CODE_SERVER_ERROR,
                ['error' => $e->getMessage()]
            );
        }
    }

    public function testBackTestTask(Request $request): Response
    {
        unset($request);

        $taskManager = Framework::getInstance()->getTaskManager();

        try {
            $callbackResult = null;

            $taskManager->async(\App\Tasks\BackTestTask::class, [
                'should_throw' => true,
                'message'      => 'Async exception demo',
            ], function ($result) use (&$callbackResult) {
                $callbackResult = $result;
            });

            return (new Response())->ret([
                'callback_received' => $callbackResult !== null,
                'callback_data'     => $callbackResult,
            ], ['message_hint' => 'Async task submitted']);
        } catch (\RuntimeException $e) {
            return (new Response())->err(
                'Server not running',
                Response::CODE_SERVER_ERROR,
                ['error' => $e->getMessage()]
            );
        }
    }
}
