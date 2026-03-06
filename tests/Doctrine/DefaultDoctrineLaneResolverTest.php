<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\bundle\tests\Doctrine;

use Gohany\CircuitBreakerSymfonyBundle\Doctrine\DefaultDoctrineLaneResolver;
use PHPUnit\Framework\TestCase;

final class DefaultDoctrineLaneResolverTest extends TestCase
{
    public function testResolveConnectLaneContextReturnsPrimaryLaneWithoutExtras(): void
    {
        $resolver = new DefaultDoctrineLaneResolver('connect_pipe', 'query_pipe', 'connect_lane', 'query_lane');

        $context = $resolver->resolveConnectLaneContext();

        $this->assertSame('connect_pipe', $context->getPrimary()->getPipeline());
        $this->assertSame('connect_lane', $context->getPrimary()->getLane());
        $this->assertSame([], $context->getExtraLanes());
    }

    public function testResolveQueryLaneContextReturnsPrimaryLaneWithoutExtras(): void
    {
        $resolver = new DefaultDoctrineLaneResolver('connect_pipe', 'query_pipe', 'connect_lane', 'query_lane');

        $context = $resolver->resolveQueryLaneContext('SELECT 1');

        $this->assertSame('query_pipe', $context->getPrimary()->getPipeline());
        $this->assertSame('query_lane', $context->getPrimary()->getLane());
        $this->assertSame([], $context->getExtraLanes());
    }
}
