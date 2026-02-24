![CI](https://github.com/Gohany/circuit-breaker-symfony-bundle/actions/workflows/ci.yml/badge.svg)
[![codecov](https://codecov.io/gh/Gohany/circuit-breaker-symfony-bundle/branch/main/graph/badge.svg)](https://codecov.io/gh/Gohany/circuitbreaker)
# Gohany CircuitBreaker Symfony Bundle

This bundle wires `gohany/circuitbreaker` into Symfony, with first-class support for:

- **Resilience pipelines** (bulkhead → circuit breaker → retry)
- **Bulkheads**
  - fixed concurrency caps
  - percent-based caps (lane shares)
  - weighted caps (priority lanes)
  - optional **soft borrowing** (ignore lane caps until the pool is under load)
- **Doctrine DBAL middleware**
  - run a pipeline around **connect()** (gate new connections)
  - run a pipeline around **queries** (observe failures / contribute to circuit)

This README is intentionally **recipe-first**: pick the implementation that matches the problem you’re solving.

## Install

```bash
composer require gohany/circuitbreaker gohany/circuitbreaker-symfony-bundle
```

## Concepts (what you’re wiring)

- A **bulkhead** limits concurrency (globally and optionally per “lane”).
- A **circuit breaker** denies or probes work when downstream is unhealthy.
- A **retry** policy re-attempts work under controlled rules.
- A **pipeline** is an ordered stack of stages (bulkhead → circuit breaker → retry) applied around an operation.

The bundle focuses on **Symfony configuration + service wiring**. The underlying behaviour lives in the core library (`gohany/circuitbreaker`).

## Quickstart (minimal config)

1) Register a Redis client adapter service

```php
// config/services.php

use Gohany\Circuitbreaker\Util\ExtRedisClient;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $c): void {
    $services = $c->services();

    $services->set('gohany.circuitbreaker.redis_client', ExtRedisClient::class)
        ->args([service('app.redis')]); // `app.redis` is your \Redis service
};
```

2) Configure one pool and one pipeline

```yaml
# config/packages/gohany_circuitbreaker.yaml

gohany_circuitbreaker:
  redis_client_service: 'gohany.circuitbreaker.redis_client'
  key_prefix: 'cb'

  profiles:
    default:
      pools:
        db-main:
          global_max: 200
          mode: weighted
          soft_borrow_utilization_threshold: 0.60
          lanes:
            db.connect:
              weight: 1

      pipelines:
        doctrine_connect:
          stages:
            - { type: bulkhead, pool: db-main }
            - { type: circuit_breaker }
            - { type: retry, retry: 'rtry:a=2;d=25ms;cap=200ms;j=50%' }

      doctrine:
        enabled: true
        connection: default
        connect_pipeline: doctrine_connect
        connect_lane: db.connect
```

## Redis client service

The bundle expects a service that implements:

- `Gohany\Circuitbreaker\Contracts\RedisClientInterface`

If you use `ext-redis` (`\Redis`), you can register the adapter from the core lib:

```php
// services.php
$services->set('gohany.circuitbreaker.redis_client', Gohany\Circuitbreaker\Util\ExtRedisClient::class)
    ->args([service('app.redis')]); // app.redis is your \Redis service
```

## Configuration (profiles + shared pools)

Symfony already supports per-environment config (e.g. `config/packages/prod/…`).

On top of that, this bundle supports **profiles** selected by env var:

- default env var: `GOHANY_CB_PROFILE`
- default profile: `default`

That makes it easy to run different pipelines in `api` vs `worker` while still sharing
**the same Redis-backed pool ids**, so all processes cooperate on the same concurrency budget.

### Example

```yaml
# config/packages/gohany_circuitbreaker.yaml

gohany_circuitbreaker:
  # service id that implements RedisClientInterface
  redis_client_service: 'gohany.circuitbreaker.redis_client'
  key_prefix: 'cb'

  profiles:
    default:
      pools:
        db-main:
          global_max: 200
          mode: weighted
          # ignore lane caps until 60% utilized
          soft_borrow_utilization_threshold: 0.60
          lanes:
            auth.login:
              weight: 1
            payments.charge:
              weight: 8
            reporting:
              weight: 1

      pipelines:
        doctrine_connect:
          stages:
            - { type: bulkhead, pool: db-main }
            - { type: circuit_breaker }
            # Retry can be configured either as a gohany/rtry spec string (recommended):
            # - { type: retry, retry: 'rtry:a=2;d=25ms;cap=200ms;j=50%' }
            # ...or as a legacy map (backward-compatible; mapped internally to a best-effort `rtry:` spec):
            - { type: retry, retry: { max_attempts: 2, base_delay_ms: 25, max_delay_ms: 200, jitter: true } }

        doctrine_query_observe:
          stages:
            # intentionally no bulkhead here if you don't want to block already-connected clients
            - { type: circuit_breaker }

      doctrine:
        enabled: true
        connection: default
        connect_pipeline: doctrine_connect
        query_pipeline: doctrine_query_observe
        connect_lane: db.connect
        query_lane: db.query
```

### Different profile, same pool id (shared capacity)

```yaml
# config/packages/worker/gohany_circuitbreaker.yaml (or use env var GOHANY_CB_PROFILE=worker)

gohany_circuitbreaker:
  profiles:
    worker:
      pools:
        # SAME id: db-main
        db-main:
          global_max: 200
          mode: weighted
          soft_borrow_utilization_threshold: 0.60
          lanes:
            jobs.billing:
              weight: 6
            jobs.reports:
              weight: 1

      pipelines:
        doctrine_connect:
          stages:
            - { type: bulkhead, pool: db-main }
            - { type: circuit_breaker }
```

All processes that point at the same Redis and the same `pool id` share the same
global concurrency cap and lane caps.

## Doctrine semantics (connect-gate, query-observe)

Use **connect_pipeline** to block new connections under load / circuit open.

Use **query_pipeline** to record failures and drive circuit state, without necessarily
blocking already-open connections.

This matches the pattern:

> block connecting, but once connected do not attempt to block; failed queries contribute to circuit state

## Common implementation recipes

### 1) “Protect the DB from connection storms” (connect-gate)

Use a bulkhead + circuit breaker around `connect()`.

- Pros: prevents stampedes and keeps pool size sane under load
- Cons: does not stop already-open connections from issuing queries

```yaml
gohany_circuitbreaker:
  profiles:
    default:
      pipelines:
        doctrine_connect:
          stages:
            - { type: bulkhead, pool: db-main }
            - { type: circuit_breaker }
            - { type: retry, retry: 'rtry:a=2;d=25ms;cap=200ms;j=50%' }

      doctrine:
        enabled: true
        connect_pipeline: doctrine_connect
        connect_lane: db.connect
```

### 2) “Observe query failures to drive circuit state” (query-observe)

Use a circuit breaker around query execution *without* a bulkhead.

- Pros: circuit reacts to real query outcomes
- Cons: doesn’t add a concurrency cap by itself

```yaml
gohany_circuitbreaker:
  profiles:
    default:
      pipelines:
        doctrine_query_observe:
          stages:
            - { type: circuit_breaker }

      doctrine:
        enabled: true
        query_pipeline: doctrine_query_observe
        query_lane: db.query
```

### 3) “Keep API fast while workers drain in the background” (profiles)

Run different profiles per process type while sharing the same Redis-backed pool IDs.

Typical split:

- API: allow `auth.login`, `payments.charge` more capacity
- Worker: allow `jobs.billing` more capacity

Select profile via `GOHANY_CB_PROFILE` (default env var name).

### 4) Lane naming strategies (how to get priority lanes)

Lanes are just strings. Good patterns:

- **Operation names**: `payments.charge`, `auth.login`
- **Transport/type**: `db.connect`, `db.query`, `http.payments`
- **Tenant-aware** (only if you really want per-tenant lane caps): `tenant:123:db.query`

Rule of thumb: prefer **stable, low-cardinality** lanes. If you need tenant isolation, do it deliberately.

### 5) HTTP routes: tie a controller to a pool/lane (bulkhead)

If your “routes” are Symfony HTTP routes, the simplest mapping is:

- **pool** = the shared dependency you’re protecting (e.g. `db-main`)
- **lane** = a stable route identifier (often the Symfony route name)

This bundle supports an opt-in controller docblock tag:

```php
use Symfony\Component\Routing\Annotation\Route;

final class ChargesController
{
    /**
     * @Route("/courses/{courseEntity}/hydra/charges", methods={"GET"})
     * @Bulkhead(pool="db-main", lane="http.courses.charges")
     */
    public function __invoke(): Response
    {
        // ...
    }
}
```

If you omit `lane`, it defaults to Symfony’s `_route` value (the route name):

```php
/**
 * @Route("/courses/{courseEntity}/hydra/charges", name="api_courses_hydra_charges", methods={"GET"})
 * @Bulkhead(pool="db-main")
 */
public function __invoke(): Response
{
}
```

That gives you “bulkheads by route” without needing to manually pass lane strings around.

#### Pool policy examples (fixed number vs percent vs weighted)

All three pool modes are supported in config:

```yaml
gohany_circuitbreaker:
  profiles:
    default:
      pools:
        # Fixed: explicit per-lane hard caps
        api-http:
          global_max: 100
          mode: fixed
          lanes:
            api_courses_hydra_charges: { max_concurrent: 10 }
            api_auth_login:            { max_concurrent: 20 }

        # Percent: lane caps are shares of global_max
        api-http-percent:
          global_max: 100
          mode: percent
          lanes:
            api_auth_login:  { percent: 0.40 }  # ~40 concurrent
            api_reporting:   { percent: 0.10 }  # ~10 concurrent

        # Weighted: relative priority under contention
        api-http-weighted:
          global_max: 100
          mode: weighted
          soft_borrow_utilization_threshold: 0.60
          lanes:
            api_auth_login:  { weight: 8 }
            api_reporting:   { weight: 1 }
```

Notes:

- `mode: weighted` is typically what you want for “priority lanes”.
- `soft_borrow_utilization_threshold` lets low-priority lanes borrow when there’s plenty of capacity, but enforces lane preference under load.

#### “Sub-lanes” (parent share + child weights)

The underlying bulkhead lane policy is **flat** (a lane is just a string). There isn’t a first-class “lane hierarchy” in `PoolPolicy`.

To model *“40% of the DB pool is reserved for hydra traffic, and within hydra the charges route is highest priority”*, compose **two bulkheads**:

1) A **parent** pool that allocates a share of the global budget to a coarse lane (e.g. `hydra`).
2) A **child** pool that allocates that share among routes using `mode: weighted`.

