<?php

declare(strict_types=1);

namespace Gohany\CircuitBreakerSymfonyBundle\Http\EventSubscriber;

use Gohany\Circuitbreaker\Contracts\BulkheadInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Symfony HttpKernel integration for bulkheads.
 *
 * Opt-in per controller via a docblock tag:
 *
 *   @Bulkhead(pool="db-main", lane="payments.charge")
 *
 * If `lane` is omitted, defaults to Symfony's `_route`.
 */
final class BulkheadControllerSubscriber implements EventSubscriberInterface
{
    private const PERMITS_ATTR = '_gohany_cb_bulkhead_permits';

    /** @var ServiceLocator */
    private $poolLocator;

    public function __construct(ServiceLocator $poolLocator)
    {
        $this->poolLocator = $poolLocator;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => ['onController', 0],
            KernelEvents::RESPONSE => ['onResponse', 0],
            KernelEvents::EXCEPTION => ['onException', 0],
        ];
    }

    public function onController(ControllerEvent $event): void
    {
        $request = $event->getRequest();
        if ($request->attributes->has(self::PERMITS_ATTR)) {
            return;
        }

        $ref = $this->reflectController($event->getController());
        if ($ref === null) {
            return;
        }

        $cfgs = $this->resolveBulkheadTags($ref['class'], $ref['method']);
        if ($cfgs === []) {
            return;
        }

        $permits = [];
        foreach ($cfgs as $cfg) {
            $poolId = (string) ($cfg['pool'] ?? '');
            if ($poolId === '') {
                continue;
            }

            if (!$this->poolLocator->has($poolId)) {
                throw new \InvalidArgumentException('Unknown bulkhead pool id: ' . $poolId);
            }

            $lane = isset($cfg['lane']) ? (string) $cfg['lane'] : null;
            if ($lane === null || trim($lane) === '') {
                $lane = (string) ($request->attributes->get('_route') ?? 'default');
            }

            $timeout = null;
            if (isset($cfg['timeout']) && $cfg['timeout'] !== '') {
                $timeout = (float) $cfg['timeout'];
            }

            /** @var BulkheadInterface $pool */
            $pool = $this->poolLocator->get($poolId);
            $permits[] = $pool->acquire($lane, $timeout);
        }

        if ($permits !== []) {
            $request->attributes->set(self::PERMITS_ATTR, $permits);
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        $this->releasePermit($event->getRequest());
    }

    public function onException(ExceptionEvent $event): void
    {
        $this->releasePermit($event->getRequest());
    }

    private function releasePermit(Request $request): void
    {
        if (!$request->attributes->has(self::PERMITS_ATTR)) {
            return;
        }

        $permits = $request->attributes->get(self::PERMITS_ATTR);
        $request->attributes->remove(self::PERMITS_ATTR);

        if (!is_array($permits)) {
            $permits = [$permits];
        }

        foreach ($permits as $permit) {
            if (is_object($permit) && method_exists($permit, 'release')) {
                $permit->release();
            }
        }
    }

    /**
     * @param mixed $controller
     * @return array{class:\ReflectionClass, method:?\ReflectionMethod}|null
     */
    private function reflectController($controller): ?array
    {
        if (is_array($controller) && isset($controller[0], $controller[1]) && is_object($controller[0]) && is_string($controller[1])) {
            $class = new \ReflectionClass($controller[0]);
            $method = $class->hasMethod($controller[1]) ? $class->getMethod($controller[1]) : null;
            return ['class' => $class, 'method' => $method];
        }

        if (is_object($controller) && method_exists($controller, '__invoke')) {
            $class = new \ReflectionClass($controller);
            $method = $class->getMethod('__invoke');
            return ['class' => $class, 'method' => $method];
        }

        return null;
    }

    /**
     * @return array<int,array{pool?:string,lane?:string,timeout?:string}>
     */
    private function resolveBulkheadTags(\ReflectionClass $class, ?\ReflectionMethod $method): array
    {
        $cfgs = [];
        if ($method !== null) {
            $doc = $method->getDocComment();
            $cfgs = array_merge($cfgs, $this->parseBulkheadTags(is_string($doc) ? $doc : ''));
        }

        $doc = $class->getDocComment();
        return array_merge($cfgs, $this->parseBulkheadTags(is_string($doc) ? $doc : ''));
    }

    /**
     * Parses one-or-more `@Bulkhead(pool="...", lane="...", timeout=0.25)` tags.
     *
     * @return array<int,array<string,string>>
     */
    private function parseBulkheadTags(string $doc): array
    {
        if ($doc === '') {
            return [];
        }

        if (!preg_match_all('/@Bulkhead\(([^)]*)\)/', $doc, $mm)) {
            return [];
        }

        $out = [];
        foreach (($mm[1] ?? []) as $insideRaw) {
            $inside = trim((string) $insideRaw);
            if ($inside === '') {
                $out[] = [];
                continue;
            }

            $pairs = $this->splitCommaPairs($inside);
            $cfg = [];
            foreach ($pairs as $pair) {
                $pos = strpos($pair, '=');
                if ($pos === false) {
                    continue;
                }
                $k = trim(substr($pair, 0, $pos));
                $v = trim(substr($pair, $pos + 1));
                if ($k === '') {
                    continue;
                }
                $cfg[strtolower($k)] = $this->trimQuotes($v);
            }
            $out[] = $cfg;
        }

        return $out;
    }

    /**
     * @return string[]
     */
    private function splitCommaPairs(string $inside): array
    {
        $parts = [];
        $buf = '';
        $inQuote = false;
        $quoteChar = '';
        $len = strlen($inside);

        for ($i = 0; $i < $len; $i++) {
            $ch = $inside[$i];
            if ($inQuote) {
                if ($ch === $quoteChar) {
                    $inQuote = false;
                    $quoteChar = '';
                }
                $buf .= $ch;
                continue;
            }

            if ($ch === '"' || $ch === "'") {
                $inQuote = true;
                $quoteChar = $ch;
                $buf .= $ch;
                continue;
            }

            if ($ch === ',') {
                $t = trim($buf);
                if ($t !== '') {
                    $parts[] = $t;
                }
                $buf = '';
                continue;
            }

            $buf .= $ch;
        }

        $t = trim($buf);
        if ($t !== '') {
            $parts[] = $t;
        }

        return $parts;
    }

    private function trimQuotes(string $v): string
    {
        $v = trim($v);
        if ($v === '') {
            return $v;
        }
        $first = $v[0];
        $last = $v[strlen($v) - 1];
        if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
            return substr($v, 1, -1);
        }
        return $v;
    }
}
