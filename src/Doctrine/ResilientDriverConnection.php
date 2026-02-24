<?php

declare(strict_types=1);

namespace Gohany\CircuitBreakerSymfonyBundle\Doctrine;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\ParameterType;
use Gohany\CircuitBreaker\Observability\EmitterInterface;
use Gohany\CircuitBreaker\Resilience\Context;
use Gohany\CircuitBreaker\Resilience\ResiliencePipeline;
use Psr\Container\ContainerInterface;

final class ResilientDriverConnection implements Connection
{
    /** @var Connection */
    private $conn;
    /** @var ContainerInterface */
    private $container;
    /** @var EmitterInterface */
    private $emitter;
    /** @var string|null */
    private $queryPipeline;
    /** @var string */
    private $queryLane;

    public function __construct(
        Connection $conn,
        ContainerInterface $container,
        EmitterInterface $emitter,
        ?string $queryPipeline,
        string $queryLane
    ) {
        $this->conn = $conn;
        $this->container = $container;
        $this->emitter = $emitter;
        $this->queryPipeline = $queryPipeline;
        $this->queryLane = $queryLane;
    }

    public function prepare(string $sql): Statement
    {
        $stmt = $this->conn->prepare($sql);
        return new ResilientDriverStatement($stmt, $this->container, $this->emitter, $this->queryPipeline, $this->queryLane, $sql);
    }

    public function query(string $sql): Result
    {
        return $this->runQueryPipeline('db.query', $sql, function () use ($sql): Result {
            return $this->conn->query($sql);
        });
    }

    public function quote($value, $type = ParameterType::STRING)
    {
        return $this->conn->quote($value, $type);
    }

    public function exec(string $sql): int
    {
        return $this->runQueryPipeline('db.exec', $sql, function () use ($sql): int {
            return $this->conn->exec($sql);
        });
    }

    public function lastInsertId($name = null)
    {
        return $this->conn->lastInsertId($name);
    }

    public function beginTransaction(): bool
    {
        return $this->conn->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->conn->commit();
    }

    public function rollBack(): bool
    {
        return $this->conn->rollBack();
    }

    public function getNativeConnection()
    {
        return $this->conn->getNativeConnection();
    }

    /**
     * @template T
     * @param callable():T $fn
     * @return T
     */
    private function runQueryPipeline(string $op, string $sql, callable $fn)
    {
        if (!$this->queryPipeline) {
            return $fn();
        }

        /** @var ResiliencePipeline $pipe */
        $pipe = $this->container->get('gohany.circuitbreaker.pipeline.' . $this->queryPipeline);
        $ctx = new Context($op, $this->queryLane);
        $ctx->set('dbal.sql', $this->shortSql($sql));

        return $pipe->execute($ctx, $fn);
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
