<?php

declare(strict_types=1);

namespace Gohany\CircuitBreakerSymfonyBundle\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Exception;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Gohany\CircuitBreaker\Observability\EmitterInterface;
use Gohany\CircuitBreaker\Resilience\Context;
use Gohany\CircuitBreaker\Resilience\ResiliencePipeline;
use Psr\Container\ContainerInterface;

final class ResilientDriver implements Driver
{
    /** @var Driver */
    private $driver;
    /** @var ContainerInterface */
    private $container;
    /** @var EmitterInterface */
    private $emitter;
    /** @var string|null */
    private $connectPipeline;
    /** @var string|null */
    private $queryPipeline;
    /** @var string */
    private $connectLane;
    /** @var string */
    private $queryLane;

    public function __construct(
        Driver $driver,
        ContainerInterface $container,
        EmitterInterface $emitter,
        ?string $connectPipeline,
        ?string $queryPipeline,
        string $connectLane,
        string $queryLane
    ) {
        $this->driver = $driver;
        $this->container = $container;
        $this->emitter = $emitter;
        $this->connectPipeline = $connectPipeline;
        $this->queryPipeline = $queryPipeline;
        $this->connectLane = $connectLane;
        $this->queryLane = $queryLane;
    }

    public function connect(array $params): Connection
    {
        $op = 'db.connect';

        if ($this->connectPipeline) {
            /** @var ResiliencePipeline $pipe */
            $pipe = $this->container->get('gohany.circuitbreaker.pipeline.' . $this->connectPipeline);
            $ctx = new Context($op, $this->connectLane);
            $ctx->set('dbal.params', $this->redactParams($params));

            $conn = $pipe->execute($ctx, function () use ($params): Connection {
                return $this->driver->connect($params);
            });
        } else {
            $conn = $this->driver->connect($params);
        }

        return new ResilientDriverConnection(
            $conn,
            $this->container,
            $this->emitter,
            $this->queryPipeline,
            $this->queryLane
        );
    }

    public function getDatabasePlatform()
    {
        return $this->driver->getDatabasePlatform();
    }

    public function getSchemaManager(DbalConnection $conn, AbstractPlatform $platform): AbstractSchemaManager
    {
        return $this->driver->getSchemaManager($conn, $platform);
    }

    public function getExceptionConverter(): Driver\API\ExceptionConverter
    {
        return $this->driver->getExceptionConverter();
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function redactParams(array $params): array
    {
        $redacted = $params;
        foreach (['password', 'passwd', 'pwd'] as $k) {
            if (isset($redacted[$k])) {
                $redacted[$k] = '***';
            }
        }
        return $redacted;
    }
}
