<?php

declare(strict_types=1);

namespace Gohany\CircuitBreakerSymfonyBundle\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;
use Gohany\CircuitBreaker\Observability\EmitterInterface;
use Psr\Container\ContainerInterface;

/**
 * Doctrine DBAL middleware that:
 *  - optionally runs a pipeline around connect()
 *  - optionally runs a pipeline around query execution
 *
 * Designed for the "block connecting, but don't block already-connected clients" pattern:
 *  - put circuit breaker + bulkhead on connect_pipeline
 *  - put *observability + circuit failure accounting* on query_pipeline (no bulkhead stage if desired)
 */
final class ResilientDbalMiddleware implements Middleware
{
    /** @var ContainerInterface */
    private $container;
    /** @var EmitterInterface */
    private $emitter;
    /** @var DoctrineLaneResolverInterface */
    private $laneResolver;
    /** @var bool */
    private $bypassDenyBlock;

    public function __construct(
        ContainerInterface $container,
        EmitterInterface $emitter,
        DoctrineLaneResolverInterface $laneResolver,
        $bypassDenyBlock = false
    ) {
        $this->container = $container;
        $this->emitter = $emitter;
        $this->laneResolver = $laneResolver;
        $this->bypassDenyBlock = $this->normalizeBoolean($bypassDenyBlock);
    }

    public function wrap(Driver $driver): Driver
    {
        return new ResilientDriver(
            $driver,
            $this->container,
            $this->emitter,
            $this->laneResolver,
            $this->bypassDenyBlock
        );
    }

    /**
     * @param mixed $value
     */
    private function normalizeBoolean($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN) === true;
    }
}
