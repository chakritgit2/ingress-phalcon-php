# Framework and Stack Overview

This document provides a comprehensive overview of the technology stack,
framework architecture, and project structure of the Kubernetes ingress
self-service tool built on Phalcon PHP.

---

## Technology Stack

| Layer | Technology | Version |
|-------|-----------|---------|
| Backend Framework | Phalcon (c-phalcon) | v5.9.2 |
| Runtime | PHP | >= 8.4 |
| Template Engine | Volt | (bundled with Phalcon 5.x) |
| Database | MariaDB / MySQL | via PDO adapter |
| Frontend | Server-rendered Volt + vanilla JS (`fetch`) | — |
| HTTP client (K8s API) | GuzzleHttp | ^7.9 |
| OAuth client (Google) | `google/apiclient` | ^2.18 |
| Kubeconfig parsing | `symfony/yaml` | ^7.1 |
| Containerization | Docker (single-stage) | `phalconphp/cphalcon:v5.9.2-php8.4` |
| Orchestration | Kubernetes (Deployment, CronJob, RBAC) | — |
| Dependency Manager (PHP) | Composer | — |

There is deliberately no frontend build step (no npm, no CSS framework, no
JS bundler) — the admin UI is a handful of server-rendered forms and tables
with one small inline `fetch()` call for the namespace→deployment dropdown
(see `app/views/ingress/create.volt`). This matches the tool's actual
scope; adding a frontend toolchain here would be complexity the UI doesn't
need.

---

## Project Structure

```
ingress.advws.com/
├── app/
│   ├── config/
│   │   ├── config.php          # getenv()-based config — no secrets committed
│   │   ├── loader.php          # Namespace-based autoloader registration
│   │   ├── router.php          # Route definitions (flat, no tenancy)
│   │   └── services.php        # DI container service registrations
│   ├── console.php             # CLI bootstrap (separate DI, used by the sweeper)
│   ├── controllers/            # 4 controllers (Login, Ingress, Audit, Error)
│   ├── middleware/             # AuthMiddleware (dispatcher events-manager hook)
│   ├── migrations/             # Numbered SQL files + migrate.php runner
│   ├── models/                 # Users, IngressRequests, AuditLog
│   ├── services/               # Google auth, K8s client, audit log, orchestration
│   ├── tasks/                  # KubernetesTask (expiry sweeper)
│   └── views/                  # Volt templates (layouts/, login/, ingress/, audit/)
├── public/
│   └── index.php               # Application entry point
├── k8s/                        # Deployment manifests for THIS app
│   ├── namespace.yaml, serviceaccount.yaml
│   ├── clusterrole.yaml, clusterrolebinding.yaml
│   ├── configmap.yaml, secret.example.yaml
│   ├── deployment.yaml, service.yaml
│   └── cronjob.yaml             # Expiry sweeper schedule
├── resource/
│   ├── mdArchitect/             # This documentation set
│   ├── mdSource/                # Domain documentation (schema, auth, K8s integration, RBAC, audit design)
│   └── mdSourceWorkflow/        # Step-by-step workflow documentation
├── Dockerfile                   # Single-stage build
├── composer.json
└── README.md                    # Setup / deploy / first-user instructions
```

Notably absent compared to a typical Phalcon app: `credentials/`,
`helpers/`, `library/`, `traits/`, `cache/` (source-controlled), and any
frontend `package.json`. None of those were needed for this tool's scope —
see [03-Routing-and-Dispatcher.md](03-Routing-and-Dispatcher.md) for what
replaced the tenant-resolution machinery a larger Phalcon app might have.

---

## Phalcon Framework Architecture

### Key Phalcon Components Used

- **`Phalcon\Mvc\Application`** — Application handler (`public/index.php`)
- **`Phalcon\Di\FactoryDefault`** — Web DI container
- **`Phalcon\Di\FactoryDefault\Cli`** — Separate DI container for CLI tasks
- **`Phalcon\Mvc\Router`** — Flat route definitions, no route groups (no tenancy)
- **`Phalcon\Mvc\Dispatcher`** + **`Phalcon\Events\Manager`** — Wired together
  so `AuthMiddleware::beforeExecuteRoute()` runs on every dispatch
- **`Phalcon\Mvc\View`** + **Volt engine** — `{% extends %}`/`{% block %}`
  template inheritance (see [04-Volt-Templates-and-Views.md](04-Volt-Templates-and-Views.md))
