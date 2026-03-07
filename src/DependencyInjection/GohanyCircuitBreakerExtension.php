<?php

declare(strict_types=1);

namespace Gohany\CircuitBreakerSymfonyBundle\DependencyInjection;

use Gohany\Circuitbreaker\Bulkhead\LanePolicy;
use Gohany\Circuitbreaker\Bulkhead\PoolPolicy;
use Gohany\Circuitbreaker\Bulkhead\RedisPoolBulkhead;
use Gohany\Circuitbreaker\Resilience\BulkheadMiddleware;
use Gohany\Circuitbreaker\Resilience\CircuitBreakerConfig;
use Gohany\Circuitbreaker\Resilience\CircuitBreakerMiddleware;
use Gohany\Circuitbreaker\Resilience\InMemoryCircuitBreaker;
use Gohany\Circuitbreaker\Resilience\ResiliencePipeline;
use Gohany\Circuitbreaker\Resilience\RetryConfig;
use Gohany\Circuitbreaker\Resilience\RetryMiddleware;
use Gohany\Circuitbreaker\Resilience\RtryRetryMiddleware;
use Gohany\CircuitBreakerSymfonyBundle\Doctrine\DefaultDoctrineLaneResolver;
use Gohany\CircuitBreakerSymfonyBundle\Doctrine\RequestAwareDoctrineLaneResolver;
use Gohany\Rtry\Impl\RtryPolicyFactory;
use Symfony\Component\DependencyInjection\Argument\ServiceLocatorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;

final class GohanyCircuitBreakerExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $profileEnv = $config['profile_env_var'] ?? 'GOHANY_CB_PROFILE';
        $defaultProfile = $config['default_profile'] ?? 'default';
        $active = (string) ($container->getParameterBag()->resolveValue('%env(' . $profileEnv . ')%') ?? '');
        $active = $active !== '' ? $active : $defaultProfile;

        $profiles = $config['profiles'] ?? [];
        if (!isset($profiles[$active])) {
            // If active profile missing, fall back to default_profile
            $active = $defaultProfile;
        }
        $p = $profiles[$active] ?? ['pools' => [], 'pipelines' => [], 'doctrine' => ['enabled' => false]];

        $container->setParameter('gohany_circuitbreaker.active_profile', $active);
        $container->setParameter('gohany_circuitbreaker.key_prefix', $config['key_prefix']);
        $container->setParameter('gohany_circuitbreaker.bypass_deny_block', $config['bypass_deny_block'] ?? false);

        // Pools
        $poolServiceIds = [];
        foreach (($p['pools'] ?? []) as $poolId => $poolCfg) {
            $policy = $this->createPoolPolicyDefinition((string) $poolId, $poolCfg);

            $def = new Definition(RedisPoolBulkhead::class);
            $def->setArguments([
                new Reference($config['redis_client_service']),
                $policy,
                '%gohany_circuitbreaker.key_prefix%',
                new Reference('gohany.circuitbreaker.emitter'),
            ]);
            $svcId = 'gohany.circuitbreaker.bulkhead.pool.' . $poolId;
            $container->setDefinition($svcId, $def);
            $poolServiceIds[$poolId] = $svcId;
        }

        // A ServiceLocator for pool lookup by id
        $container->setDefinition('gohany.circuitbreaker.bulkhead_pool_locator', new Definition('Symfony\\Component\\DependencyInjection\\ServiceLocator', [
            new ServiceLocatorArgument(array_map(function (string $svcId) {
                return new Reference($svcId);
            }, $poolServiceIds)),
        ]));

        // Circuit breaker default (in-memory). You can replace with a Redis-backed implementation later.
        if (!$container->hasDefinition('gohany.circuitbreaker.circuit_breaker')) {
            $cbCfg = new Definition(CircuitBreakerConfig::class);
            $cbCfg->setPublic(false);
            $container->setDefinition('gohany.circuitbreaker.circuit_breaker_config', $cbCfg);

            $cb = new Definition(InMemoryCircuitBreaker::class);
            $cb->setArguments([
                'default',
                new Reference('gohany.circuitbreaker.circuit_breaker_config'),
                new Reference('gohany.circuitbreaker.emitter'),
            ]);
            $container->setDefinition('gohany.circuitbreaker.circuit_breaker', $cb);
        }

        // Pipelines
        foreach (($p['pipelines'] ?? []) as $name => $pipeCfg) {
            $stageDefs = [];
            foreach (($pipeCfg['stages'] ?? []) as $stage) {
                if ($stage['type'] === 'bulkhead') {
                    $poolId = (string) ($stage['pool'] ?? '');
                    $svcId = $poolServiceIds[$poolId] ?? null;
                    if ($svcId === null) {
                        throw new \InvalidArgumentException('Unknown bulkhead pool id: ' . $poolId);
                    }
                    $stageDefs[] = new Definition(BulkheadMiddleware::class, [
                        new Reference($svcId),
                        new Reference('gohany.circuitbreaker.emitter'),
                    ]);
                } elseif ($stage['type'] === 'circuit_breaker') {
                    $stageDefs[] = new Definition(CircuitBreakerMiddleware::class, [
                        new Reference('gohany.circuitbreaker.circuit_breaker'),
                        new Reference('gohany.circuitbreaker.emitter'),
                    ]);
                } elseif ($stage['type'] === 'retry') {
                    $retry = $stage['retry'] ?? null;

                    // String form: gohany/rtry spec string (e.g. `rtry:attempts=3;delay=50ms`).
                    if (is_string($retry) && trim($retry) !== '') {
                        // Fail fast on invalid literal specs.
                        // `%env(...)%` placeholders are resolved at runtime and cannot be parsed here.
                        if (!$this->isEnvPlaceholder($retry)) {
                            (new RtryPolicyFactory())->fromSpec($retry);
                        }

                        $stageDefs[] = new Definition(RtryRetryMiddleware::class, [
                            $retry,
                            new Reference('gohany.circuitbreaker.emitter'),
                        ]);
                        continue;
                    }

                    // Map form: bundle-native exponential backoff settings.
                    $retry = is_array($retry) ? $retry : [];

                    $rc = new Definition(RetryConfig::class);
                    $rc->setPublic(false);
                    $rc->setProperties([
                        'maxAttempts' => (int) ($retry['max_attempts'] ?? 3),
                        'baseDelayMs' => (int) ($retry['base_delay_ms'] ?? 50),
                        'maxDelayMs' => (int) ($retry['max_delay_ms'] ?? 1000),
                        'jitter' => (bool) ($retry['jitter'] ?? true),
                    ]);
                    $stageDefs[] = new Definition(RetryMiddleware::class, [
                        $rc,
                        new Reference('gohany.circuitbreaker.emitter'),
                    ]);
                }
            }

            $pipelineDef = new Definition(ResiliencePipeline::class, [$stageDefs]);
            $pipelineSvcId = 'gohany.circuitbreaker.pipeline.' . $name;
            $container->setDefinition($pipelineSvcId, $pipelineDef);
        }

        // Minimal default emitter (decorate/replace in app)
        if (!$container->hasDefinition('gohany.circuitbreaker.emitter')) {
            $container->register('gohany.circuitbreaker.emitter', \Gohany\CircuitBreaker\Observability\NullEmitter::class);
        }

        // Doctrine DBAL middleware wiring (optional)
        if (($p['doctrine']['enabled'] ?? false) === true) {
            $configuredConnections = $p['doctrine']['connections'] ?? [];
            $legacyConnection = $p['doctrine']['connection'] ?? null;

            if ($configuredConnections !== [] && $legacyConnection !== null && function_exists('trigger_deprecation')) {
                trigger_deprecation(
                    'gohany/circuitbreaker-symfony-bundle',
                    '1.2',
                    'Config option "gohany_circuitbreaker.profiles.<profile>.doctrine.connection" is deprecated, use "connections" instead.'
                );
            }

            $connections = $configuredConnections !== []
                ? array_values($configuredConnections)
                : ($legacyConnection !== null ? [(string) $legacyConnection] : ['default']);

            $doctrineDefaults = [
                'connect_pipeline' => $p['doctrine']['connect_pipeline'],
                'query_pipeline' => $p['doctrine']['query_pipeline'],
                'connect_lane' => $p['doctrine']['connect_lane'],
                'query_lane' => $p['doctrine']['query_lane'],
            ];

            $connectionSettings = $p['doctrine']['connection_settings'] ?? [];

            $container->setParameter('gohany_circuitbreaker.doctrine.connection', $connections[0]);
            $container->setParameter('gohany_circuitbreaker.doctrine.connections', $connections);
            $container->setParameter('gohany_circuitbreaker.doctrine.connect_pipeline', $p['doctrine']['connect_pipeline']);
            $container->setParameter('gohany_circuitbreaker.doctrine.query_pipeline', $p['doctrine']['query_pipeline']);
            $container->setParameter('gohany_circuitbreaker.doctrine.connect_lane', $p['doctrine']['connect_lane']);
            $container->setParameter('gohany_circuitbreaker.doctrine.query_lane', $p['doctrine']['query_lane']);

            foreach ($connections as $connectionName) {
                $overrides = $connectionSettings[$connectionName] ?? [];
                $connectionConfig = array_filter(
                    is_array($overrides) ? $overrides : [],
                    static function ($value): bool {
                        return $value !== null;
                    }
                );
                $resolved = array_merge($doctrineDefaults, $connectionConfig);
                $laneResolverServiceId = sprintf('gohany.circuitbreaker.doctrine.lane_resolver.%s', $connectionName);
                $this->registerDoctrineLaneResolverForConnection(
                    $container,
                    $laneResolverServiceId,
                    $resolved['connect_pipeline'],
                    $resolved['query_pipeline'],
                    $resolved['connect_lane'],
                    $resolved['query_lane'],
                    $p['doctrine']['routing_lanes'] ?? []
                );

                $this->registerDoctrineMiddlewareForConnection(
                    $container,
                    (string) $connectionName,
                    $laneResolverServiceId
                );
            }
        }

        // Optional Symfony HttpKernel integration: bulkhead around controllers when #[Bulkhead] is present.
        if (class_exists('Symfony\\Component\\HttpKernel\\KernelEvents')) {
            $container->setDefinition('gohany.circuitbreaker.http.bulkhead_controller_subscriber', new Definition(
                \Gohany\CircuitBreakerSymfonyBundle\Http\EventSubscriber\BulkheadControllerSubscriber::class,
                [new Reference('gohany.circuitbreaker.bulkhead_pool_locator')]
            ))->addTag('kernel.event_subscriber');
        }
    }

    private function registerDoctrineMiddlewareForConnection(
        ContainerBuilder $container,
        string $connectionName,
        string $laneResolverServiceId
    ): void {
        $mw = new Definition(\Gohany\CircuitBreakerSymfonyBundle\Doctrine\ResilientDbalMiddleware::class);
        $mw->setArguments([
            new Reference('service_container'),
            new Reference('gohany.circuitbreaker.emitter'),
            new Reference($laneResolverServiceId),
            '%gohany_circuitbreaker.bypass_deny_block%',
        ]);
        $mw->addTag('doctrine.dbal.middleware', ['connection' => $connectionName]);

        $serviceId = sprintf('gohany.circuitbreaker.doctrine.dbal_middleware.%s', $connectionName);
        $container->setDefinition($serviceId, $mw);
    }

    /**
     * @param array<string,mixed> $poolCfg
     */
    private function createPoolPolicyDefinition(string $poolId, array $poolCfg): Definition
    {
        $mode = (string) ($poolCfg['mode'] ?? 'weighted');
        $laneDefinitions = [];
        foreach (($poolCfg['lanes'] ?? []) as $laneName => $laneCfg) {
            $laneDefinitions[$laneName] = $this->createLanePolicyDefinition(
                (string) $laneName,
                $mode,
                is_array($laneCfg) ? $laneCfg : []
            );
        }

        return new Definition(PoolPolicy::class, [
            $poolId,
            (int) ($poolCfg['global_max'] ?? 0),
            $mode,
            (float) ($poolCfg['soft_borrow_utilization_threshold'] ?? 0.8),
            $laneDefinitions,
        ]);
    }

    /**
     * @param array<string,mixed> $laneCfg
     */
    private function createLanePolicyDefinition(string $laneName, string $mode, array $laneCfg): Definition
    {
        if ($mode === 'fixed') {
            return (new Definition(LanePolicy::class))
                ->setFactory([LanePolicy::class, 'fixed'])
                ->setArguments([$laneName, (int) ($laneCfg['max_concurrent'] ?? 1)]);
        }

        if ($mode === 'percent') {
            return (new Definition(LanePolicy::class))
                ->setFactory([LanePolicy::class, 'percent'])
                ->setArguments([$laneName, (float) ($laneCfg['percent'] ?? 0.1)]);
        }

        return (new Definition(LanePolicy::class))
            ->setFactory([LanePolicy::class, 'weight'])
            ->setArguments([$laneName, (int) ($laneCfg['weight'] ?? 1)]);
    }

    /**
     * @param array<string,mixed> $routingLanes
     */
    private function registerDoctrineLaneResolverForConnection(
        ContainerBuilder $container,
        string $serviceId,
        ?string $connectPipeline,
        ?string $queryPipeline,
        string $connectLane,
        string $queryLane,
        array $routingLanes
    ): void {
        $baseResolverId = $serviceId . '.default';
        $baseResolver = new Definition(DefaultDoctrineLaneResolver::class, [
            $connectPipeline,
            $queryPipeline,
            $connectLane,
            $queryLane,
        ]);
        $container->setDefinition($baseResolverId, $baseResolver);

        $parentLaneMap = $routingLanes['parent_lane_map'] ?? [];
        $childLaneMap = $routingLanes['child_lane_map'] ?? [];
        $hasRouting = is_array($parentLaneMap) && $parentLaneMap !== []
            || is_array($childLaneMap) && $childLaneMap !== [];

        if (!$hasRouting || !$container->hasDefinition('request_stack')) {
            $container->setAlias($serviceId, $baseResolverId);
            return;
        }

        $requestResolver = new Definition(RequestAwareDoctrineLaneResolver::class, [
            new Reference($baseResolverId),
            new Reference('request_stack'),
            $routingLanes['parent_pipeline'] ?? null,
            $routingLanes['child_pipeline'] ?? null,
            is_array($parentLaneMap) ? $parentLaneMap : [],
            is_array($childLaneMap) ? $childLaneMap : [],
        ]);
        $container->setDefinition($serviceId, $requestResolver);
    }

    private function isEnvPlaceholder(string $value): bool
    {
        return preg_match('/^%env\([^)]+\)%$/', trim($value)) === 1;
    }
}
