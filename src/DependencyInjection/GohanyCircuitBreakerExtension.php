<?php

namespace Gohany\Circuitbreaker\bundle\DependencyInjection;

use Gohany\Circuitbreaker\Core\CircuitBreaker;
use Gohany\Circuitbreaker\Core\CircuitBreakerInterface;
use Gohany\Circuitbreaker\Override\Redis\RedisCircuitAdmin;
use Gohany\Circuitbreaker\Override\Redis\RedisOverrideAdmin;
use Gohany\Circuitbreaker\Override\Redis\RedisOverrideDecider;
use Gohany\Circuitbreaker\Override\Redis\RedisOverrideStore;
use Gohany\Circuitbreaker\Store\CircuitHistoryStoreInterface;
use Gohany\Circuitbreaker\Store\CircuitStateStoreInterface;
use Gohany\Circuitbreaker\Store\ProbeGateInterface;
use Gohany\Circuitbreaker\Store\Redis\RedisCircuitHistoryStore;
use Gohany\Circuitbreaker\Store\Redis\RedisCircuitStateStore;
use Gohany\Circuitbreaker\Store\Redis\RedisKeyBuilder;
use Gohany\Circuitbreaker\Store\Redis\RedisProbeGate;
use Gohany\Circuitbreaker\Store\Redis\RedisClientInterface;
use Gohany\Circuitbreaker\bundle\Clock\SystemClock;
use Gohany\Circuitbreaker\bundle\Command\CircuitBreakerDebugCommand;
use Gohany\Circuitbreaker\bundle\Command\CircuitBreakerSanityCheckCommand;
use Gohany\Circuitbreaker\bundle\Command\RedisOverrideCommand;
use Gohany\Circuitbreaker\bundle\Registry\CircuitBreakerRegistry;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Alias;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ChildDefinition;
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

        $redisClientService = $config['redis']['client_service'] ?? null;
        $hasRedis = is_string($redisClientService) && $redisClientService !== '';

        if ($hasRedis) {
            // Core infra services (redis-backed)
            $container->register('Gohany.circuitbreaker.redis_key_builder', RedisKeyBuilder::class)
                ->setArguments([
                    $config['redis']['key_prefix'],
                    (bool) $config['redis']['use_human_readable_keys'],
                ]);

            // Alias RedisClientInterface to user's service
            $container->setAlias(RedisClientInterface::class, new Alias($redisClientService, false));

            $container->register('Gohany.circuitbreaker.state_store.redis', RedisCircuitStateStore::class)
                ->setArguments([
                    new Reference(RedisClientInterface::class),
                    new Reference('Gohany.circuitbreaker.redis_key_builder'),
                    (int) $config['redis']['state_default_ttl_ms'],
                ]);

            $container->register('Gohany.circuitbreaker.history_store.redis', RedisCircuitHistoryStore::class)
                ->setArguments([
                    new Reference(RedisClientInterface::class),
                    new Reference('Gohany.circuitbreaker.redis_key_builder'),
                    (int) $config['redis']['bucket_ttl_seconds'],
                    (int) $config['redis']['counters_ttl_seconds'],
                ]);

            $container->register('Gohany.circuitbreaker.probe_gate.redis', RedisProbeGate::class)
                ->setArguments([
                    new Reference(RedisClientInterface::class),
                    new Reference('Gohany.circuitbreaker.redis_key_builder'),
                    (int) $config['redis']['state_default_ttl_ms'],
                ]);

            // Overrides (optional wiring; override deciders are tagged)
            $container->register('Gohany.circuitbreaker.override_store.redis', RedisOverrideStore::class)
                ->setArguments([
                    new Reference(RedisClientInterface::class),
                    new Reference('Gohany.circuitbreaker.redis_key_builder'),
                ]);

            $container->register('Gohany.circuitbreaker.override_decider.redis', RedisOverrideDecider::class)
                ->setArguments([
                    new Reference('Gohany.circuitbreaker.override_store.redis'),
                    new Reference('Gohany.circuitbreaker.clock.system'),
                ])
                ->addTag('Gohany.circuitbreaker.override_decider');

            $container->register('Gohany.circuitbreaker.override_admin.redis', RedisOverrideAdmin::class)
                ->setArguments([
                    new Reference('Gohany.circuitbreaker.override_store.redis'),
                    new Reference('Gohany.circuitbreaker.clock.system'),
                ]);

            $container->register('Gohany.circuitbreaker.circuit_admin.redis', RedisCircuitAdmin::class)
                ->setArguments([
                    new Reference(RedisClientInterface::class),
                    new Reference('Gohany.circuitbreaker.redis_key_builder'),
                    new Reference('Gohany.circuitbreaker.override_store.redis'),
                    new Reference('Gohany.circuitbreaker.state_store.redis'),
                    new Reference('Gohany.circuitbreaker.clock.system'),
                ]);
        }

        // Default Clock (PSR-20)
        $container->register('Gohany.circuitbreaker.clock.system', SystemClock::class)
            ->setArguments(['UTC']);

        // Build default circuit breaker + named circuits
        $circuits = [];
        $resolved = [];

        $default = $this->registerCircuitBreaker(
            $container,
            'default',
            $config['default'],
            $hasRedis
        );
        $circuits['default'] = $default['service_id'];
        $resolved['default'] = $default['resolved'];

        foreach ($config['circuits'] as $name => $cCfg) {
            $row = $this->registerCircuitBreaker(
                $container,
                (string) $name,
                $cCfg,
                $hasRedis
            );
            $circuits[$name] = $row['service_id'];
            $resolved[(string) $name] = $row['resolved'];
        }

        // Expose resolved wiring for console/debug tooling.
        $container->setParameter('Gohany.circuitbreaker.resolved_circuits', $resolved);

        // Registry
        $registryDef = new Definition(CircuitBreakerRegistry::class);
        $registryDef->setArguments([$this->refsMap($circuits)]);
        $container->setDefinition('Gohany.circuitbreaker.registry', $registryDef);

        // Alias default
        if ($circuits['default'] !== 'Gohany.circuitbreaker.default') {
            $container->setAlias('Gohany.circuitbreaker.default', new Alias($circuits['default'], true));
        } else {
            // The default breaker is already registered under the public service ID.
            $container->getDefinition($circuits['default'])->setPublic(true);
        }

        $container->setAlias(CircuitBreakerInterface::class, new Alias($circuits['default'], true));

        // Console commands
        if (class_exists(\Symfony\Component\Console\Command\Command::class)) {
            $container->register('Gohany.circuitbreaker.command.debug', CircuitBreakerDebugCommand::class)
                ->setArguments([
                    '%Gohany.circuitbreaker.resolved_circuits%',
                ])
                ->addTag('console.command');

            $container->register('Gohany.circuitbreaker.command.sanity', CircuitBreakerSanityCheckCommand::class)
                ->setArguments([
                    new Reference('service_container'),
                    '%Gohany.circuitbreaker.resolved_circuits%',
                ])
                ->addTag('console.command');

            if ($hasRedis) {
                $container->register('Gohany.circuitbreaker.command.redis_override', RedisOverrideCommand::class)
                    ->setArguments([
                        new Reference('Gohany.circuitbreaker.override_admin.redis'),
                    ])
                    ->addTag('console.command');
            }
        }
    }

    /**
     * @param array<string,mixed> $cfg
     */
    private function registerCircuitBreaker(ContainerBuilder $container, string $name, array $cfg, bool $hasRedis): array
    {
        $id = 'Gohany.circuitbreaker.' . $name;

        $stateStoreId = $cfg['state_store_service'] ?: ($hasRedis ? 'Gohany.circuitbreaker.state_store.redis' : null);
        $historyStoreId = $cfg['history_store_service'] ?: ($hasRedis ? 'Gohany.circuitbreaker.history_store.redis' : null);
        $probeGateId = $cfg['probe_gate_service'] ?: ($hasRedis ? 'Gohany.circuitbreaker.probe_gate.redis' : null);
        $clockId = $cfg['clock_service'] ?: 'Gohany.circuitbreaker.clock.system';

        if ($stateStoreId === null || $historyStoreId === null || $probeGateId === null) {
            throw new InvalidConfigurationException(sprintf(
                'Circuit "%s" requires explicit infra store services when Redis is not configured. Please set `state_store_service`, `history_store_service`, and `probe_gate_service` under `%s:`.',
                $name,
                $name === 'default' ? 'default' : 'circuits.' . $name
            ));
        }

        $overrideTag = $cfg['override_decider_tag'] ?: 'Gohany.circuitbreaker.override_decider';
        $overrideDeciders = new TaggedIteratorArgument($overrideTag);

        $def = new Definition(CircuitBreaker::class);
        $def->setArguments([
            new Reference($stateStoreId),
            new Reference($historyStoreId),
            new Reference($cfg['policy_service']),
            new Reference($cfg['classifier_service']),
            $overrideDeciders,
            new Reference($cfg['side_effect_dispatcher_service']),
            new Reference($clockId),
            new Reference($probeGateId),
            $cfg['retry_executor_service'] ? new Reference($cfg['retry_executor_service']) : null,
            $cfg['retry_policy_or_spec'] ?: null,
        ]);

        $def->setPublic(false);

        $container->setDefinition($id, $def);

        return [
            'service_id' => $id,
            'resolved' => [
                'breaker_service' => $id,
                'policy_service' => (string) $cfg['policy_service'],
                'classifier_service' => (string) $cfg['classifier_service'],
                'side_effect_dispatcher_service' => (string) $cfg['side_effect_dispatcher_service'],
                'clock_service' => (string) $clockId,
                'probe_gate_service' => (string) $probeGateId,
                'state_store_service' => (string) $stateStoreId,
                'history_store_service' => (string) $historyStoreId,
                'override_decider_tag' => (string) $overrideTag,
                'retry_executor_service' => $cfg['retry_executor_service'] ? (string) $cfg['retry_executor_service'] : null,
                'retry_policy_or_spec' => $cfg['retry_policy_or_spec'] ?: null,
            ],
        ];
    }

    /**
     * @param array<string,string> $serviceIds
     * @return array<string,Reference>
     */
    private function refsMap(array $serviceIds): array
    {
        $out = [];
        foreach ($serviceIds as $name => $id) {
            $out[$name] = new Reference($id);
        }
        return $out;
    }
}
