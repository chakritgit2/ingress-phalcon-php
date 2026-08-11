# Configuration and Dependency Injection Services

This document details the configuration files, dependency injection
container setup, and service registrations that form the backbone of the
application.

---

## Configuration Files Overview

| File | Purpose |
|------|---------|
| `app/config/config.php` | Global configuration array — reads `getenv()`, no hardcoded secrets |
| `app/config/services.php` | DI container service registrations (web) |
| `app/config/loader.php` | Namespace-based autoloader registration |
| `app/config/router.php` | Route definitions (see [03-Routing-and-Dispatcher.md](03-Routing-and-Dispatcher.md)) |
| `app/console.php` | CLI bootstrap with its own, separate DI container |

Full rationale for the env-var-only approach (and what goes in a
`ConfigMap` vs. a `Secret` at deploy time) is in
[../mdSource/Configuration-and-Secrets.md](../mdSource/Configuration-and-Secrets.md).
This document covers the DI wiring mechanics; that one covers the
secrets-management decision.

---

## config.php — Global Configuration

Returned as a `Phalcon\Config\Config` object. Every value comes from
`getenv()`, with defaults only where a default is safe:

```php
defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__, 2));
defined('APP_PATH') || define('APP_PATH', BASE_PATH . '/app');
```

### Configuration Sections

#### Database
```php
'database' => [
    'adapter'  => 'Mysql',
    'host'     => getenv('DB_HOST') ?: 'localhost',
    'port'     => (int) (getenv('DB_PORT') ?: 3306),
    'username' => getenv('DB_USER') ?: '',
    'password' => getenv('DB_PASSWORD') ?: '',
    'dbname'   => getenv('DB_NAME') ?: '',
    'charset'  => 'utf8mb4',
]
```

#### Application
Path constants (`controllersDir`, `middlewareDir`, `modelsDir`,
`servicesDir`, `tasksDir`, `migrationsDir`, `viewsDir`) derived from
`APP_PATH`, plus `baseUri` from `BASE_URI` env var (default `/`).

#### Google OAuth
```php
'google' => [
    'client_id'     => getenv('GOOGLE_CLIENT_ID') ?: '',
    'client_secret' => getenv('GOOGLE_CLIENT_SECRET') ?: '',
    'redirect_uri'  => getenv('GOOGLE_REDIRECT_URI') ?: '',
    'hosted_domain' => getenv('GOOGLE_HD') ?: 'advws.com',
]
```

#### Cookie
`sign_key` from `COOKIE_SIGN_KEY` — no default, must be set for sessions to
be trustworthy in production.

#### Kubernetes
```php
'kubernetes' => [
    'node_ip'            => getenv('K8S_NODE_IP') ?: '192.168.33.31',
    'server_config_path' => getenv('SERVER_CONFIG') ?: '',
]
```

`server_config_path` is a path to a kubeconfig file (standard
`~/.kube/config` YAML format) — parsed at DI-construction time by
`App\Services\KubeconfigLoader` to get the actual API host/port/token/CA
(see below, and
[../mdSource/Kubernetes-Integration.md](../mdSource/Kubernetes-Integration.md)).
Unlike `DB_*`/`GOOGLE_*`, there's no separate set of raw host/port/token
env vars — everything Kubernetes-connection-related is bundled into
whatever file `SERVER_CONFIG` points at.

---

## services.php — DI Container Registration

Registers shared services on a `Phalcon\Di\FactoryDefault` instance.

### Registered Services

| Service | Notes |
|---|---|
| `config` | The already-loaded `$config`, just re-exposed as a DI service |
| `url` | `Phalcon\Mvc\Url`, base URI from config |
| `session` | `Phalcon\Session\Manager` + `Stream` adapter, temp-dir backed |
| `security` | `Phalcon\Encryption\Security`, bcrypt work factor 12 |
| `db` | `Phalcon\Db\Adapter\Pdo\Mysql`, built from `config.database` |
| `modelsMetadata` | `Phalcon\Mvc\Model\Metadata\Memory` |
| `flash` | Bootstrap-style CSS classes (`alert alert-danger`, etc.) |
| `cache` | File-based `Phalcon\Storage\Adapter\Stream`, temp-dir backed |
| `view` | Volt engine (`.volt`) + plain PHP engine (`.phtml`) fallback |
| `dispatcher` | **See below — the one deliberately different from hr-advws** |
| `kubernetesClient` | `App\Services\KubernetesClient`, built from `KubeconfigLoader::load($config->kubernetes->server_config_path)` |
| `kubernetesService` | `App\Services\KubernetesService`, wraps `kubernetesClient` |
| `googleAuthService` | `App\Services\GoogleAuthService`, built from `config.google` |
| `authService` | `App\Services\AuthService`, wraps the `session` service |
| `auditLogService` | `App\Services\AuditLogService` |
| `ingressRequestService` | `App\Services\IngressRequestService`, wraps `kubernetesService` + `auditLogService` + the configured node IP |

