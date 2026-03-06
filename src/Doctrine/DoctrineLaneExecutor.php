<?php

declare(strict_types=1);

namespace Gohany\CircuitBreakerSymfonyBundle\Doctrine;

use Gohany\Circuitbreaker\Resilience\Context;
use Gohany\CircuitBreaker\Resilience\ResiliencePipeline;
use Psr\Container\ContainerInterface;

final class DoctrineLaneExecutor
{
    /** @var ContainerInterface */
    private $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    /**
     * @template T
     * @param callable():T $action
     * @param array<string,mixed> $attributes
     * @return T
     */
    public function execute(DoctrineLaneContext $laneContext, string $operation, callable $action, array $attributes = [])
    {
        $acquisitions = $laneContext->getAllAcquisitions();
        $runner = $action;

        for ($i = count($acquisitions) - 1; $i >= 0; --$i) {
            $acquisition = $acquisitions[$i];
            $next = $runner;

            $runner = function () use ($operation, $attributes, $acquisition, $i, $next) {
                $pipeline = $acquisition->getPipeline();
                if ($pipeline === null || $pipeline === '') {
                    return $next();
                }

                /** @var ResiliencePipeline $pipe */
                $pipe = $this->container->get('gohany.circuitbreaker.pipeline.' . $pipeline);
                $ctx = new Context($operation, $acquisition->getLane());
                foreach ($attributes as $name => $value) {
                    $ctx->set($name, $value);
                }
                $ctx->set('dbal.bulkhead.pipeline', $pipeline);
                $ctx->set('dbal.bulkhead.lane', $acquisition->getLane());
                $ctx->set('dbal.bulkhead.level', $i === 0 ? 'primary' : 'extra');

                return $pipe->execute($ctx, $next);
            };
        }

        return $runner();
    }
}
