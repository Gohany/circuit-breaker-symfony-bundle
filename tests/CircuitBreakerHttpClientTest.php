<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\bundle\tests;

use Gohany\Circuitbreaker\Core\CircuitBreakerInterface;
use Gohany\Circuitbreaker\Core\CircuitContext;
use Gohany\Circuitbreaker\Core\CircuitKey;
use Gohany\Circuitbreaker\Defaults\Http\HttpCircuitBuilderInterface;
use Gohany\CircuitBreakerSymfonyBundle\Http\CircuitBreakerHttpClient;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class CircuitBreakerHttpClientTest extends TestCase
{
    public function testSendRequestIsExecutedThroughBreaker(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);

        $inner = $this->createMock(ClientInterface::class);
        $inner
            ->expects($this->once())
            ->method('sendRequest')
            ->with($request)
            ->willReturn($response);

        $key = new CircuitKey('http:test');
        $context = new CircuitContext(null, ['a' => 'b']);

        $builder = new class($key, $context) implements HttpCircuitBuilderInterface {
            private CircuitKey $key;
            private CircuitContext $context;

            public function __construct(CircuitKey $key, CircuitContext $context)
            {
                $this->key = $key;
                $this->context = $context;
            }

            public function buildKey(RequestInterface $request, string $prefix): CircuitKey
            {
                return $this->key;
            }

            public function buildContext(RequestInterface $request): CircuitContext
            {
                return $this->context;
            }
        };

        $breaker = $this->createMock(CircuitBreakerInterface::class);
        $breaker
            ->expects($this->once())
            ->method('execute')
            ->with($key, $context, $this->isType('callable'))
            ->willReturnCallback(static function (CircuitKey $k, CircuitContext $c, callable $operation) {
                return $operation();
            });

        $client = new CircuitBreakerHttpClient($inner, $breaker, 'http', $builder);

        $out = $client->sendRequest($request);
        $this->assertSame($response, $out);
    }
}
