<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\bundle\tests;

use Gohany\Circuitbreaker\bundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;

final class ConfigurationTreeTest extends TestCase
{
    public function testMinimalConfigProcesses(): void
    {
        $processor = new Processor();
        $cfg = $processor->processConfiguration(new Configuration(), [[
            'redis' => [
                'client_service' => 'app.redis_client',
            ],
            'default' => [
                'policy_service' => 'app.policy',
                'classifier_service' => 'app.classifier',
                'side_effect_dispatcher_service' => 'app.side_effects',
            ],
        ]]);

        $this->assertSame('app.redis_client', $cfg['redis']['client_service']);
        $this->assertSame('cb', $cfg['redis']['key_prefix']);
        $this->assertSame('app.policy', $cfg['default']['policy_service']);
    }

    public function testConfigProcessesWithoutRedis(): void
    {
        $processor = new Processor();
        $cfg = $processor->processConfiguration(new Configuration(), [[
            'default' => [
                'policy_service' => 'app.policy',
                'classifier_service' => 'app.classifier',
                'side_effect_dispatcher_service' => 'app.side_effects',
                'state_store_service' => 'app.state_store',
                'history_store_service' => 'app.history_store',
                'probe_gate_service' => 'app.probe_gate',
            ],
        ]]);

        $this->assertNull($cfg['redis']['client_service']);
        $this->assertSame('app.state_store', $cfg['default']['state_store_service']);
    }
}
