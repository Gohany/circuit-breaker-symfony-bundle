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
use Gohany\Rtry\Impl\RtryPolicyFactory;
use Symfony\Component\Config\FileLocator;
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

        // Pools
        $poolServiceIds = [];
        foreach (($p['pools'] ?? []) as $poolId => $poolCfg) {
            $lanes = [];
            foreach (($poolCfg['lanes'] ?? []) as $laneName => $laneCfg) {
                if ($poolCfg['mode'] === 'fixed') {
                    $lanes[$laneName] = LanePolicy::fixed($laneName, (int) ($laneCfg['max_concurrent'] ?? 1));
                } elseif ($poolCfg['mode'] === 'percent') {
                    $lanes[$laneName] = LanePolicy::percent($laneName, (float) ($laneCfg['percent'] ?? 0.1));
                } else {
                    $lanes[$laneName] = LanePolicy::weight($laneName, (int) ($laneCfg['weight'] ?? 1));
                }
            }

            $policy = new PoolPolicy(
                (string) $poolId,
                (int) $poolCfg['global_max'],
                (string) $poolCfg['mode'],
                (float) $poolCfg['soft_borrow_utilization_threshold'],
                $lanes
            );

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
                        // Fail fast on invalid specs.
                        (new RtryPolicyFactory())->fromSpec($retry);

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
            $container->setParameter('gohany_circuitbreaker.doctrine.connection', $p['doctrine']['connection']);
            $container->setParameter('gohany_circuitbreaker.doctrine.connect_pipeline', $p['doctrine']['connect_pipeline']);
            $container->setParameter('gohany_circuitbreaker.doctrine.query_pipeline', $p['doctrine']['query_pipeline']);
            $container->setParameter('gohany_circuitbreaker.doctrine.connect_lane', $p['doctrine']['connect_lane']);
            $container->setParameter('gohany_circuitbreaker.doctrine.query_lane', $p['doctrine']['query_lane']);

            $mw = new Definition(\Gohany\CircuitBreakerSymfonyBundle\Doctrine\ResilientDbalMiddleware::class);
            $mw->setArguments([
                new Reference('service_container'),
                new Reference('gohany.circuitbreaker.emitter'),
                '%gohany_circuitbreaker.doctrine.connect_pipeline%',
                '%gohany_circuitbreaker.doctrine.query_pipeline%',
                '%gohany_circuitbreaker.doctrine.connect_lane%',
                '%gohany_circuitbreaker.doctrine.query_lane%',
            ]);
            $mw->addTag('doctrine.dbal.middleware');
            $container->setDefinition('gohany.circuitbreaker.doctrine.dbal_middleware', $mw);
        }

        // Optional Symfony HttpKernel integration: bulkhead around controllers when #[Bulkhead] is present.
        if (class_exists('Symfony\\Component\\HttpKernel\\KernelEvents')) {
            $container->setDefinition('gohany.circuitbreaker.http.bulkhead_controller_subscriber', new Definition(
                \Gohany\CircuitBreakerSymfonyBundle\Http\EventSubscriber\BulkheadControllerSubscriber::class,
                [new Reference('gohany.circuitbreaker.bulkhead_pool_locator')]
            ))->addTag('kernel.event_subscriber');
        }
    }
}
