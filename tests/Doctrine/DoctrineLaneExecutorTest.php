<?php

declare(strict_types=1);

namespace Gohany\Circuitbreaker\bundle\tests\Doctrine;

use Gohany\CircuitBreaker\Resilience\Context;
use Gohany\CircuitBreakerSymfonyBundle\Doctrine\DoctrineLaneAcquisition;
use Gohany\CircuitBreakerSymfonyBundle\Doctrine\DoctrineLaneContext;
use Gohany\CircuitBreakerSymfonyBundle\Doctrine\DoctrineLaneExecutor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class DoctrineLaneExecutorTest extends TestCase
{
    public function testExecuteReturnsActionResultWhenAllPipelinesAreNull(): void
    {
        $container = new ContainerBuilder();
        $executor = new DoctrineLaneExecutor($container);
        $laneContext = new DoctrineLaneContext(
            new DoctrineLaneAcquisition(null, 'db.query.primary'),
            [new DoctrineLaneAcquisition(null, 'db.query.extra')]
        );

        $result = $executor->execute($laneContext, 'db.query', function (): string {
            return 'ok';
        });

        $this->assertSame('ok', $result);
    }

    public function testExecuteWrapsActionWithPrimaryThenExtraPipelines(): void
    {
        $container = new ContainerBuilder();
        $recorded = [];

        $container->set('gohany.circuitbreaker.pipeline.p1', new TestPipeline($recorded));
        $container->set('gohany.circuitbreaker.pipeline.p2', new TestPipeline($recorded));

        $executor = new DoctrineLaneExecutor($container);
        $laneContext = new DoctrineLaneContext(
            new DoctrineLaneAcquisition('p1', 'lane.primary'),
            [new DoctrineLaneAcquisition('p2', 'lane.extra')]
        );

        $result = $executor->execute(
            $laneContext,
            'db.query',
            function () use (&$recorded): string {
                $recorded[] = ['stage' => 'action'];
                return 'done';
            },
            ['dbal.sql' => 'SELECT 1']
        );

        $this->assertSame('done', $result);
        $this->assertCount(5, $recorded);
        $this->assertSame(['stage' => 'before', 'lane' => 'lane.primary', 'level' => 'primary', 'pipeline' => 'p1', 'operation' => 'db.query', 'sql' => 'SELECT 1', 'bypass_deny_block' => false], $recorded[0]);
        $this->assertSame(['stage' => 'before', 'lane' => 'lane.extra', 'level' => 'extra', 'pipeline' => 'p2', 'operation' => 'db.query', 'sql' => 'SELECT 1', 'bypass_deny_block' => false], $recorded[1]);
        $this->assertSame(['stage' => 'action'], $recorded[2]);
        $this->assertSame(['stage' => 'after', 'lane' => 'lane.extra', 'level' => 'extra', 'pipeline' => 'p2'], $recorded[3]);
        $this->assertSame(['stage' => 'after', 'lane' => 'lane.primary', 'level' => 'primary', 'pipeline' => 'p1'], $recorded[4]);
    }

    public function testExecutePassesBypassDenyBlockAttributeIntoContext(): void
    {
        $container = new ContainerBuilder();
        $recorded = [];

        $container->set('gohany.circuitbreaker.pipeline.p1', new TestPipeline($recorded));

        $executor = new DoctrineLaneExecutor($container);
        $laneContext = new DoctrineLaneContext(
            new DoctrineLaneAcquisition('p1', 'lane.primary')
        );

        $executor->execute(
            $laneContext,
            'db.query',
            function (): string {
                return 'done';
            },
            ['cb_bypass_deny_block' => true]
        );

        $this->assertTrue($recorded[0]['bypass_deny_block']);
    }
}

final class TestPipeline
{
    /** @var array<int,array<string,string>> */
    private $recorded;

    /**
     * @param array<int,array<string,string>> $recorded
     */
    public function __construct(array &$recorded)
    {
        $this->recorded = &$recorded;
    }

    /**
     * @param callable():mixed $next
     * @return mixed
     */
    public function execute(Context $ctx, callable $next)
    {
        $this->recorded[] = [
            'stage' => 'before',
            'lane' => $ctx->getLane(),
            'level' => (string) $ctx->get('dbal.bulkhead.level'),
            'pipeline' => (string) $ctx->get('dbal.bulkhead.pipeline'),
            'operation' => $ctx->getOperation(),
            'sql' => (string) $ctx->get('dbal.sql', ''),
            'bypass_deny_block' => $ctx->get('cb_bypass_deny_block', false),
        ];

        $result = $next();

        $this->recorded[] = [
            'stage' => 'after',
            'lane' => $ctx->getLane(),
            'level' => (string) $ctx->get('dbal.bulkhead.level'),
            'pipeline' => (string) $ctx->get('dbal.bulkhead.pipeline'),
        ];

        return $result;
    }
}