Because this bundle’s controller integration can read **multiple** `@Bulkhead(...)` tags, you can acquire both permits per request.

Example (1800 total DB-concurrency, hydra gets ~40% = 720, charges is top priority within hydra):

```yaml
gohany_circuitbreaker:
  profiles:
    default:
      pools:
        # Parent: split the global DB pool across major traffic classes
        db-queries:
          global_max: 1800
          mode: percent
          lanes:
            hydra:  { percent: 0.40 } # ~720
            other:  { percent: 0.60 } # ~1080

        # Child: hydra-only priority lanes within the hydra share
        db-hydra:
          global_max: 720
          mode: weighted
          soft_borrow_utilization_threshold: 0.60
          lanes:
            hydra.charges: { weight: 10 }
            hydra.list:    { weight: 2 }
            hydra.misc:    { weight: 1 }
```

Controller mapping:

```php
use Symfony\Component\Routing\Annotation\Route;

final class ChargesController
{
    /**
     * @Route("/courses/{courseEntity}/hydra/charges", methods={"GET"})
     *
     * // Parent share: counts this request against the hydra slice
     * @Bulkhead(pool="db-queries", lane="hydra")
     *
     * // Child weights: counts this request against the hydra route-priority pool
     * @Bulkhead(pool="db-hydra", lane="hydra.charges")
     */
    public function __invoke(): Response
    {
        // ...
    }
}
```

