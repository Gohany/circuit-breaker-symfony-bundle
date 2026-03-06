<?php

declare(strict_types=1);

namespace Gohany\CircuitBreakerSymfonyBundle\Doctrine;

final class DefaultDoctrineLaneResolver implements DoctrineLaneResolverInterface
{
    /** @var string|null */
    private $connectPipeline;
    /** @var string|null */
    private $queryPipeline;
    /** @var string */
    private $connectLane;
    /** @var string */
    private $queryLane;

    public function __construct(?string $connectPipeline, ?string $queryPipeline, string $connectLane, string $queryLane)
    {
        $this->connectPipeline = $connectPipeline;
        $this->queryPipeline = $queryPipeline;
        $this->connectLane = $connectLane;
        $this->queryLane = $queryLane;
    }

    public function resolveConnectLaneContext(): DoctrineLaneContext
    {
        return new DoctrineLaneContext(new DoctrineLaneAcquisition($this->connectPipeline, $this->connectLane));
    }

    public function resolveQueryLaneContext(?string $sql = null): DoctrineLaneContext
    {
        return new DoctrineLaneContext(new DoctrineLaneAcquisition($this->queryPipeline, $this->queryLane));
    }
}
