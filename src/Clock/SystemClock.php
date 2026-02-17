<?php

namespace Gohany\Circuitbreaker\bundle\Clock;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;

final class SystemClock implements ClockInterface
{
    private DateTimeZone $tz;

    public function __construct(?string $timezone = 'UTC')
    {
        $this->tz = new DateTimeZone($timezone ?: 'UTC');
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', $this->tz);
    }
}
