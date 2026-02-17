<?php

namespace Gohany\Circuitbreaker\bundle\Http;

use Gohany\Circuitbreaker\Core\CircuitBreakerInterface;
use Gohany\Circuitbreaker\Defaults\Http\CircuitBreakingPsr18Client;
use Gohany\Circuitbreaker\Defaults\Http\HttpCircuitBuilderInterface;
use Psr\Http\Client\ClientInterface;

/**
 * Symfony-bundle convenience wrapper.
 *
 * This is a PSR-18 HTTP client decorator that adds circuit breaking.
 *
 * If you need Symfony's HttpClient contracts, use Symfony's PSR-18 bridge/adapters
 * and decorate the PSR-18 client service instead.
 */
class CircuitBreakerHttpClient extends CircuitBreakingPsr18Client
{
    public function __construct(
        ClientInterface $inner,
        CircuitBreakerInterface $breaker,
        string $prefix = 'http',
        ?HttpCircuitBuilderInterface $builder = null
    ) {
        parent::__construct($inner, $breaker, $prefix, $builder);
    }
}
