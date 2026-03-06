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
use Psr\Container\ContainerInterface;

final class ResilientDriver implements Driver
{
    /** @var Driver */
    private $driver;
    /** @var ContainerInterface */
    private $container;
    /** @var EmitterInterface */
    private $emitter;
    /** @var DoctrineLaneResolverInterface */
    private $laneResolver;

    public function __construct(
        Driver $driver,
        ContainerInterface $container,
        EmitterInterface $emitter,
        DoctrineLaneResolverInterface $laneResolver
    ) {
        $this->driver = $driver;
        $this->container = $container;
        $this->emitter = $emitter;
        $this->laneResolver = $laneResolver;
    }

    public function connect(array $params): Connection
    {
        $op = 'db.connect';

        $connectContext = $this->laneResolver->resolveConnectLaneContext();
        $executor = new DoctrineLaneExecutor($this->container);

        $conn = $executor->execute(
            $connectContext,
            $op,
            function () use ($params): Connection {
                return $this->driver->connect($params);
            },
            ['dbal.params' => $this->redactParams($params)]
        );

        return new ResilientDriverConnection(
            $conn,
            $this->container,
            $this->emitter,
            $this->laneResolver
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
