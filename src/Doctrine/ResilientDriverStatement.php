<?php

declare(strict_types=1);

namespace Gohany\CircuitBreakerSymfonyBundle\Doctrine;

use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Gohany\CircuitBreaker\Observability\EmitterInterface;
use Gohany\CircuitBreaker\Resilience\Context;
use Gohany\CircuitBreaker\Resilience\ResiliencePipeline;
use Psr\Container\ContainerInterface;

final class ResilientDriverStatement implements Statement
{
    /** @var Statement */
    private $stmt;
    /** @var ContainerInterface */
    private $container;
    /** @var EmitterInterface */
    private $emitter;
    /** @var string|null */
    private $queryPipeline;
    /** @var string */
    private $queryLane;
    /** @var string */
    private $sql;

    public function __construct(
        Statement $stmt,
        ContainerInterface $container,
        EmitterInterface $emitter,
        ?string $queryPipeline,
        string $queryLane,
        string $sql
    ) {
        $this->stmt = $stmt;
        $this->container = $container;
        $this->emitter = $emitter;
        $this->queryPipeline = $queryPipeline;
        $this->queryLane = $queryLane;
        $this->sql = $sql;
    }

    public function bindValue($param, $value, $type = null): bool
    {
        return $this->stmt->bindValue($param, $value, $type);
    }

    public function bindParam($param, &$variable, $type = null, $length = null): bool
    {
        return $this->stmt->bindParam($param, $variable, $type, $length);
    }

    public function execute($params = null): Result
    {
        if (!$this->queryPipeline) {
            return $this->stmt->execute($params);
        }

        /** @var ResiliencePipeline $pipe */
        $pipe = $this->container->get('gohany.circuitbreaker.pipeline.' . $this->queryPipeline);
        $ctx = new Context('db.execute', $this->queryLane);
        $ctx->set('dbal.sql', $this->shortSql($this->sql));

        return $pipe->execute($ctx, function () use ($params): Result {
            return $this->stmt->execute($params);
        });
    }

    public function rowCount(): int
    {
        return $this->stmt->rowCount();
    }

    public function closeCursor(): bool
    {
        return $this->stmt->closeCursor();
    }

    public function columnCount(): int
    {
        return $this->stmt->columnCount();
    }

    public function getWrappedStatement(): Statement
    {
        return $this->stmt;
    }

    private function shortSql(string $sql): string
    {
        $s = preg_replace('/\s+/', ' ', trim($sql));
        if ($s === null) {
            $s = $sql;
        }
        return strlen($s) > 400 ? substr($s, 0, 400) . '…' : $s;
    }
}
