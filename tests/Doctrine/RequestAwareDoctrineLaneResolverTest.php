<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\bundle\tests\Doctrine;

use Gohany\CircuitBreakerSymfonyBundle\Doctrine\DefaultDoctrineLaneResolver;
use Gohany\CircuitBreakerSymfonyBundle\Doctrine\RequestAwareDoctrineLaneResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class RequestAwareDoctrineLaneResolverTest extends TestCase
{
    public function testResolveQueryLaneContextAddsParentAndChildLanesWhenRouteMatches(): void
    {
        $stack = new RequestStack();
        $request = Request::create('/hydra/charges');
        $request->attributes->set('_route', 'hydra_charges_list');
        $stack->push($request);

        $resolver = new RequestAwareDoctrineLaneResolver(
            new DefaultDoctrineLaneResolver('connect_pipe', 'query_pipe', 'db.connect', 'db.query'),
            $stack,
            'db_query',
            'db_query',
            ['^hydra_' => 'hydra'],
            ['^hydra_charges_' => 'hydra.charges']
        );

        $context = $resolver->resolveQueryLaneContext('SELECT 1');
        $extraLanes = $context->getExtraLanes();

        $this->assertCount(2, $extraLanes);
        $this->assertSame('db_query', $extraLanes[0]->getPipeline());
        $this->assertSame('hydra', $extraLanes[0]->getLane());
        $this->assertSame('db_query', $extraLanes[1]->getPipeline());
        $this->assertSame('hydra.charges', $extraLanes[1]->getLane());
    }

    public function testResolveQueryLaneContextMatchesByPathWhenRouteDoesNotMatch(): void
    {
        $stack = new RequestStack();
        $request = Request::create('/hydra/orders');
        $request->attributes->set('_route', 'orders_list');
        $stack->push($request);

        $resolver = new RequestAwareDoctrineLaneResolver(
            new DefaultDoctrineLaneResolver('connect_pipe', 'query_pipe', 'db.connect', 'db.query'),
            $stack,
            'db_query',
            null,
            ['hydra' => 'hydra'],
            []
        );

        $context = $resolver->resolveQueryLaneContext('SELECT 1');
        $extraLanes = $context->getExtraLanes();

        $this->assertCount(1, $extraLanes);
        $this->assertSame('db_query', $extraLanes[0]->getPipeline());
        $this->assertSame('hydra', $extraLanes[0]->getLane());
    }

    public function testResolveConnectLaneContextFallsBackToPrimaryOnlyWhenNoRequest(): void
    {
        $resolver = new RequestAwareDoctrineLaneResolver(
            new DefaultDoctrineLaneResolver('connect_pipe', 'query_pipe', 'db.connect', 'db.query'),
            new RequestStack(),
            'db_query',
            'db_query',
            ['^hydra_' => 'hydra'],
            ['^hydra_charges_' => 'hydra.charges']
        );

        $context = $resolver->resolveConnectLaneContext();

        $this->assertSame('connect_pipe', $context->getPrimary()->getPipeline());
        $this->assertSame('db.connect', $context->getPrimary()->getLane());
        $this->assertSame([], $context->getExtraLanes());
    }
}
