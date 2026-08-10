<?php

namespace Sikelan\Core;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class Logger implements LoggerInterface
{
    protected $logLevel;
    protected $logPath;
    protected $channel;

    public const LEVELS = [
        LogLevel::EMERGENCY => 0,
        LogLevel::ALERT => 1,
        LogLevel::CRITICAL => 2,
        LogLevel::ERROR => 3,
        LogLevel::WARNING => 4,
        LogLevel::NOTICE => 5,
        LogLevel::INFO => 6,
        LogLevel::DEBUG => 7,
    ];

    public function __construct(Config $config)
    {
        $this->logLevel = $config->get('app.log_level', LogLevel::DEBUG);
        $this->logPath = $config->get('app.log_path', LOG_PATH);
        $this->channel = $config->get('app.log_channel', 'app');

        if (!is_dir($this->logPath)) {
            mkdir($this->logPath, 0755, true);
        }
    }

    public function emergency($message, array $context = [])
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    public function alert($message, array $context = [])
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    public function critical($message, array $context = [])
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    public function error($message, array $context = [])
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    public function warning($message, array $context = [])
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    public function notice($message, array $context = [])
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    public function info($message, array $context = [])
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    public function debug($message, array $context = [])
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }

    public function log($level, $message, array $context = [])
    {
        if (!isset(self::LEVELS[$level])) {
            throw new \InvalidArgumentException("Invalid log level: {$level}");
        }

        if (self::LEVELS[$level] > self::LEVELS[$this->logLevel]) {
            return;
        }

        $logMessage = $this->formatMessage($level, $message, $context);
        $this->writeLog($level, $logMessage);
    }

    protected function formatMessage($level, $message, array $context = [])
    {
        $timestamp = date('Y-m-d H:i:s');
        $pid = posix_getpid();

        if (is_array($message) || is_object($message)) {
            $message = json_encode($message, JSON_UNESCAPED_UNICODE);
        }

        $contextStr = !empty($context) ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';

        return "[{$timestamp}] [{$pid}] [{$level}] {$message}{$contextStr}\n";
    }

    protected function writeLog($level, $message)
    {
        $filename = $this->logPath . '/' . $this->channel . '_' . date('Y-m-d') . '.log';
        file_put_contents($filename, $message, FILE_APPEND | LOCK_EX);
    }
}
