<?php

declare(strict_types=1);

namespace Gohany\CircuitBreakerSymfonyBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('gohany_circuitbreaker');
        $root = $treeBuilder->getRootNode();

        $root
            ->children()
                ->scalarNode('profile_env_var')->defaultValue('GOHANY_CB_PROFILE')->end()
                ->scalarNode('default_profile')->defaultValue('default')->end()
                ->scalarNode('redis_client_service')->defaultValue('gohany.circuitbreaker.redis_client')->end()
                ->scalarNode('key_prefix')->defaultValue('cb')->end()
                ->arrayNode('profiles')
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            ->arrayNode('pools')
                                ->useAttributeAsKey('id')
                                ->arrayPrototype()
                                    ->children()
                                        ->integerNode('global_max')->min(1)->isRequired()->end()
                                        ->enumNode('mode')->values(['fixed','percent','weighted'])->defaultValue('fixed')->end()
                                        ->floatNode('soft_borrow_utilization_threshold')->defaultValue(0.5)->end()
                                        ->arrayNode('lanes')
                                            ->useAttributeAsKey('lane')
                                            ->arrayPrototype()
                                                ->children()
                                                    ->integerNode('max_concurrent')->min(1)->defaultNull()->end()
                                                    ->floatNode('percent')->min(0.0)->max(1.0)->defaultNull()->end()
                                                    ->integerNode('weight')->min(1)->defaultNull()->end()
                                                ->end()
                                            ->end()
                                        ->end()
                                    ->end()
                                ->end()
                            ->end()

                            ->arrayNode('pipelines')
                                ->useAttributeAsKey('name')
                                ->arrayPrototype()
                                    ->children()
                                        ->arrayNode('stages')
                                            ->arrayPrototype()
                                                ->children()
                                                    ->enumNode('type')->values(['bulkhead','circuit_breaker','retry'])->isRequired()->end()
                                                    ->scalarNode('pool')->defaultNull()->end()
                                                    ->scalarNode('circuit_id')->defaultNull()->end()
                                                    // `retry` can be either:
                                                    // - a map for the built-in `Resilience\\RetryMiddleware`
                                                    // - a scalar string spec for the gohany/rtry integration (e.g. `rtry:attempts=3;delay=50ms`)
                                                    ->variableNode('retry')->defaultNull()->end()
                                                ->end()
                                            ->end()
                                        ->end()
                                    ->end()
                                ->end()
                            ->end()

                            ->arrayNode('doctrine')
                                ->addDefaultsIfNotSet()
                                ->children()
                                    ->booleanNode('enabled')->defaultFalse()->end()
                                    ->scalarNode('connection')->defaultValue('default')->end()
                                    ->scalarNode('connect_pipeline')->defaultNull()->end()
                                    ->scalarNode('query_pipeline')->defaultNull()->end()
                                    ->scalarNode('connect_lane')->defaultValue('db.connect')->end()
                                    ->scalarNode('query_lane')->defaultValue('db.query')->end()
                                ->end()
                            ->end()

                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
