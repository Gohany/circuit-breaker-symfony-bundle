<?php

declare(strict_types=1);

namespace Gohany\CircuitBreakerSymfonyBundle\Doctrine;

interface DoctrineLaneResolverInterface
{
    public function resolveConnectLaneContext(): DoctrineLaneContext;

    public function resolveQueryLaneContext(?string $sql = null): DoctrineLaneContext;
}
