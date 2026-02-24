<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\bundle\tests\Http;

use Gohany\Circuitbreaker\Contracts\BulkheadInterface;
use Gohany\Circuitbreaker\Contracts\BulkheadPermitInterface;
use Gohany\CircuitBreakerSymfonyBundle\Http\EventSubscriber\BulkheadControllerSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class BulkheadControllerSubscriberTest extends TestCase
{
    public function testAcquiresAndReleasesPermitOnResponse(): void
    {
        $permit = new class() implements BulkheadPermitInterface {
            public int $released = 0;

            public function getId(): string
            {
                return 'p1';
            }

            public function getLane(): string
            {
                return 'payments.charge';
            }

            public function release(): void
            {
                $this->released++;
            }
        };

        $bulkhead = new class($permit) implements BulkheadInterface {
            private BulkheadPermitInterface $permit;
            /** @var array<int,array{0:string,1:?float}> */
            public array $acquired = [];

            public function __construct(BulkheadPermitInterface $permit)
            {
                $this->permit = $permit;
            }

            public function acquire(string $lane, ?float $timeoutSeconds = null): BulkheadPermitInterface
            {
                $this->acquired[] = [$lane, $timeoutSeconds];
                return $this->permit;
            }

            public function run(string $lane, callable $fn, ?float $timeoutSeconds = null)
            {
                $p = $this->acquire($lane, $timeoutSeconds);
                try {
                    return $fn();
                } finally {
                    $p->release();
                }
            }
        };

        $locator = new ServiceLocator([
            'db-main' => static fn () => $bulkhead,
        ]);

        $sub = new BulkheadControllerSubscriber($locator);

        $kernel = $this->createMock(HttpKernelInterface::class);
        $req = new Request();
        $req->attributes->set('_route', 'route_name_should_not_be_used');

        $controller = new class {
            /** @Bulkhead(pool="db-main", lane="payments.charge") */
            public function __invoke(): void
            {
            }
        };

        $ev = new ControllerEvent($kernel, $controller, $req, HttpKernelInterface::MAIN_REQUEST);
        $sub->onController($ev);

        $this->assertSame([['payments.charge', null]], $bulkhead->acquired);

        $respEv = new ResponseEvent($kernel, $req, HttpKernelInterface::MAIN_REQUEST, new Response());
        $sub->onResponse($respEv);

        $this->assertSame(1, $permit->released);
    }

    public function testSupportsMultipleBulkheadTagsAndReleasesAllPermits(): void
    {
        $permit1 = new class() implements BulkheadPermitInterface {
            public int $released = 0;
            public function getId(): string { return 'p1'; }
            public function getLane(): string { return 'hydra'; }
            public function release(): void { $this->released++; }
        };
        $permit2 = new class() implements BulkheadPermitInterface {
            public int $released = 0;
            public function getId(): string { return 'p2'; }
            public function getLane(): string { return 'charges'; }
            public function release(): void { $this->released++; }
        };

        $bulkhead1 = new class($permit1) implements BulkheadInterface {
            private BulkheadPermitInterface $permit;
            /** @var array<int,array{0:string,1:?float}> */
            public array $acquired = [];
            public function __construct(BulkheadPermitInterface $permit) { $this->permit = $permit; }
            public function acquire(string $lane, ?float $timeoutSeconds = null): BulkheadPermitInterface
            {
                $this->acquired[] = [$lane, $timeoutSeconds];
                return $this->permit;
            }
            public function run(string $lane, callable $fn, ?float $timeoutSeconds = null)
            {
                $p = $this->acquire($lane, $timeoutSeconds);
                try { return $fn(); } finally { $p->release(); }
            }
        };
        $bulkhead2 = new class($permit2) implements BulkheadInterface {
            private BulkheadPermitInterface $permit;
            /** @var array<int,array{0:string,1:?float}> */
            public array $acquired = [];
            public function __construct(BulkheadPermitInterface $permit) { $this->permit = $permit; }
            public function acquire(string $lane, ?float $timeoutSeconds = null): BulkheadPermitInterface
            {
                $this->acquired[] = [$lane, $timeoutSeconds];
                return $this->permit;
            }
            public function run(string $lane, callable $fn, ?float $timeoutSeconds = null)
            {
                $p = $this->acquire($lane, $timeoutSeconds);
                try { return $fn(); } finally { $p->release(); }
            }
        };

        $locator = new ServiceLocator([
            'db-queries' => static fn () => $bulkhead1,
            'db-hydra' => static fn () => $bulkhead2,
        ]);

        $sub = new BulkheadControllerSubscriber($locator);
        $kernel = $this->createMock(HttpKernelInterface::class);
        $req = new Request();

        $controller = new class {
            /**
             * @Bulkhead(pool="db-queries", lane="hydra")
             * @Bulkhead(pool="db-hydra", lane="charges")
             */
            public function __invoke(): void
            {
            }
        };

        $ev = new ControllerEvent($kernel, $controller, $req, HttpKernelInterface::MAIN_REQUEST);
        $sub->onController($ev);

        $this->assertSame([['hydra', null]], $bulkhead1->acquired);
        $this->assertSame([['charges', null]], $bulkhead2->acquired);

        $respEv = new ResponseEvent($kernel, $req, HttpKernelInterface::MAIN_REQUEST, new Response());
        $sub->onResponse($respEv);

        $this->assertSame(1, $permit1->released);
        $this->assertSame(1, $permit2->released);
    }

    public function testDefaultsLaneToSymfonyRouteName(): void
    {
        $permit = new class() implements BulkheadPermitInterface {
            public function getId(): string { return 'p1'; }
            public function getLane(): string { return 'x'; }
            public function release(): void {}
        };

        $bulkhead = new class($permit) implements BulkheadInterface {
            private BulkheadPermitInterface $permit;
            /** @var array<int,array{0:string,1:?float}> */
            public array $acquired = [];
            public function __construct(BulkheadPermitInterface $permit) { $this->permit = $permit; }
            public function acquire(string $lane, ?float $timeoutSeconds = null): BulkheadPermitInterface
            {
                $this->acquired[] = [$lane, $timeoutSeconds];
                return $this->permit;
            }

            public function run(string $lane, callable $fn, ?float $timeoutSeconds = null)
            {
                $p = $this->acquire($lane, $timeoutSeconds);
                try {
                    return $fn();
                } finally {
                    $p->release();
                }
            }
        };

        $locator = new ServiceLocator([
            'db-main' => static fn () => $bulkhead,
        ]);

        $sub = new BulkheadControllerSubscriber($locator);

        $kernel = $this->createMock(HttpKernelInterface::class);
        $req = new Request();
        $req->attributes->set('_route', 'api_courses_hydra_charges');

        $controller = new class {
            /** @Bulkhead(pool="db-main") */
            public function action(): void {}
        };

        $ev = new ControllerEvent($kernel, [$controller, 'action'], $req, HttpKernelInterface::MAIN_REQUEST);
        $sub->onController($ev);

        $this->assertSame([['api_courses_hydra_charges', null]], $bulkhead->acquired);
    }

    public function testReleasesPermitOnException(): void
    {
        $permit = new class() implements BulkheadPermitInterface {
            public int $released = 0;
            public function getId(): string { return 'p1'; }
            public function getLane(): string { return 'x'; }
            public function release(): void { $this->released++; }
        };

        $bulkhead = new class($permit) implements BulkheadInterface {
            private BulkheadPermitInterface $permit;
            public function __construct(BulkheadPermitInterface $permit) { $this->permit = $permit; }
            public function acquire(string $lane, ?float $timeoutSeconds = null): BulkheadPermitInterface { return $this->permit; }

            public function run(string $lane, callable $fn, ?float $timeoutSeconds = null)
            {
                $p = $this->acquire($lane, $timeoutSeconds);
                try {
                    return $fn();
                } finally {
                    $p->release();
                }
            }
        };

        $locator = new ServiceLocator([
            'db-main' => static fn () => $bulkhead,
        ]);

        $sub = new BulkheadControllerSubscriber($locator);
        $kernel = $this->createMock(HttpKernelInterface::class);

        $req = new Request();
        $req->attributes->set('_route', 'r');

        $controller = new class {
            /** @Bulkhead(pool="db-main") */
            public function action(): void {}
        };

        $ev = new ControllerEvent($kernel, [$controller, 'action'], $req, HttpKernelInterface::MAIN_REQUEST);
        $sub->onController($ev);

        $excEv = new ExceptionEvent($kernel, $req, HttpKernelInterface::MAIN_REQUEST, new \RuntimeException('fail'));
        $sub->onException($excEv);

        $this->assertSame(1, $permit->released);
    }

    public function testUnknownPoolThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $locator = new ServiceLocator([]);
        $sub = new BulkheadControllerSubscriber($locator);

        $kernel = $this->createMock(HttpKernelInterface::class);
        $req = new Request();
        $req->attributes->set('_route', 'r');

        $controller = new class {
            /** @Bulkhead(pool="missing") */
            public function action(): void {}
        };

        $ev = new ControllerEvent($kernel, [$controller, 'action'], $req, HttpKernelInterface::MAIN_REQUEST);
        $sub->onController($ev);
    }
}
