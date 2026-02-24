<?php

// This file is intentionally not auto-loaded.
// Many Gohany apps prefer PHP DI configuration in the app repo.
// Use it as a reference for wiring additional integrations like PSR-18 HttpClient decoration.
//
// Note: this bundle also ships Symfony Console commands (auto-registered by the DI extension when
// `symfony/console` is installed):
//   - circuitbreaker:debug
//   - circuitbreaker:sanity
//   - circuitbreaker:redis:override (only when Redis wiring is enabled)

// Example (Symfony): decorate a PSR-18 client with circuit breaking.
//
// Requirements in your app:
//   - a PSR-18 client service (e.g. Symfony's \Symfony\Component\HttpClient\Psr18Client)
//   - a circuit breaker service (e.g. `Gohany.circuitbreaker.default`)
//
// $services->set('app.http.psr18', \Symfony\Component\HttpClient\Psr18Client::class)
//     ->args([service('http_client')]);
//
// $services->set('Gohany.circuitbreaker.http_client', \Gohany\Circuitbreaker\bundle\Http\CircuitbreakerHttpClient::class)
//     ->args([
//         service('app.http.psr18'),
//         service('Gohany.circuitbreaker.default'),
//         'http', // prefix
//     ]);

// -----------------------------------------------------------------------------
// Example (Symfony): multi-circuit PSR-18 client (ordered list of circuits per request)
//
// Use this when you want a single HTTP request to coordinate multiple circuit breakers
// (e.g. provider reliability + tenant fraud lockout).
//
// Requires:
//   - a PSR-18 client service (e.g. Symfony's \Symfony\Component\HttpClient\Psr18Client)
//   - 2+ circuit breaker services (e.g. from `Gohany_circuitbreaker.circuits`)
//   - 2+ outcome classifier services (one per circuit)
//
// use Gohany\Circuitbreaker\Defaults\Http\DefaultMultiHttpCircuitsBuilder;
// use Gohany\Circuitbreaker\Defaults\Http\HttpCircuitDefinition;
// use Gohany\Circuitbreaker\Defaults\Http\MultiCircuitBreakingPsr18Client;
//
// $services->set('app.http.multi_circuit.builder', DefaultMultiHttpCircuitsBuilder::class);
//
// $services->set('app.http.multi_circuit', MultiCircuitBreakingPsr18Client::class)
//     ->args([
//         service('app.http.psr18'),
//         [
//             new HttpCircuitDefinition(
//                 service('Gohany.circuitbreaker.reliability'),
//                 service('app.reliability.classifier'),
//                 'payments_http',
//                 false
//             ),
//             new HttpCircuitDefinition(
//                 service('Gohany.circuitbreaker.fraud'),
//                 service('app.fraud.classifier'),
//                 'payments_fraud',
//                 true
//             ),
//         ],
//         service('app.http.multi_circuit.builder'),
//     ]);

// -----------------------------------------------------------------------------
// Example (Sane HTTP retries with the default rtry policy)
//
// The circuit breaker supports optional retries via the `gohany/rtry` integration.
// A recommended approach is to bake the retry policy into your *circuit policy* by
// implementing `RetrySpecProviderInterface`, so you can use object-based policies
// like `SaneRetryPolicies::defaultHttp()` without needing to serialize them into YAML.
//
// 1) Register a retry executor (Rtry integration)
//
// $services->set('app.circuit.retry_executor', \Gohany\Circuitbreaker\Integration\Rtry\RtryRetryExecutor::class)
//     ->args([
//         service('app.circuit.classifier'),
//     ]);
//
// 2) Implement a circuit policy that provides the default HTTP retry policy
//
// namespace App\Circuit\Policy;
//
// use Gohany\Circuitbreaker\Policy\Http\DefaultHttpCircuitPolicy;
// use Gohany\Circuitbreaker\Integration\Rtry\RetrySpec;
// use Gohany\Circuitbreaker\Integration\Rtry\RetrySpecProviderInterface;
// use Gohany\Circuitbreaker\Defaults\Rtry\SaneRetryPolicies;
// use Gohany\Circuitbreaker\Core\CircuitKey;
// use Gohany\Circuitbreaker\Core\CircuitContext;
//
// final class DefaultHttpPolicyWithRetries extends DefaultHttpCircuitPolicy implements RetrySpecProviderInterface
// {
//     public function getRetrySpec(CircuitKey $key, CircuitContext $context): ?RetrySpec
//     {
//         return new RetrySpec(SaneRetryPolicies::defaultHttp());
//     }
// }
//
// $services->set('app.circuit.policy', \App\Circuit\Policy\DefaultHttpPolicyWithRetries::class);
//
// 3) Point the bundle config at your services
//
// # config/packages/Gohany_circuitbreaker.yaml
// # Gohany_circuitbreaker:
// #   default:
// #     policy_service: 'app.circuit.policy'
// #     classifier_service: 'app.circuit.classifier'
// #     side_effect_dispatcher_service: 'app.circuit.side_effects'
// #     retry_executor_service: 'app.circuit.retry_executor'
//
// If you do NOT implement `RetrySpecProviderInterface`, you can instead configure
// `retry_policy_or_spec` directly. Note: the bundle config currently defines it as
// a scalar, so use a string spec your retry executor understands.

// -----------------------------------------------------------------------------
// Example (PDO-backed state + history stores)
//
// The core library ships PDO store implementations (MySQL/PostgreSQL/SQLite/etc).
// You can swap the bundle's Redis-backed infra by overriding these services in
// `config/packages/Gohany_circuitbreaker.yaml`.
//
// 0) Create tables
//
// See: vendor/gohany/circuitbreaker/src/Store/Pdo/schema.sql
//
// 1) Register a PDO connection
//
// $services->set('app.cb.pdo', \PDO::class)
//     ->args([
//         'mysql:host=127.0.0.1;dbname=app;charset=utf8mb4',
//         'db_user',
//         'db_pass',
//         [
//             \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
//             \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
//         ],
//     ]);
//
// 2) Register PDO-backed infra services
//
// $services->set('app.cb.state_store.pdo', \Gohany\Circuitbreaker\Store\Pdo\PdoCircuitStateStore::class)
//     ->args([service('app.cb.pdo'), 'circuit_states']);
//
// $services->set('app.cb.history_store.pdo', \Gohany\Circuitbreaker\Store\Pdo\PdoCircuitHistoryStore::class)
//     ->args([service('app.cb.pdo'), 'circuit_history', 100]); // retention limit
//
// $services->set('app.cb.probe_gate.pdo', \Gohany\Circuitbreaker\Store\Pdo\PdoProbeGate::class)
//     ->args([service('app.cb.pdo'), 'circuit_probe_gates']);
//
// 3) Point the bundle at your services
//
// # config/packages/Gohany_circuitbreaker.yaml
// # Gohany_circuitbreaker:
// #   default:
// #     state_store_service: 'app.cb.state_store.pdo'
// #     history_store_service: 'app.cb.history_store.pdo'
// #     probe_gate_service: 'app.cb.probe_gate.pdo'

