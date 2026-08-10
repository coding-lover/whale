<?php

namespace App\Tasks;

use Sikelan\Task\TaskInterface;
use Sikelan\Core\Logger;

class ExceptionDemoTask implements TaskInterface
{
    private $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    public function handle(array $args)
    {
        $shouldThrow = $args['should_throw'] ?? false;
        $message = $args['message'] ?? 'No message provided';

        $this->logger->info('ExceptionDemoTask started', [
            'should_throw' => $shouldThrow,
            'message' => $message
        ]);

        if ($shouldThrow) {
            $this->logger->warning('ExceptionDemoTask will throw an exception', [
                'message' => $message
            ]);

            throw new \RuntimeException("Intentional exception: {$message}");
        }

        $this->logger->info('ExceptionDemoTask completed successfully', [
            'message' => $message
        ]);

        return [
            'success' => true,
            'message' => $message,
            'timestamp' => date('Y-m-d H:i:s'),
            'args_received' => $args
        ];
    }
}
