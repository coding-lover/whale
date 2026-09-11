<?php

namespace Sikelan\Tests\Stest;

use PHPUnit\Framework\TestCase;
use Sikelan\Core\Config;
use Sikelan\Core\Logger;
use Psr\Log\LogLevel;

/**
 * Logger 全覆盖测试
 */
class LoggerTest extends TestCase
{
    private string $tempLogPath;
    private Config $config;
    private Logger $logger;

    protected function setUp(): void
    {
        $this->tempLogPath = sys_get_temp_dir() . '/sikelan_test_logs_' . uniqid();
        mkdir($this->tempLogPath);

        // 创建配置
        $this->config = new Config();
        $this->config->set('app.log_level', LogLevel::DEBUG);
        $this->config->set('app.log_path', $this->tempLogPath);
        $this->config->set('app.log_channel', 'test');

        $this->logger = new Logger($this->config);
    }

    protected function tearDown(): void
    {
        // 清理日志文件
        array_map('unlink', glob($this->tempLogPath . '/*.log'));
        if (is_dir($this->tempLogPath)) {
            rmdir($this->tempLogPath);
        }
    }

    private function getLatestLogFile(): ?string
    {
        $files = glob($this->tempLogPath . '/*.log');
        return $files ? end($files) : null;
    }

    private function getLatestLogContent(): string
    {
        $file = $this->getLatestLogFile();
        return $file ? file_get_contents($file) : '';
    }

    // ========== 构造函数测试 ==========

    public function testConstructorCreatesLogDirectory()
    {
        $newPath = sys_get_temp_dir() . '/sikelan_new_log_dir_' . uniqid();

        $config = new Config();
        $config->set('app.log_path', $newPath);
        $config->set('app.log_level', LogLevel::INFO);
        $config->set('app.log_channel', 'new');

        $logger = new Logger($config);

        $this->assertTrue(is_dir($newPath));

        // 清理
        array_map('unlink', glob($newPath . '/*.log'));
        rmdir($newPath);
    }

    // ========== PSR-3 日志级别方法测试 ==========

    public function testEmergency()
    {
        $this->logger->emergency('Emergency message');

        $content = $this->getLatestLogContent();
        $this->assertStringContainsString('Emergency message', $content);
        $this->assertStringContainsString(LogLevel::EMERGENCY, $content);
    }

    public function testAlert()
    {
        $this->logger->alert('Alert message');

        $content = $this->getLatestLogContent();
        $this->assertStringContainsString('Alert message', $content);
        $this->assertStringContainsString(LogLevel::ALERT, $content);
    }

    public function testCritical()
    {
        $this->logger->critical('Critical message');

        $content = $this->getLatestLogContent();
        $this->assertStringContainsString('Critical message', $content);
        $this->assertStringContainsString(LogLevel::CRITICAL, $content);
    }

    public function testError()
    {
        $this->logger->error('Error message');

        $content = $this->getLatestLogContent();
        $this->assertStringContainsString('Error message', $content);
        $this->assertStringContainsString(LogLevel::ERROR, $content);
    }

    public function testWarning()
    {
        $this->logger->warning('Warning message');

        $content = $this->getLatestLogContent();
        $this->assertStringContainsString('Warning message', $content);
        $this->assertStringContainsString(LogLevel::WARNING, $content);
    }

    public function testNotice()
    {
        $this->logger->notice('Notice message');

        $content = $this->getLatestLogContent();
        $this->assertStringContainsString('Notice message', $content);
        $this->assertStringContainsString(LogLevel::NOTICE, $content);
    }

    public function testInfo()
    {
        $this->logger->info('Info message');

        $content = $this->getLatestLogContent();
        $this->assertStringContainsString('Info message', $content);
        $this->assertStringContainsString(LogLevel::INFO, $content);
    }

    public function testDebug()
    {
        $this->logger->debug('Debug message');

        $content = $this->getLatestLogContent();
        $this->assertStringContainsString('Debug message', $content);
        $this->assertStringContainsString(LogLevel::DEBUG, $content);
    }

    // ========== log() 方法测试 ==========

    public function testLogWithValidLevel()
    {
        $this->logger->log(LogLevel::ERROR, 'Test log message');

        $content = $this->getLatestLogContent();
        $this->assertStringContainsString('Test log message', $content);
    }

    public function testLogWithInvalidLevelThrowsException()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid log level/');

        $this->logger->log('invalid_level', 'Test message');
    }

    public function testLogRespectsLogLevel()
    {
        // 设置日志级别为 ERROR
        $config = new Config();
        $config->set('app.log_level', LogLevel::ERROR);
        $config->set('app.log_path', $this->tempLogPath);
        $config->set('app.log_channel', 'level_test');

        $logger = new Logger($config);

        // DEBUG 消息不应该被记录
        $logger->debug('This should not be logged');

        $content = $this->getLatestLogContent();
        $this->assertStringNotContainsString('This should not be logged', $content);
    }

    // ========== 日志上下文测试 ==========

    public function testLogWithContext()
    {
        $this->logger->info('Test message', ['key' => 'value', 'num' => 123]);

        $content = $this->getLatestLogContent();
        $this->assertStringContainsString('Test message', $content);
        $this->assertStringContainsString('key', $content);
        $this->assertStringContainsString('value', $content);
    }

    public function testLogWithArrayMessage()
    {
        $this->logger->info(['array' => 'message']);

        $content = $this->getLatestLogContent();
        $this->assertStringContainsString('array', $content);
        $this->assertStringContainsString('message', $content);
    }

    public function testLogWithObjectMessage()
    {
        $obj = new \stdClass();
        $obj->key = 'value';

        $this->logger->info($obj);

        $content = $this->getLatestLogContent();
        $this->assertStringContainsString('key', $content);
        $this->assertStringContainsString('value', $content);
    }

    // ========== 日志格式测试 ==========

    public function testLogFormatContainsTimestamp()
    {
        $this->logger->info('Format test');

        $content = $this->getLatestLogContent();
        // 格式: [YYYY-MM-DD HH:MM:SS] [PID] [LEVEL] MESSAGE
        $this->assertMatchesRegularExpression('/\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\]/', $content);
    }

    public function testLogFormatContainsPid()
    {
        $this->logger->info('PID test');

        $content = $this->getLatestLogContent();
        $this->assertStringContainsString((string) posix_getpid(), $content);
    }

    public function testLogFormatContainsLevel()
    {
        $this->logger->error('Level test');

        $content = $this->getLatestLogContent();
        $this->assertStringContainsString(LogLevel::ERROR, $content);
    }

    // ========== 日志文件命名测试 ==========

    public function testLogFileNamedWithChannelAndDate()
    {
        $this->logger->info('Channel test');

        $logFile = $this->getLatestLogFile();
        $this->assertNotNull($logFile);
        $this->assertStringContainsString('test_', basename($logFile));
        $this->assertStringContainsString(date('Y-m-d'), basename($logFile));
    }

    // ========== PSR-3 接口实现测试 ==========

    public function testImplementsLoggerInterface()
    {
        $this->assertInstanceOf(\Psr\Log\LoggerInterface::class, $this->logger);
    }

    public function testLogMethodExists()
    {
        $this->assertTrue(method_exists($this->logger, 'log'));
    }
}
