# Gohany/Circuitbreaker-symfony-bundle

A thin Symfony bundle that wires the `Gohany/Circuitbreaker` core library into Symfony (5.4+), while keeping the core reusable for CodeIgniter.

## Install

```bash
composer require Gohany/Circuitbreaker Gohany/Circuitbreaker-symfony-bundle
```

Enable the bundle if not using Flex:

```php
// config/bundles.php
return [
  // ...
  Gohany\Circuitbreaker\bundle\GohanyCircuitBreakerBundle::class => ['all' => true],
];
```

## Configuration

Example `config/packages/Gohany_circuitbreaker.yaml`:

```yaml
Gohany_circuitbreaker:
  # Optional (recommended): Redis-backed infra services.
  # If you omit `redis`, you MUST provide `state_store_service`, `history_store_service`, and
  # `probe_gate_service` under each circuit (see PDO example below).
  redis:
    # This service must implement Gohany\Circuitbreaker\Store\Redis\RedisClientInterface
    client_service: 'app.redis_client'
    key_prefix: 'cb'
    use_human_readable_keys: false
    state_default_ttl_ms: 604800000 # 7 days
    bucket_ttl_seconds: 900         # 15 min
    counters_ttl_seconds: 0         # 0 = no expiry (optional)

  # Required: choose a policy + classifier for the default circuit breaker
  default:
    policy_service: 'app.circuit.policy'
    classifier_service: 'app.circuit.classifier'
    side_effect_dispatcher_service: 'app.circuit.side_effects'

    # Optional: override deciders (tagged)
    override_decider_tag: 'Gohany.circuitbreaker.override_decider'

    # Optional: swap infra services (defaults are Redis-backed services registered by the bundle)
    # clock_service: 'Gohany.circuitbreaker.clock.system'
    # probe_gate_service: 'Gohany.circuitbreaker.probe_gate.redis'
    # state_store_service: 'Gohany.circuitbreaker.state_store.redis'
    # history_store_service: 'Gohany.circuitbreaker.history_store.redis'

    # Optional: retry
    retry_executor_service: 'app.rtry_executor'
    # `retry_policy_or_spec` is passed through to the retry executor.
    # When using `Gohany\Circuitbreaker\Integration\Rtry\RtryRetryExecutor`, this must be a
    # `Gohany\Retry\RetryPolicyInterface` (e.g. a `Gohany\Rtry\Impl\RtryPolicy`) or a
    # `Gohany\Circuitbreaker\Integration\Rtry\RetrySpec`.
    # Note: Symfony config only supports scalars here; if you want to use `SaneRetryPolicies::defaultHttp()`
    # (which returns an object), prefer implementing `RetrySpecProviderInterface` on your policy (see below).
    # retry_policy_or_spec: 'rtry:...'

  # Optional: define named circuit breakers
  circuits:
    finix_fraud:
      policy_service: 'app.finix_fraud.policy'
      classifier_service: 'app.finix_fraud.classifier'
      side_effect_dispatcher_service: 'app.circuit.side_effects'

      # Optional: override deciders (tagged)
      override_decider_tag: 'Gohany.circuitbreaker.override_decider'

      # Optional: swap infra services (defaults are Redis-backed services registered by the bundle)
      # clock_service: 'Gohany.circuitbreaker.clock.system'
      # probe_gate_service: 'Gohany.circuitbreaker.probe_gate.redis'
      # state_store_service: 'Gohany.circuitbreaker.state_store.redis'
      # history_store_service: 'Gohany.circuitbreaker.history_store.redis'

      retry_executor_service: 'app.rtry_executor'
      retry_policy_or_spec: 'rtry:...'
```

## Services

- `Gohany.circuitbreaker.default` : `Gohany/Circuitbreaker\Core\CircuitBreakerInterface`
- `Gohany.circuitbreaker.registry` : `Gohany/Circuitbreaker\bundle\Registry\CircuitBreakerRegistry`
- `Gohany.circuitbreaker.http_client` : `Psr\Http\Client\ClientInterface` (optional PSR-18 wrapper; see `Resources/config/services.php`)

## Console commands

This bundle registers a few Symfony Console commands (when `symfony/console` is installed):

### `circuitbreaker:debug`

Lists the circuits known to the bundle and shows the resolved service IDs used for each circuit (stores, probe gate, policy, classifier, etc).

### `circuitbreaker:sanity`

Runs the upstream sanity check (`gohany/circuitbreaker` `Integration\Sanity\SanityCheckRunner`) against a selected circuit.

Example:

```bash
php bin/console circuitbreaker:sanity --circuit=default
```

Notes:

- The upstream sanity runner currently expects a `Gohany\Circuitbreaker\Policy\Http\DefaultHttpCircuitPolicy` (or a subclass).
- You can use `--no-sleep` to skip the wait between OPEN and HALF_OPEN (fast, but less realistic).

### `circuitbreaker:redis:override`

