<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\bundle\tests;

use Gohany\Circuitbreaker\bundle\DependencyInjection\GohanyCircuitBreakerExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class GohanyCircuitBreakerExtensionTest extends TestCase
{
    public function testLoadWithoutRedisRequiresExplicitStores(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $container = new ContainerBuilder();
        $ext = new GohanyCircuitBreakerExtension();

        $ext->load([
            [
                'default' => [
                    'policy_service' => 'app.policy',
                    'classifier_service' => 'app.classifier',
                    'side_effect_dispatcher_service' => 'app.side_effects',
                ],
            ],
        ], $container);
    }

    public function testLoadWithoutRedisWithExplicitStoresSucceeds(): void
    {
        $container = new ContainerBuilder();
        $ext = new GohanyCircuitBreakerExtension();

        $ext->load([
            [
                'default' => [
                    'policy_service' => 'app.policy',
                    'classifier_service' => 'app.classifier',
                    'side_effect_dispatcher_service' => 'app.side_effects',
                    'state_store_service' => 'app.state_store',
                    'history_store_service' => 'app.history_store',
                    'probe_gate_service' => 'app.probe_gate',
                ],
            ],
        ], $container);

        $this->assertTrue($container->hasDefinition('Gohany.circuitbreaker.default'));
        $this->assertTrue($container->hasDefinition('Gohany.circuitbreaker.registry'));
        $this->assertTrue($container->hasParameter('Gohany.circuitbreaker.resolved_circuits'));

        if (class_exists(\Symfony\Component\Console\Command\Command::class)) {
            $this->assertTrue($container->hasDefinition('Gohany.circuitbreaker.command.debug'));
            $this->assertTrue($container->hasDefinition('Gohany.circuitbreaker.command.sanity'));
        }
    }
}
