<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * @group integration
 */
final class BundleWiringTest extends TestCase
{
    public function testBundleExtensionClassExists(): void
    {
        // Minimal integration/smoke check: ensures the bundle extension remains autoloadable.
        // Full Symfony Kernel boot tests should live in the consuming app or in a dedicated
        // symfony-kernel test suite if this bundle already has one.
        $this->assertTrue(class_exists('Gohany\\CircuitBreakerSymfonyBundle\\DependencyInjection\\GohanyCircuitBreakerExtension'));
    }
}
