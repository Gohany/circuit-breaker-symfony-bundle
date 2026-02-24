<?php

namespace Gohany\CircuitBreakerSymfonyBundle\Registry;

use Gohany\Circuitbreaker\Core\CircuitBreakerInterface;

final class CircuitBreakerRegistry
{
    /** @var array<string,CircuitBreakerInterface> */
    private array $circuits;

    /**
     * @param array<string,CircuitBreakerInterface> $circuits
     */
    public function __construct(array $circuits)
    {
        $this->circuits = $circuits;
    }

    public function get(string $name): CircuitBreakerInterface
    {
        if (!isset($this->circuits[$name])) {
            throw new \InvalidArgumentException('Unknown circuit breaker: ' . $name);
        }

        return $this->circuits[$name];
    }

    /**
     * @return string[]
     */
    public function names(): array
    {
        return array_keys($this->circuits);
    }
}
