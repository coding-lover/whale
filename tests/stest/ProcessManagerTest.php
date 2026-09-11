<?php

namespace Sikelan\Tests\Stest;

use PHPUnit\Framework\TestCase;
use Sikelan\Core\Container;
use Sikelan\Core\Config;
use Sikelan\Core\Logger;
use Sikelan\Process\ProcessManager;

/**
 * ProcessManager 全覆盖测试
 */
class ProcessManagerTest extends TestCase
{
    private Container $container;
    private Logger $logger;
    private ProcessManager $processManager;

    protected function setUp(): void
    {
        $this->container = new Container();

        $config = new Config();
        $config->set('app.log_level', 'debug');
        $config->set('app.log_path', sys_get_temp_dir());
        $config->set('app.log_channel', 'test');

        $this->logger = new Logger($config);
        $this->processManager = new ProcessManager($this->container, $this->logger);
    }

    public function testConstructorSetsDependencies()
    {
        $reflection = new \ReflectionClass($this->processManager);
        $containerProp = $reflection->getProperty('container');
        $loggerProp = $reflection->getProperty('logger');

        $containerProp->setAccessible(true);
        $loggerProp->setAccessible(true);

        $this->assertSame($this->container, $containerProp->getValue($this->processManager));
        $this->assertSame($this->logger, $loggerProp->getValue($this->processManager));
    }

    public function testAddProcess()
    {
        $result = $this->processManager->addProcess('test_process', function ($worker) {
        });

        $this->assertSame($this->processManager, $result); // 支持链式调用

        $reflection = new \ReflectionClass($this->processManager);
        $prop = $reflection->getProperty('processes');
        $prop->setAccessible(true);
        $processes = $prop->getValue($this->processManager);

        $this->assertArrayHasKey('test_process', $processes);
    }

    public function testAddProcessWithOptions()
    {
        $this->processManager->addProcess(
            'test_process',
            function ($worker) {
            },
            true,  // redirectStdinStdout
            2      // pipeType
        );

        $reflection = new \ReflectionClass($this->processManager);
        $prop = $reflection->getProperty('processes');
        $prop->setAccessible(true);
        $processes = $prop->getValue($this->processManager);

        $this->assertArrayHasKey('test_process', $processes);
        $this->assertInstanceOf(\Swoole\Process::class, $processes['test_process']);
    }

    public function testGetProcess()
    {
        $this->processManager->addProcess('test_process', function ($worker) {
        });

        $process = $this->processManager->getProcess('test_process');

        $this->assertInstanceOf(\Swoole\Process::class, $process);
    }

    public function testGetProcessReturnsNullForNonExistent()
    {
        $process = $this->processManager->getProcess('non_existent');

        $this->assertNull($process);
    }

    public function testGetAllProcesses()
    {
        $this->processManager->addProcess('process1', function ($worker) {
        });
        $this->processManager->addProcess('process2', function ($worker) {
        });

        $processes = $this->processManager->getAllProcesses();

        $this->assertCount(2, $processes);
        $this->assertArrayHasKey('process1', $processes);
        $this->assertArrayHasKey('process2', $processes);
    }

    public function testStartThrowsExceptionForNonExistent()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Process non_existent not found');

        $this->processManager->start('non_existent');
    }

    public function testStopThrowsExceptionForNonExistent()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Process non_existent not found');

        $this->processManager->stop('non_existent');
    }

    public function testAllPublicMethodsExist()
    {
        $expectedMethods = ['addProcess', 'start', 'startAll', 'stop', 'stopAll', 'getProcess', 'getAllProcesses'];

        foreach ($expectedMethods as $method) {
            $this->assertTrue(
                method_exists($this->processManager, $method),
                "Method {$method} should exist"
            );
        }
    }

    public function testAddProcessMethodSignature()
    {
        $method = new \ReflectionMethod($this->processManager, 'addProcess');
        $params = $method->getParameters();

        $this->assertCount(4, $params);
        $this->assertEquals('name', $params[0]->getName());
        $this->assertEquals('callback', $params[1]->getName());
        $this->assertEquals('redirectStdinStdout', $params[2]->getName());
        $this->assertEquals('pipeType', $params[3]->getName());
        $this->assertTrue($params[2]->isDefaultValueAvailable() && $params[2]->getDefaultValue() === false);
        $this->assertTrue($params[3]->isDefaultValueAvailable() && $params[3]->getDefaultValue() === 2);
    }

    public function testStartMethodSignature()
    {
        $method = new \ReflectionMethod($this->processManager, 'start');
        $params = $method->getParameters();

        $this->assertCount(1, $params);
        $this->assertEquals('name', $params[0]->getName());
    }

    public function testStopMethodSignature()
    {
        $method = new \ReflectionMethod($this->processManager, 'stop');
        $params = $method->getParameters();

        $this->assertCount(1, $params);
        $this->assertEquals('name', $params[0]->getName());
    }

    public function testProcessesInitiallyEmpty()
    {
        $reflection = new \ReflectionClass($this->processManager);
        $prop = $reflection->getProperty('processes');
        $prop->setAccessible(true);

        $this->assertEmpty($prop->getValue($this->processManager));
    }
}