If you configured Redis in the bundle, you can force allow/deny switches in Redis:

```bash
php bin/console circuitbreaker:redis:override allow 'payments_http:stripe.com' --ttl-ms=60000 --reason='maintenance'
php bin/console circuitbreaker:redis:override deny  'payments_http:stripe.com' --ttl-ms=60000 --reason='incident'
```

## Multi-circuit HTTP client (PSR-18)

If you want a single HTTP request to coordinate an **ordered list of circuits** (for example: host/provider reliability + tenant fraud lockout), use the upstream `MultiCircuitBreakingPsr18Client`.

Semantics (high level):

- Pre-checks each circuit via `decide(...)` (in order)
- Sends the request through the inner PSR-18 client (no circuit wraps the call)
- Classifies + `recordOutcome(...)` for each circuit (in order)

### Symfony DI wiring example

```php
use Gohany\Circuitbreaker\Defaults\Http\DefaultMultiHttpCircuitsBuilder;
use Gohany\Circuitbreaker\Defaults\Http\HttpCircuitDefinition;
use Gohany\Circuitbreaker\Defaults\Http\MultiCircuitBreakingPsr18Client;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $configurator): void {
    $services = $configurator->services();

    // Base PSR-18 client (Symfony)
    $services->set('app.http.psr18', \Symfony\Component\HttpClient\Psr18Client::class)
        ->args([service('http_client')]);

    // Example: use two named circuit breakers from the bundle
    // (Make sure you have configured these under `Gohany_circuitbreaker.circuits`.)
    $services->set('app.http.multi_circuit', MultiCircuitBreakingPsr18Client::class)
        ->args([
            service('app.http.psr18'),
            [
                // 1) Reliability circuit (host/provider scoped)
                new HttpCircuitDefinition(
                    service('Gohany.circuitbreaker.reliability'),
                    service('app.reliability.classifier'),
                    'payments_http',
                    false
                ),
                // 2) Fraud/tenant lockout circuit (optionally disabled when tenant id is missing)
                new HttpCircuitDefinition(
                    service('Gohany.circuitbreaker.fraud'),
                    service('app.fraud.classifier'),
                    'payments_fraud',
                    true
                ),
            ],
            service('app.http.multi_circuit.builder'),
        ]);

    $services->set('app.http.multi_circuit.builder', DefaultMultiHttpCircuitsBuilder::class);
};
```

Usage:

```php
// $client is your `MultiCircuitBreakingPsr18Client` service
// $response = $client->sendRequest($request);
```

## Redis client service

If you're using ext-redis, register:

```php
$services->set('app.redis_native', \Redis::class)
  ->factory([\App\RedisFactory::class, 'create']); // or however you build it

$services->set('app.redis_client', \Gohany\Circuitbreaker\Store\Redis\ExtRedisClient::class)
  ->args([service('app.redis_native')]);
```

## PDO (SQL) state + history stores

The core library ships PDO-backed implementations you can use with any PDO-supported database (MySQL, PostgreSQL, SQLite, etc.).

### 1) Create the tables

Use the schema provided by the core library:

- `vendor/gohany/circuitbreaker/src/Store/Pdo/schema.sql`

It creates three tables:

- `circuit_states` (state store)
- `circuit_history` (history store)
- `circuit_probe_gates` (probe gate; recommended when you use half-open probing)

### 2) Wire services (Symfony PHP DI example)

```php
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $configurator): void {
    $services = $configurator->services();

    // Your app PDO connection (adjust DSN/credentials as needed)
    $services->set('app.cb.pdo', \PDO::class)
        ->args([
            'mysql:host=127.0.0.1;dbname=app;charset=utf8mb4',
            'db_user',
            'db_pass',
            [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ],
        ]);

    $services->set('app.cb.state_store.pdo', \Gohany\Circuitbreaker\Store\Pdo\PdoCircuitStateStore::class)
        ->args([
            service('app.cb.pdo'),
            'circuit_states',
        ]);

    $services->set('app.cb.history_store.pdo', \Gohany\Circuitbreaker\Store\Pdo\PdoCircuitHistoryStore::class)
        ->args([
            service('app.cb.pdo'),
            'circuit_history',
            100, // retention limit
        ]);

    $services->set('app.cb.probe_gate.pdo', \Gohany\Circuitbreaker\Store\Pdo\PdoProbeGate::class)
        ->args([
            service('app.cb.pdo'),
            'circuit_probe_gates',
        ]);
};
```

### 3) Point the bundle config at your PDO-backed services

```yaml
Gohany_circuitbreaker:
  default:
    policy_service: 'app.circuit.policy'
    classifier_service: 'app.circuit.classifier'
    side_effect_dispatcher_service: 'app.circuit.side_effects'

    # Swap infra from Redis to PDO
    state_store_service: 'app.cb.state_store.pdo'
    history_store_service: 'app.cb.history_store.pdo'
    probe_gate_service: 'app.cb.probe_gate.pdo'
```

