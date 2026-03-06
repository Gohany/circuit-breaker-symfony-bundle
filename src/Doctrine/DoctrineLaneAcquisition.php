<?php

declare(strict_types=1);

namespace Gohany\CircuitBreakerSymfonyBundle\Doctrine;

final class DoctrineLaneAcquisition
{
    /** @var string|null */
    private $pipeline;
    /** @var string */
    private $lane;

    public function __construct(?string $pipeline, string $lane)
    {
        $this->pipeline = $pipeline;
        $this->lane = $lane;
    }

    public function getPipeline(): ?string
    {
        return $this->pipeline;
    }

    public function getLane(): string
    {
        return $this->lane;
    }
}
