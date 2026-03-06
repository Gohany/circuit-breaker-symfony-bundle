<?php

declare(strict_types=1);

namespace Gohany\CircuitBreakerSymfonyBundle\Doctrine;

use Symfony\Component\HttpFoundation\RequestStack;

final class RequestAwareDoctrineLaneResolver implements DoctrineLaneResolverInterface
{
    /** @var DoctrineLaneResolverInterface */
    private $fallback;
    /** @var RequestStack */
    private $requestStack;
    /** @var string|null */
    private $parentPipeline;
    /** @var string|null */
    private $childPipeline;
    /** @var array<string,string> */
    private $parentLaneMap;
    /** @var array<string,string> */
    private $childLaneMap;

    /**
     * @param array<string,string> $parentLaneMap
     * @param array<string,string> $childLaneMap
     */
    public function __construct(
        DoctrineLaneResolverInterface $fallback,
        RequestStack $requestStack,
        ?string $parentPipeline,
        ?string $childPipeline,
        array $parentLaneMap,
        array $childLaneMap
    ) {
        $this->fallback = $fallback;
        $this->requestStack = $requestStack;
        $this->parentPipeline = $parentPipeline;
        $this->childPipeline = $childPipeline;
        $this->parentLaneMap = $parentLaneMap;
        $this->childLaneMap = $childLaneMap;
    }

    public function resolveConnectLaneContext(): DoctrineLaneContext
    {
        return $this->withRoutingLanes($this->fallback->resolveConnectLaneContext());
    }

    public function resolveQueryLaneContext(?string $sql = null): DoctrineLaneContext
    {
        return $this->withRoutingLanes($this->fallback->resolveQueryLaneContext($sql));
    }

    private function withRoutingLanes(DoctrineLaneContext $base): DoctrineLaneContext
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return $base;
        }

        $routeValue = $request->attributes->get('_route');
        $route = is_string($routeValue) ? $routeValue : '';
        $path = (string) $request->getPathInfo();

        $extraLanes = $base->getExtraLanes();

        $parentLane = $this->findLane($this->parentLaneMap, $route, $path);
        if ($parentLane !== null) {
            $extraLanes[] = new DoctrineLaneAcquisition($this->parentPipeline, $parentLane);
        }

        $childLane = $this->findLane($this->childLaneMap, $route, $path);
        if ($childLane !== null) {
            $extraLanes[] = new DoctrineLaneAcquisition($this->childPipeline, $childLane);
        }

        return new DoctrineLaneContext($base->getPrimary(), $extraLanes);
    }

    /**
     * @param array<string,string> $map
     */
    private function findLane(array $map, string $route, string $path): ?string
    {
        foreach ($map as $pattern => $lane) {
            if (@preg_match('/' . $pattern . '/', $route) === 1 || @preg_match('/' . $pattern . '/', $path) === 1) {
                return $lane;
            }
        }

        return null;
    }
}