### `dispatcher` — the intentional deviation

```php
$di->setShared('dispatcher', function () {
    $eventsManager = new EventsManager();
    $eventsManager->attach('dispatch:beforeExecuteRoute', new AuthMiddleware());

    $dispatcher = new Dispatcher();
    $dispatcher->setDefaultNamespace('App\\Controllers');
    $dispatcher->setEventsManager($eventsManager);
    return $dispatcher;
});
```

hr-advws left its `dispatcher`/events-manager registration commented out
and did authentication and tenant resolution ad hoc inside
`TenantBaseController::beforeExecuteRoute()` on each controller instead.
This project wires the events manager for real, so
`AuthMiddleware::beforeExecuteRoute()` (a single class, not duplicated
per-controller logic) runs before *every* action, controller-agnostic. See
[03-Routing-and-Dispatcher.md](03-Routing-and-Dispatcher.md) and
[06-Controller-Patterns.md](06-Controller-Patterns.md).

### Kubernetes service wiring detail

```php
$di->setShared('kubernetesClient', function () use ($config) {
    $kubeconfig = KubeconfigLoader::load($config->kubernetes->server_config_path);
    return new KubernetesClient($kubeconfig['host'], $kubeconfig['port'], $kubeconfig['token'], $kubeconfig['ca_path']);
});
```

Parses the kubeconfig at `SERVER_CONFIG` on every DI build — in-cluster
that's a file mounted from the `ingress-selfservice-kubeconfig` Secret;
locally it can be any kubeconfig with a token-based user entry. See
[../mdSource/Kubernetes-Integration.md](../mdSource/Kubernetes-Integration.md).

---

## loader.php — Autoloader

```php
$loader = new Phalcon\Autoload\Loader();
$loader->setNamespaces([
    'App\\Controllers' => $config->application->controllersDir,
    'App\\Middleware'  => $config->application->middlewareDir,
    'App\\Models'      => $config->application->modelsDir,
    'App\\Services'    => $config->application->servicesDir,
    'App\\Tasks'       => $config->application->tasksDir,
]);
$loader->register();
```

Namespace-based (`setNamespaces()`), not directory-based (`setDirectories()`)
like hr-advws — every class under `app/` has an explicit `App\...`
namespace declaration matching its directory.

**Note**: `composer.json` deliberately has **no** `"App\\": "app/"` PSR-4
entry. The directories are lowercase (`controllers/`, `services/`, ...)
while the namespaces are capitalized (`Controllers`, `Services`, ...), so a
literal PSR-4 mapping to `app/` would be case-mismatched and Composer would
silently skip those classes from its classmap anyway (confirmed —
`composer install` prints a "does not comply with psr-4" warning per class
if that entry is present). This loader is the only thing that resolves
`App\*` classes; both `public/index.php` and `app/console.php` still
`require vendor/autoload.php` first, but only for the actual Composer
dependencies (Guzzle, `google/apiclient`, `symfony/yaml`), not for `App\*`.

---

## console.php — CLI Bootstrap

A separate, self-contained DI bootstrap for the expiry sweeper (and any
future CLI tasks):

- Loads `config.php` directly (same file the web bootstrap uses).
- Registers only what CLI tasks need: `config`, `db`, `kubernetesClient`,
  `kubernetesService`, `auditLogService`, and a minimal `dispatcher`
  (`Phalcon\Cli\Dispatcher`, default namespace `App\Tasks`, no
  events-manager — CLI tasks don't need `AuthMiddleware`).
- Parses `$argv` manually into `task`/`action`/`params`:
  ```
  php app/console.php kubernetes pruneExpired
  ```
  maps to `App\Tasks\KubernetesTask::pruneExpiredAction()`. See
  [../mdSourceWorkflow/03-Expiry-Sweeper-Workflow.md](../mdSourceWorkflow/03-Expiry-Sweeper-Workflow.md).
