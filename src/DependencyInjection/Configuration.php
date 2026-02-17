<?php

namespace Gohany\Circuitbreaker\bundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tb = new TreeBuilder('Gohany_circuitbreaker');
        $root = $tb->getRootNode();

        $root
            ->children()
                ->arrayNode('redis')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('client_service')->defaultNull()->end()
                        ->scalarNode('key_prefix')->defaultValue('cb')->end()
                        ->booleanNode('use_human_readable_keys')->defaultFalse()->end()
                        ->integerNode('state_default_ttl_ms')->defaultValue(604800000)->end()
                        ->integerNode('bucket_ttl_seconds')->defaultValue(900)->end()
                        ->integerNode('counters_ttl_seconds')->defaultValue(0)->end()
                    ->end()
                ->end()
                ->arrayNode('default')
                    ->isRequired()
                    ->children()
                        ->scalarNode('policy_service')->isRequired()->cannotBeEmpty()->end()
                        ->scalarNode('classifier_service')->isRequired()->cannotBeEmpty()->end()
                        ->scalarNode('side_effect_dispatcher_service')->isRequired()->cannotBeEmpty()->end()

                        ->scalarNode('override_decider_tag')->defaultValue('Gohany.circuitbreaker.override_decider')->end()

                        ->scalarNode('clock_service')->defaultNull()->end()

                        ->scalarNode('probe_gate_service')->defaultNull()->end()
                        ->scalarNode('state_store_service')->defaultNull()->end()
                        ->scalarNode('history_store_service')->defaultNull()->end()

                        ->scalarNode('retry_executor_service')->defaultNull()->end()
                        ->scalarNode('retry_policy_or_spec')->defaultNull()->end()
                    ->end()
                ->end()
                ->arrayNode('circuits')
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('policy_service')->isRequired()->cannotBeEmpty()->end()
                            ->scalarNode('classifier_service')->isRequired()->cannotBeEmpty()->end()
                            ->scalarNode('side_effect_dispatcher_service')->isRequired()->cannotBeEmpty()->end()

                            ->scalarNode('override_decider_tag')->defaultValue('Gohany.circuitbreaker.override_decider')->end()

                            ->scalarNode('clock_service')->defaultNull()->end()

                            ->scalarNode('probe_gate_service')->defaultNull()->end()
                            ->scalarNode('state_store_service')->defaultNull()->end()
                            ->scalarNode('history_store_service')->defaultNull()->end()

                            ->scalarNode('retry_executor_service')->defaultNull()->end()
                            ->scalarNode('retry_policy_or_spec')->defaultNull()->end()
                        ->end()
                    ->end()
                    ->defaultValue([])
                ->end()
            ->end();

        return $tb;
    }
}
