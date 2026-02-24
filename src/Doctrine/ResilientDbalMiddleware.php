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
    /** @var string|null */
    private $connectPipeline;
    /** @var string|null */
    private $queryPipeline;
    /** @var string */
    private $connectLane;
    /** @var string */
    private $queryLane;

    public function __construct(
        ContainerInterface $container,
        EmitterInterface $emitter,
        ?string $connectPipeline,
        ?string $queryPipeline,
        string $connectLane,
        string $queryLane
    ) {
        $this->container = $container;
        $this->emitter = $emitter;
        $this->connectPipeline = $connectPipeline;
        $this->queryPipeline = $queryPipeline;
        $this->connectLane = $connectLane;
        $this->queryLane = $queryLane;
    }

    public function wrap(Driver $driver): Driver
    {
        return new ResilientDriver(
            $driver,
            $this->container,
            $this->emitter,
            $this->connectPipeline,
            $this->queryPipeline,
            $this->connectLane,
            $this->queryLane
        );
    }
}
