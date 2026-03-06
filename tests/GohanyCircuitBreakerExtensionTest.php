<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\bundle\tests;

use Gohany\CircuitBreakerSymfonyBundle\DependencyInjection\GohanyCircuitBreakerExtension;
use Gohany\Circuitbreaker\Resilience\RtryRetryMiddleware;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class GohanyCircuitBreakerExtensionTest extends TestCase
{
    public function testLoadMinimalConfigSucceeds(): void
    {
        $container = new ContainerBuilder();
        $ext = new GohanyCircuitBreakerExtension();

        $_ENV['GOHANY_CB_PROFILE'] = 'default';
        putenv('GOHANY_CB_PROFILE=default');

        $ext->load([
            [
                'profiles' => [
                    'default' => [],
                ],
            ],
        ], $container);

        $this->assertSame('default', $container->getParameter('gohany_circuitbreaker.active_profile'));
        $this->assertSame('cb', $container->getParameter('gohany_circuitbreaker.key_prefix'));
        $this->assertTrue($container->hasDefinition('gohany.circuitbreaker.emitter'));
    }

    public function testRetryStageAcceptsRtrySpecString(): void
    {
        $container = new ContainerBuilder();
        $ext = new GohanyCircuitBreakerExtension();

        $_ENV['GOHANY_CB_PROFILE'] = 'default';
        putenv('GOHANY_CB_PROFILE=default');

        $ext->load([
            [
                'profiles' => [
                    'default' => [
                        'pipelines' => [
                            'doctrine_connect' => [
                                'stages' => [
                                    [
                                        'type' => 'retry',
                                        'retry' => 'rtry:a=2;d=25ms;cap=200ms;j=50%',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ], $container);

        $pipeline = $container->getDefinition('gohany.circuitbreaker.pipeline.doctrine_connect');
        $stageDefs = $pipeline->getArgument(0);
        $this->assertIsArray($stageDefs);
        $this->assertCount(1, $stageDefs);
        $this->assertSame(RtryRetryMiddleware::class, $stageDefs[0]->getClass());
    }

    public function testInvalidRtrySpecStringThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $container = new ContainerBuilder();
        $ext = new GohanyCircuitBreakerExtension();

        $_ENV['GOHANY_CB_PROFILE'] = 'default';
        putenv('GOHANY_CB_PROFILE=default');

        $ext->load([
            [
                'profiles' => [
                    'default' => [
                        'pipelines' => [
                            'doctrine_connect' => [
                                'stages' => [
                                    [
                                        'type' => 'retry',
                                        'retry' => 'rtry:THIS_IS_NOT_VALID',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ], $container);
    }

    public function testDoctrineRegistersMiddlewarePerConfiguredConnection(): void
    {
        $container = new ContainerBuilder();
        $ext = new GohanyCircuitBreakerExtension();

        $_ENV['GOHANY_CB_PROFILE'] = 'default';
        putenv('GOHANY_CB_PROFILE=default');

        $ext->load([
            [
                'profiles' => [
                    'default' => [
                        'doctrine' => [
                            'enabled' => true,
                            'connections' => ['default', 'readonly'],
                        ],
                    ],
                ],
            ],
        ], $container);

        $this->assertSame(['default', 'readonly'], $container->getParameter('gohany_circuitbreaker.doctrine.connections'));
        $this->assertTrue($container->hasDefinition('gohany.circuitbreaker.doctrine.dbal_middleware.default'));
        $this->assertTrue($container->hasDefinition('gohany.circuitbreaker.doctrine.dbal_middleware.readonly'));

        $defaultTags = $container->getDefinition('gohany.circuitbreaker.doctrine.dbal_middleware.default')->getTag('doctrine.dbal.middleware');
        $readonlyTags = $container->getDefinition('gohany.circuitbreaker.doctrine.dbal_middleware.readonly')->getTag('doctrine.dbal.middleware');

        $this->assertSame('default', $defaultTags[0]['connection'] ?? null);
        $this->assertSame('readonly', $readonlyTags[0]['connection'] ?? null);
    }

    public function testDoctrineLegacyConnectionConfigIsStillSupported(): void
    {
        $container = new ContainerBuilder();
        $ext = new GohanyCircuitBreakerExtension();

        $_ENV['GOHANY_CB_PROFILE'] = 'default';
        putenv('GOHANY_CB_PROFILE=default');

        $ext->load([
            [
                'profiles' => [
                    'default' => [
                        'doctrine' => [
                            'enabled' => true,
                            'connection' => 'legacy_conn',
                        ],
                    ],
                ],
            ],
        ], $container);

        $this->assertSame('legacy_conn', $container->getParameter('gohany_circuitbreaker.doctrine.connection'));
        $this->assertSame(['legacy_conn'], $container->getParameter('gohany_circuitbreaker.doctrine.connections'));
        $this->assertTrue($container->hasDefinition('gohany.circuitbreaker.doctrine.dbal_middleware.legacy_conn'));
    }
}
