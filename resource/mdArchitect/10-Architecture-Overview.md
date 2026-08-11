# Architecture Overview

High-level architectural summary of the ingress self-service tool: request
lifecycle, layer separation, cross-cutting concerns, key architectural
decisions, and a file-to-domain reference map.

---

## System Architecture Diagram

```
┌─────────────────────────────────────────────────────────────────┐
│                         Client (Browser)                         │
│          Server-rendered Volt + one inline fetch() script        │
└──────────────────────────┬──────────────────────────────────────┘
                           │ HTTP
┌──────────────────────────▼──────────────────────────────────────┐
│                   public/index.php (Bootstrap)                   │
│  config.php → services.php (DI) → loader.php → router.php        │
│              Phalcon\Mvc\Application->handle()                   │
└──────────────────────────┬──────────────────────────────────────┘
                           │
┌──────────────────────────▼──────────────────────────────────────┐
│              Dispatcher + EventsManager                          │
│         AuthMiddleware::beforeExecuteRoute() — the ONE gate       │
│   public (login, error) → logged-in → devops-only (ingress,audit) │
└──────────────────────────┬──────────────────────────────────────┘
                           │
┌──────────────────────────▼──────────────────────────────────────┐
│                    Controller Layer                              │
│  ├── LoginController    (Google OAuth flow)                      │
│  ├── IngressController  (create/list/delete NodePort exposures)  │
│  ├── AuditController    (log view + drill-down)                  │
│  └── ErrorController    (404)                                    │
└──────────┬───────────────────────────────┬──────────────────────┘
           │                               │
┌──────────▼──────────────────┐  ┌─────────▼──────────────────────┐
│      Service Layer           │  │        Model Layer (ORM)        │
│ ├── GoogleAuthService         │  │  ├── Users                      │
│ ├── AuthService                │  │  ├── IngressRequests            │
│ ├── KubernetesClient/Service   │  │  ├── AuditLog                   │
│ ├── AuditLogService            │  │  └── K8sCommands                │
│ └── IngressRequestService       │  └─────────────┬──────────────────┘
│    (validates + enqueues only) │                │
└──────────┬─────────────┬───────┘                │
           │             │                        │
           │   ┌─────────▼──────────────────┐     │
           │   │  KubernetesTask (bot)        │     │
           │   │  processCommands/pruneExpired│     │
           │   │  — the only caller of the     │     │
           │   │  two mutating K8s methods     │     │
           │   └─────────┬──────────────────┘     │
           │             │                        │
┌──────────▼───┐  ┌──────▼─────────────┐  ┌───────▼───────────────┐
│ Google OAuth │  │ Kubernetes API     │  │ MySQL / MariaDB         │
│ (external)   │  │ (in-cluster, RBAC- │  │ users, ingress_requests,│
│              │  │  scoped ServiceAcct)│  │ audit_log, k8s_commands │
└──────────────┘  └────────────────────┘  └─────────────────────────┘
```

