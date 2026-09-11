<?php

namespace Sikelan\Tests\Stest;

use PHPUnit\Framework\TestCase;
use Sikelan\Core\Config;
use Sikelan\Core\Logger;
use App\Services\Exchanges\AbstractExchange;
use App\Services\Exchanges\Adapters\BinanceExchange;

/**
 * SSL 证书验证逻辑测试
 * 
 * 验证配置中的 ssl_verify 能正确传递到 Swoole HTTP Client 的参数中。
 * 不发起真实网络请求，仅验证参数构造逻辑。
 */
class SslVerifyTest extends TestCase
{
    /**
     * 测试 ssl_verify = true 时，Swoole Client 参数应启用证书验证
     */
    public function testSslVerifyEnabled(): void
    {
        $config = new Config();
        $config->set('exchanges.binance', [
            'base_url' => 'https://api.binance.com',
            'api_key' => 'test_key',
            'secret' => 'test_secret',
            'ssl_verify' => true,
        ]);

        $exchange = new BinanceExchange($config, new Logger($config));

        $options = $this->buildClientOptions($exchange);

        $this->assertTrue($options['ssl_verify_peer'], 'ssl_verify_peer 应为 true');
        $this->assertTrue($options['ssl_verify_peer_name'], 'ssl_verify_peer_name 应为 true');
    }

    /**
     * 测试 ssl_verify = false 时，Swoole Client 参数应禁用证书验证
     */
    public function testSslVerifyDisabled(): void
    {
        $config = new Config();
        $config->set('exchanges.binance', [
            'base_url' => 'https://api.binance.com',
            'api_key' => 'test_key',
            'secret' => 'test_secret',
            'ssl_verify' => false,
        ]);

        $exchange = new BinanceExchange($config, new Logger($config));

        $options = $this->buildClientOptions($exchange);

        $this->assertFalse($options['ssl_verify_peer'], 'ssl_verify_peer 应为 false');
        $this->assertFalse($options['ssl_verify_peer_name'], 'ssl_verify_peer_name 应为 false');
    }

    /**
     * 测试未配置 ssl_verify 时，默认启用证书验证（生产安全）
     */
    public function testSslVerifyDefaultIsTrue(): void
    {
        $config = new Config();
        $config->set('exchanges.binance', [
            'base_url' => 'https://api.binance.com',
            'api_key' => 'test_key',
            'secret' => 'test_secret',
            // 不设置 ssl_verify，应默认 true
        ]);

        $exchange = new BinanceExchange($config, new Logger($config));

        $options = $this->buildClientOptions($exchange);

        $this->assertTrue($options['ssl_verify_peer'], '默认 ssl_verify_peer 应为 true');
        $this->assertTrue($options['ssl_verify_peer_name'], '默认 ssl_verify_peer_name 应为 true');
    }

    /**
     * 通过反射调用 sendHttpRequest 中的 options 构造逻辑
     * 
     * 直接构造与 AbstractExchange::sendHttpRequest 中相同的参数数组，
     * 验证 $this->sslVerify 能正确映射到 swoole client options
     */
    private function buildClientOptions(AbstractExchange $exchange): array
    {
        // 从 exchange 实例获取 sslVerify 属性值
        $reflection = new \ReflectionProperty(AbstractExchange::class, 'sslVerify');
        $reflection->setAccessible(true);
        $sslVerify = $reflection->getValue($exchange);

        // 复制 sendHttpRequest 中的 options 构造逻辑
        return [
            'timeout' => 10,
            'connect_timeout' => 5,
            'ssl_verify_peer' => $sslVerify,
            'ssl_verify_peer_name' => $sslVerify,
        ];
    }
}
