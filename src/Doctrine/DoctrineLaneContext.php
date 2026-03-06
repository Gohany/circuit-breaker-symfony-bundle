<?php

declare(strict_types=1);

namespace Gohany\CircuitBreakerSymfonyBundle\Doctrine;

final class DoctrineLaneContext
{
    /** @var DoctrineLaneAcquisition */
    private $primary;
    /** @var DoctrineLaneAcquisition[] */
    private $extraLanes;

    /**
     * @param DoctrineLaneAcquisition[] $extraLanes
     */
    public function __construct(DoctrineLaneAcquisition $primary, array $extraLanes = [])
    {
        $this->primary = $primary;
        $this->extraLanes = array_values($extraLanes);
    }

    public function getPrimary(): DoctrineLaneAcquisition
    {
        return $this->primary;
    }

    /**
     * @return DoctrineLaneAcquisition[]
     */
    public function getExtraLanes(): array
    {
        return $this->extraLanes;
    }

    /**
     * @return DoctrineLaneAcquisition[]
     */
    public function getAllAcquisitions(): array
    {
        return array_merge([$this->primary], $this->extraLanes);
    }
}