Note the web request path (Controller → `IngressRequestService`) never
reaches the Kubernetes API box directly for create/delete — it only writes
to MySQL. `KubernetesTask`, triggered by a `CronJob` rather than an HTTP
request, is the sole bridge between the two. See
[Key Architectural Decision #7](#7-command-outbox-instead-of-inline-kubernetes-calls)
below.

---

## Request Lifecycle

1. **Browser** sends an HTTP request.
2. **`public/index.php`** — loads config, builds the DI container from
   `services.php`, registers autoloaded namespaces, lazily builds the
   router, constructs `Phalcon\Mvc\Application`, calls `handle()`.
3. **Router** (`app/config/router.php`) — matches the flat route table (no
   tenant prefix — see [03-Routing-and-Dispatcher.md](03-Routing-and-Dispatcher.md)).
4. **Dispatcher's events manager** fires `dispatch:beforeExecuteRoute` →
   `AuthMiddleware` — the single enforcement point for "must be logged
   in" and "must be `devops`," before any controller code runs.
5. **`ControllerBase::initialize()`** — exposes `currentUser` to the view.
6. **Controller action** — delegates to a service
   (`IngressRequestService`, `KubernetesService`, `GoogleAuthService`,
   `AuthService`) for anything beyond simple reads. For create/delete,
   `IngressRequestService` only validates and enqueues a `k8s_commands`
   row here — it does not call Kubernetes; that happens later, out of
   band, in `KubernetesTask` (see "Scheduled Expiry & Command Processing"
   below).
7. **Response** — either a `Response` object returned directly (redirect
   or JSON — skips view rendering), or the action falls through and Volt
   auto-renders `views/{controller}/{action}.volt`.
8. **View** (Volt) — extends `layouts/main.volt`, fills the `content`
   block, renders flash messages.

---

## Layer Separation

### Controller Layer
- **Responsibility:** request handling, delegating to services, choosing
  redirect vs. render.
- **Pattern:** thin — no controller exceeds roughly 80 lines; all real
  logic (validation, Kubernetes calls, audit writes) lives in services.

### Service Layer
- **Responsibility:** OAuth, Kubernetes REST calls, audit writing, request
  validation. Split across two different triggers rather than one: the web
  request path validates and enqueues (`IngressRequestService`); the
  Kubernetes REST calls and audit writes for create/delete only happen from
  `KubernetesTask` (a CLI task, not a service, but architecturally in the
  same role) — see
  [../mdSourceWorkflow/05-Command-Processing-Workflow.md](../mdSourceWorkflow/05-Command-Processing-Workflow.md).
- **Pattern:** constructor-injected dependencies, ordinary exceptions for
  error signaling (no `ErrorService`/error-array convention).

### Model Layer
- **Responsibility:** typed data representation, relationships.
- **Pattern:** plain Phalcon MVC models (4: `Users`, `IngressRequests`,
  `AuditLog`, `K8sCommands`), ORM-first queries, no UUID translation layer,
  no tenant scoping (see
  [05-Models-and-ORM-Practices.md](05-Models-and-ORM-Practices.md)).

### View Layer
- **Responsibility:** presentation only.
- **Pattern:** Volt `{% extends %}`/`{% block %}` inheritance, one shared
  layout, no CSS framework, one inline vanilla-JS script total.

---

## Cross-Cutting Concerns

### Authentication & Authorization
- **Enforcement point:** `AuthMiddleware::beforeExecuteRoute()`, wired via
  the dispatcher's events manager (not ad hoc per-controller checks).
- **Two layers:** SSO identity (`advws.com`-restricted Google login) vs.
  application role (`devops`/`viewer` allowlist) — kept deliberately
  separate. See [../mdSource/RBAC-and-Authorization.md](../mdSource/RBAC-and-Authorization.md).

### Kubernetes Access
- **Enforcement point:** the Pod's own `ServiceAccount` + `ClusterRole`,
  independent of which human is using the web UI.
- **See:** [../mdSource/Kubernetes-Integration.md](../mdSource/Kubernetes-Integration.md).

### Audit Logging
- **Pattern:** append-only `audit_log` table, written from every
  mutation and failure path, always keyed to the verified SSO identity
  rather than any free-text form field.
- **See:** [../mdSource/Audit-Logging-Design.md](../mdSource/Audit-Logging-Design.md).

### Scheduled Expiry & Command Processing
- **Pattern:** one Kubernetes `CronJob` running two stateless CLI tasks
  every minute — `processCommands` (executes queued create/delete
  commands against Kubernetes) then `pruneExpired` (the expiry sweeper).
  Neither is an in-process scheduler or long-running daemon; the schedule
  truth lives entirely in MySQL (`ingress_requests.expires_at` for expiry,
  `k8s_commands.status='pending'` for queued commands).
- **See:** [../mdSourceWorkflow/03-Expiry-Sweeper-Workflow.md](../mdSourceWorkflow/03-Expiry-Sweeper-Workflow.md)
  and
  [../mdSourceWorkflow/05-Command-Processing-Workflow.md](../mdSourceWorkflow/05-Command-Processing-Workflow.md).

---

## Key Architectural Decisions

### 1. "Ingress" Means a NodePort `Service`, Not a K8s `Ingress` Object
- **Decision:** creating an exposure means `POST`ing a `Service` with
  `type: NodePort`, never a `networking.k8s.io/Ingress` resource.
- **Why:** the stated requirement was "no public ingress, use IP address +
  cluster port" — that's the NodePort model, not the hostname-routed
  Ingress-controller model. Documented explicitly because the original
  request's wording ("Ingress https://xxxx") suggested the opposite.
- **Trade-off:** the resulting address is a plain `ip:port`, never an
  `https://` URL with a hostname — reflected directly in the "ออกที่ไหน"
  audit column.

### 2. Free-Text "Developer Name" Separate from the Authenticated Actor
- **Decision:** the form's "ใคร" field is editable, prefilled from the
  SSO display name but never authoritative for audit purposes.
- **Why:** covers the case of one person creating an exposure on behalf
  of a teammate, without ever losing track of who *actually* performed
  the action.
- **Trade-off:** the audit log has to carry both a display label and a
  verified actor reference, rather than a single "who" column.

### 3. Application Role Separate from SSO Domain Check
- **Decision:** passing the `advws.com` `hd` check only proves identity;
  `users.role = 'devops'` is a second, manually-maintained gate.
- **Why:** an SSO domain restriction can plausibly cover a large,
  loosely-controlled group; cluster-mutating power should stay with a
  small, deliberately curated set of people.
- **Trade-off:** every new devops hire needs a manual `UPDATE users SET
  role='devops'` — there's no self-service promotion path by design.

### 4. Cluster-Wide RBAC Instead of Per-Namespace
- **Decision:** one `ClusterRole`, not a `Role`/`RoleBinding` per
  onboarded namespace.
- **Why:** the namespace/deployment picker needs to discover across the
  whole cluster; per-namespace RBAC would require manual onboarding for
  every new namespace, defeating self-service.
- **Trade-off:** a compromised app Pod could create/delete `Service`
  objects in any namespace (though nothing else) — accepted for an
  internal, trusted-team tool.

### 5. Hand-Rolled Kubernetes Client Instead of an SDK
- **Decision:** a ~100-line Guzzle wrapper, not `renoki-co/php-k8s` or
  similar.
- **Why:** only four operations are needed; a small, fully-auditable
  client was judged more appropriate than a large dependency for a tool
  that mutates cluster networking.
- **Trade-off:** any new Kubernetes operation this tool needs in the
  future has to be hand-written rather than pulled from an existing SDK
  method.

### 6. CronJob Sweeper Instead of an In-Process Scheduler
- **Decision:** expiry is enforced by a Kubernetes `CronJob` running a
  stateless CLI task every minute, not a background thread/loop inside
  the web app.
- **Why:** stateless, survives Pod restarts and rolling deploys, no
  daemon to babysit — the schedule truth lives entirely in MySQL.
- **Trade-off:** expiry granularity is capped at roughly one minute
  (the CronJob's tick rate), not sub-second precision.

### 7. Command Outbox Instead of Inline Kubernetes Calls
- **Decision:** `IngressRequestService::create()`/`deleteManually()` never
  call the Kubernetes API — they only validate input and enqueue a
  `k8s_commands` row (`status='pending'`). A separate bot,
  `KubernetesTask::processCommandsAction()` (run by the same `CronJob` as
  the expiry sweeper), is the only thing that actually calls Kubernetes for
  these two operations and records success/failure.
- **Why:** the original synchronous design had a real gap — if the PHP-FPM
  worker died or timed out between "Kubernetes call succeeded" and "we
  wrote that down," a real Service could exist in the cluster with no
  audit trail at all. For a tool whose entire purpose is a reliable audit
  trail, that gap was unacceptable. See
  [../mdSourceWorkflow/05-Command-Processing-Workflow.md](../mdSourceWorkflow/05-Command-Processing-Workflow.md).
- **Trade-off:** the create/delete UX is no longer instant — a user sees
  "queued" immediately and has to check back (up to ~1 minute later) to see
  the real result, instead of an immediate success/failure response. The
  bot itself gains a smaller version of the same problem one level down
  (it could crash between a successful Kubernetes call and recording that
  success), mitigated but not eliminated by the idempotency label check in
  `createNodePortService()` (`deleteService()` is naturally idempotent
  already).

---

## File-to-Domain Reference Map

| Domain | Key Files | Documentation |
|---|---|---|
| **Framework & Stack** | `composer.json`, `Dockerfile` | [01-Framework-and-Stack-Overview.md](01-Framework-and-Stack-Overview.md) |
| **Config & DI** | `app/config/config.php`, `services.php`, `loader.php`, `console.php` | [02-Config-and-DI-Services.md](02-Config-and-DI-Services.md) |
| **Routing & Dispatcher** | `app/config/router.php`, `AuthMiddleware.php`, `ControllerBase.php` | [03-Routing-and-Dispatcher.md](03-Routing-and-Dispatcher.md) |
| **Volt Templates** | `app/views/` | [04-Volt-Templates-and-Views.md](04-Volt-Templates-and-Views.md) |
| **Models & ORM** | `app/models/` (4 models) | [05-Models-and-ORM-Practices.md](05-Models-and-ORM-Practices.md) |
| **Controllers** | `app/controllers/` (4 controllers) | [06-Controller-Patterns.md](06-Controller-Patterns.md) |
| **Service Layer** | `app/services/` (8 classes) | [07-Service-Layer.md](07-Service-Layer.md) |
| **Security** | `AuthMiddleware.php`, `LoginController.php`, `services.php` (security/session) | [08-Security-Practices.md](08-Security-Practices.md) |
| **Deployment** | `Dockerfile`, `k8s/`, `public/index.php` | [09-Deployment-and-Infrastructure.md](09-Deployment-and-Infrastructure.md) |
| **CLI Tasks** | `app/tasks/KubernetesTask.php`, `app/console.php` | [09-Deployment-and-Infrastructure.md](09-Deployment-and-Infrastructure.md) |
| **Migrations** | `app/migrations/` (6 SQL files + `migrate.php`) | [05-Models-and-ORM-Practices.md](05-Models-and-ORM-Practices.md) |

## Domain Documentation Sources

- **[`../mdSource/`](../mdSource/)** — 6 files covering the database
  schema, Google SSO, Kubernetes integration, RBAC/authorization, audit
  logging design, and configuration/secrets.
- **[`../mdSourceWorkflow/`](../mdSourceWorkflow/)** — 5 files tracing the
  login, create, expiry-sweep, manual-deletion, and command-processing
  flows step by step.

These documents cover the business logic and data-flow decisions; this
`mdArchitect/` set (the one you're reading) covers the technical
architecture, framework wiring, and code-level conventions that implement
them.
