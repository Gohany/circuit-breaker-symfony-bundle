<?php

declare(strict_types=1);

namespace Gohany\CircuitBreakerSymfonyBundle\Resilience;

use Gohany\Circuitbreaker\Observability\EmitterInterface;
use Gohany\Circuitbreaker\Resilience\Context;
use Gohany\Circuitbreaker\Resilience\ResilienceMiddlewareInterface;

/**
 * @deprecated Use `Gohany\Circuitbreaker\Resilience\RtryRetryMiddleware` from `gohany/circuitbreaker`.
 */
final class RtryRetryMiddleware implements ResilienceMiddlewareInterface
{
    private \Gohany\Circuitbreaker\Resilience\RtryRetryMiddleware $inner;

    public function __construct(string $spec, ?EmitterInterface $emitter = null)
    {
        $this->inner = new \Gohany\Circuitbreaker\Resilience\RtryRetryMiddleware($spec, $emitter);
    }

    public function handle(Context $ctx, callable $next)
    {
        return $this->inner->handle($ctx, $next);
    }
}
