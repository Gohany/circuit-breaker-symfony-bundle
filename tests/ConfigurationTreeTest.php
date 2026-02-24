<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\bundle\tests;

use Gohany\CircuitBreakerSymfonyBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;

final class ConfigurationTreeTest extends TestCase
{
    public function testMinimalConfigProcesses(): void
    {
        $processor = new Processor();
        $cfg = $processor->processConfiguration(new Configuration(), [[
            'profiles' => [
                'default' => [],
            ],
        ]]);

        $this->assertSame('GOHANY_CB_PROFILE', $cfg['profile_env_var']);
        $this->assertSame('default', $cfg['default_profile']);
        $this->assertSame('gohany.circuitbreaker.redis_client', $cfg['redis_client_service']);
        $this->assertSame('cb', $cfg['key_prefix']);
    }

    public function testConfigProcessesWithCustomRedisClientService(): void
    {
        $processor = new Processor();
        $cfg = $processor->processConfiguration(new Configuration(), [[
            'redis_client_service' => 'app.redis_client',
            'profiles' => [
                'default' => [],
            ],
        ]]);

        $this->assertSame('app.redis_client', $cfg['redis_client_service']);
    }
}