If you want the **child lane** to default to the Symfony route name, omit `lane` on the second tag.

### 6) Retry strategies

You have two different “retry” integration points:

#### A) Pipeline-stage retries (YAML-friendly)

In pipeline `stages`, configure a retry stage:

```yaml
pipelines:
  my_pipe:
    stages:
      - { type: retry, retry: 'rtry:a=3;d=50ms;j=15%' }
```

This uses core `Gohany\Circuitbreaker\Resilience\RtryRetryMiddleware`.

#### B) Circuit-breaker retries (object-based policies)

If you’re using the core circuit breaker directly (e.g. HTTP circuit breaker services), a recommended approach is to provide retries via your circuit policy by implementing `RetrySpecProviderInterface`.

See `Resources\\config\\services.php` in this bundle and `vendor\\gohany\\circuitbreaker\\examples.md` in the core library for examples.

### 7) HTTP client: decorate PSR-18

If you have a PSR-18 client (e.g. Symfony’s `Psr18Client`), you can decorate it with circuit breaking.

- Bundle-provided convenience wrapper: `Gohany\CircuitBreakerSymfonyBundle\Http\CircuitBreakerHttpClient`
- Core implementation: `Gohany\Circuitbreaker\Defaults\Http\CircuitBreakingPsr18Client`

For wiring examples, see `Resources\\config\\services.php`.

### 8) Multi-circuit requests (layered protection)

Use this when a single request should coordinate multiple circuits, e.g.:

- Provider reliability circuit (`payments_http`)
- Fraud/lockout circuit (`payments_fraud`)

See the “multi-circuit PSR-18 client” example in `Resources\\config\\services.php`.

### 9) Observability: emit events to logs/metrics

The core library uses an `EmitterInterface` (`gohany.circuitbreaker.emitter` service). Replace it in your app to:

- count `bulkhead.acquire` / `bulkhead.reject`
- track `circuit.open` / `circuit.half_open` / `circuit.closed`
- track retry attempts (`retry.attempt`, `retry.give_up`)

This bundle registers a minimal no-op emitter by default; production apps should replace/decorate it.

### 10) Storage backends: Redis vs PDO

This bundle’s pool/bulkhead story is Redis-first because it’s designed for **multi-process shared capacity**.

For circuit breaker state/history stores, the core library supports multiple backends (including Redis and PDO). If you want SQL-backed state/history (auditable, queryable), wire the PDO stores from the core library.

See:

- `vendor\\gohany\\circuitbreaker\\examples.md` → “Storage backends”
- `Resources\\config\\services.php` → “PDO-backed state + history stores”

## More examples

- Core library cookbook: `vendor\\gohany\\circuitbreaker\\examples.md`
- Bundle wiring reference (PHP DI): `Resources\\config\\services.php`

