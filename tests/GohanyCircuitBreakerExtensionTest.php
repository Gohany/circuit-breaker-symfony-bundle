<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\bundle\tests;

use Gohany\CircuitBreakerSymfonyBundle\DependencyInjection\GohanyCircuitBreakerExtension;
use Symfony\Component\DependencyInjection\Reference;
use Gohany\Circuitbreaker\Resilience\RtryRetryMiddleware;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

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

    public function testRetryStageAcceptsEnvRtrySpecString(): void
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
                                        'retry' => '%env(CB_RETRY_SPEC)%',
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
        $this->assertSame('%env(CB_RETRY_SPEC)%', $stageDefs[0]->getArgument(0));
    }

    public function testRetryStageAcceptsTypedEnvRtrySpecString(): void
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
                            'doctrine_query' => [
                                'stages' => [
                                    [
                                        'type' => 'retry',
                                        'retry' => '%env(string:HYDRA_RETRY_SPEC)%',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ], $container);

        $pipeline = $container->getDefinition('gohany.circuitbreaker.pipeline.doctrine_query');
        $stageDefs = $pipeline->getArgument(0);

        $this->assertIsArray($stageDefs);
        $this->assertCount(1, $stageDefs);
        $this->assertSame(RtryRetryMiddleware::class, $stageDefs[0]->getClass());
        $this->assertSame('%env(string:HYDRA_RETRY_SPEC)%', $stageDefs[0]->getArgument(0));
    }

    public function testPoolPoliciesAreWiredAsDefinitions(): void
    {
        $container = new ContainerBuilder();
        $ext = new GohanyCircuitBreakerExtension();

        $_ENV['GOHANY_CB_PROFILE'] = 'default';
        putenv('GOHANY_CB_PROFILE=default');

        $ext->load([
            [
                'profiles' => [
                    'default' => [
                        'pools' => [
                            'odin_http' => [
                                'global_max' => 100,
                                'mode' => 'weighted',
                                'lanes' => [
                                    'odin.http' => ['weight' => 3],
                                    'odin.fraud' => ['weight' => 1],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ], $container);

        $poolDef = $container->getDefinition('gohany.circuitbreaker.bulkhead.pool.odin_http');
        $policyArg = $poolDef->getArgument(1);

        $this->assertInstanceOf(Definition::class, $policyArg);
        $this->assertSame('Gohany\\Circuitbreaker\\Bulkhead\\PoolPolicy', $policyArg->getClass());

        $laneDefs = $policyArg->getArgument(4);
        $this->assertIsArray($laneDefs);
        $this->assertInstanceOf(Definition::class, $laneDefs['odin.http']);
        $this->assertSame(['Gohany\\Circuitbreaker\\Bulkhead\\LanePolicy', 'weight'], $laneDefs['odin.http']->getFactory());
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
        $defaultMiddlewareArgs = $container->getDefinition('gohany.circuitbreaker.doctrine.dbal_middleware.default')->getArguments();

        $this->assertSame('default', $defaultTags[0]['connection'] ?? null);
        $this->assertSame('readonly', $readonlyTags[0]['connection'] ?? null);
        $this->assertSame('%gohany_circuitbreaker.bypass_deny_block%', $defaultMiddlewareArgs[3]);
        $this->assertFalse($container->getParameter('gohany_circuitbreaker.bypass_deny_block'));
    }

    public function testDoctrineMiddlewareAcceptsEnvBypassDenyBlockFlag(): void
    {
        $container = new ContainerBuilder();
        $ext = new GohanyCircuitBreakerExtension();

        $_ENV['GOHANY_CB_PROFILE'] = 'default';
        putenv('GOHANY_CB_PROFILE=default');

        $ext->load([
            [
                'bypass_deny_block' => '%env(bool:CB_BYPASS_DENY_BLOCK)%',
                'profiles' => [
                    'default' => [
                        'doctrine' => [
                            'enabled' => true,
                            'connections' => ['default'],
                        ],
                    ],
                ],
            ],
        ], $container);

        $args = $container->getDefinition('gohany.circuitbreaker.doctrine.dbal_middleware.default')->getArguments();

        $this->assertSame('%gohany_circuitbreaker.bypass_deny_block%', $args[3]);
        $this->assertSame('%env(bool:CB_BYPASS_DENY_BLOCK)%', $container->getParameter('gohany_circuitbreaker.bypass_deny_block'));
    }

    public function testDoctrineEnabledAcceptsTypedEnvBoolFlag(): void
    {
        $container = new ContainerBuilder();
        $ext = new GohanyCircuitBreakerExtension();

        $_ENV['GOHANY_CB_PROFILE'] = 'default';
        putenv('GOHANY_CB_PROFILE=default');
        $_ENV['DOCTRINE_CB_ENABLED'] = '1';
        putenv('DOCTRINE_CB_ENABLED=1');

        $ext->load([
            [
                'profiles' => [
                    'default' => [
                        'doctrine' => [
                            'enabled' => '%env(bool:DOCTRINE_CB_ENABLED)%',
                            'connections' => ['default'],
                        ],
                    ],
                ],
            ],
        ], $container);

        $this->assertTrue($container->hasDefinition('gohany.circuitbreaker.doctrine.dbal_middleware.default'));
    }

    public function testDoctrineAppliesPerConnectionSettingsOverrides(): void
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
                            'connections' => ['default', 'read'],
                            'connect_pipeline' => 'db_connect',
                            'query_pipeline' => 'db_query',
                            'connect_lane' => 'db.connect.default',
                            'query_lane' => 'db.query.default',
                            'connection_settings' => [
                                'read' => [
                                    'connect_lane' => 'db.connect.read',
                                    'query_lane' => 'db.query.read',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ], $container);

        $defaultResolver = $container->getDefinition('gohany.circuitbreaker.doctrine.lane_resolver.default.default')->getArguments();
        $readResolver = $container->getDefinition('gohany.circuitbreaker.doctrine.lane_resolver.read.default')->getArguments();

        $this->assertSame('db_connect', $defaultResolver[0]);
        $this->assertSame('db_query', $defaultResolver[1]);
        $this->assertSame('db.connect.default', $defaultResolver[2]);
        $this->assertSame('db.query.default', $defaultResolver[3]);

        $this->assertSame('db_connect', $readResolver[0]);
        $this->assertSame('db_query', $readResolver[1]);
        $this->assertSame('db.connect.read', $readResolver[2]);
        $this->assertSame('db.query.read', $readResolver[3]);
    }

    public function testDoctrineRoutingLanesUseRequestAwareResolverWhenRequestStackIsAvailable(): void
    {
        $container = new ContainerBuilder();
        $container->register('request_stack', 'Symfony\\Component\\HttpFoundation\\RequestStack');

        $ext = new GohanyCircuitBreakerExtension();

        $_ENV['GOHANY_CB_PROFILE'] = 'default';
        putenv('GOHANY_CB_PROFILE=default');

        $ext->load([
            [
                'profiles' => [
                    'default' => [
                        'doctrine' => [
                            'enabled' => true,
                            'connections' => ['default'],
                            'routing_lanes' => [
                                'parent_pipeline' => 'db_query',
                                'parent_lane_map' => ['^hydra_' => 'hydra'],
                                'child_pipeline' => 'db_query',
                                'child_lane_map' => ['^hydra_charges_' => 'hydra.charges'],
                            ],
                        ],
                    ],
                ],
            ],
        ], $container);

        $resolverDef = $container->getDefinition('gohany.circuitbreaker.doctrine.lane_resolver.default');
        $this->assertSame('Gohany\\CircuitBreakerSymfonyBundle\\Doctrine\\RequestAwareDoctrineLaneResolver', $resolverDef->getClass());

        $args = $resolverDef->getArguments();
        $this->assertInstanceOf(Reference::class, $args[0]);
        $this->assertSame('gohany.circuitbreaker.doctrine.lane_resolver.default.default', (string) $args[0]);
        $this->assertSame('db_query', $args[2]);
        $this->assertSame('db_query', $args[3]);
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