- **`Phalcon\Db\Adapter\Pdo\Mysql`** — Database adapter
- **`Phalcon\Session\Manager`** (Stream adapter) — Session storage
- **`Phalcon\Encryption\Security`** — CSRF token + bcrypt work factor
- **`Phalcon\Flash\Direct`** — Flash messages
- **`Phalcon\Autoload\Loader`** — Namespace-based autoloader (`App\Controllers`, `App\Services`, etc. — see below)
- **`Phalcon\Cli\Console`** / **`Phalcon\Cli\Task`** — The expiry sweeper CLI task

### Application Bootstrap Flow (web)

`public/index.php`:
1. Require `app/config/config.php` → `$config`
2. Require `app/config/services.php` → `$di` (built from `$config`)
3. Require `app/config/loader.php` (registers namespaces on the already-built `$di`)
4. Register the `router` service lazily from `app/config/router.php`
5. Construct `Phalcon\Mvc\Application($di)`, `handle()`, echo the content

### CLI Bootstrap Flow

`app/console.php` builds its **own**, separate `Phalcon\Di\FactoryDefault\Cli`
container — it does not reuse `services.php` — because CLI tasks need `db`,
`kubernetesClient`/`kubernetesService`, and `auditLogService`, but have no
use for `session`, `view`, or the dispatcher's auth middleware. See
[02-Config-and-DI-Services.md](02-Config-and-DI-Services.md).

---

## Composer Dependencies

```json
{
    "require": {
        "php": ">=8.4",
        "guzzlehttp/guzzle": "^7.9",
        "google/apiclient": "^2.18",
        "symfony/yaml": "^7.1"
    },
    "require-dev": {
        "phalcon/ide-stubs": "^5.9"
    }
}
```

Unlike hr-advws (which relied almost entirely on the Phalcon C-extension
itself with virtually no Composer packages), this project pulls in three
real dependencies: Guzzle for the hand-rolled Kubernetes REST client (see
[07-Service-Layer.md](07-Service-Layer.md)), the official Google API
client for OAuth/ID-token verification (see
[../mdSource/Google-SSO-Authentication.md](../mdSource/Google-SSO-Authentication.md)),
and Symfony's YAML component for parsing the kubeconfig file referenced by
`SERVER_CONFIG` (see
[../mdSource/Kubernetes-Integration.md](../mdSource/Kubernetes-Integration.md)).

---

## Docker Image Architecture

Single-stage build (`Dockerfile`), deliberately simpler than hr-advws's
2-stage Node+PHP build with a bundled SSH server:

```
FROM phalconphp/cphalcon:v5.9.2-php8.4
RUN docker-php-ext-install pdo_mysql
composer install --no-dev --optimize-autoloader
COPY . .
chown -R www-data:www-data /app
EXPOSE 9000
CMD ["php-fpm"]
```

No SSH server, no boot-time `git clone`, no dev-mode branching. See
[09-Deployment-and-Infrastructure.md](09-Deployment-and-Infrastructure.md)
for the full deployment picture including the Kubernetes manifests this
image is deployed with.

---

## Database

- **Adapter:** MySQL/MariaDB (via `Phalcon\Db\Adapter\Pdo\Mysql`)
- **Connection:** host/port/name/user/password all from environment
  variables (see [02-Config-and-DI-Services.md](02-Config-and-DI-Services.md))
- **Charset:** utf8mb4
- **ORM:** Phalcon MVC Models with `setSource()` table mapping — see
  [05-Models-and-ORM-Practices.md](05-Models-and-ORM-Practices.md)

Three tables total: `users`, `ingress_requests`, `audit_log`, plus a
`schema_migrations` tracking table. Full column-level detail in
[../mdSource/Database-Schema.md](../mdSource/Database-Schema.md).

---

## External Integrations

| Service | Purpose | Configuration Location |
|---------|---------|------------------------|
| Google OAuth 2.0 | SSO login, restricted to `advws.com` | `config.php` → `google` |
| Kubernetes API server | List namespaces/deployments, create/delete NodePort Services | `config.php` → `kubernetes` (kubeconfig file path via `SERVER_CONFIG`) |

No email service, no message queue, no third-party analytics, no
localization system — this tool's scope is intentionally narrow compared
to hr-advws.
