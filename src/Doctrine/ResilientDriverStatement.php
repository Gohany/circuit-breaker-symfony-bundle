<?php

declare(strict_types=1);

namespace Gohany\CircuitBreakerSymfonyBundle\Doctrine;

use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Gohany\CircuitBreaker\Observability\EmitterInterface;
use Psr\Container\ContainerInterface;

final class ResilientDriverStatement implements Statement
{
    /** @var Statement */
    private $stmt;
    /** @var ContainerInterface */
    private $container;
    /** @var EmitterInterface */
    private $emitter;
    /** @var DoctrineLaneResolverInterface */
    private $laneResolver;
    /** @var string */
    private $sql;

    public function __construct(
        Statement $stmt,
        ContainerInterface $container,
        EmitterInterface $emitter,
        DoctrineLaneResolverInterface $laneResolver,
        string $sql
    ) {
        $this->stmt = $stmt;
        $this->container = $container;
        $this->emitter = $emitter;
        $this->laneResolver = $laneResolver;
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
        $laneContext = $this->laneResolver->resolveQueryLaneContext($this->sql);
        $executor = new DoctrineLaneExecutor($this->container);

        return $executor->execute(
            $laneContext,
            'db.execute',
            function () use ($params): Result {
            return $this->stmt->execute($params);
            },
            ['dbal.sql' => $this->shortSql($this->sql)]
        );
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
