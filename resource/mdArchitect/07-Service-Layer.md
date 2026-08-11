# Service Layer

This document details the service classes that encapsulate OAuth,
Kubernetes API access, audit logging, and request orchestration.

---

## Service Inventory

| Service | DI Registration | Purpose |
|---|---|---|
| `GoogleAuthService` | `googleAuthService` (shared) | OAuth2 client for Google SSO |
| `AuthService` | `authService` (shared) | Session handling + find-or-create user |
| `KubeconfigLoader` | not DI-registered (static) | Parses the `SERVER_CONFIG` kubeconfig file into host/port/token/CA |
| `KubernetesClient` | `kubernetesClient` (shared) | Raw REST wrapper around the K8s API |
| `KubernetesService` | `kubernetesService` (shared, unless mocked — see below) | Domain operations built on the client |
| `MockKubernetesService` | `kubernetesService` (shared, dev-only) | Drop-in stand-in for `KubernetesService` when `APP_ENV=local` and no `SERVER_CONFIG` is set — see [../mdSource/Configuration-and-Secrets.md](../mdSource/Configuration-and-Secrets.md) |
| `AuditLogService` | `auditLogService` (shared) | Append-only audit ledger writer |
| `IngressRequestService` | `ingressRequestService` (shared) | Validates input and enqueues create/delete intent — does **not** call Kubernetes (see below) |

`KubernetesService` and `MockKubernetesService` both implement
`KubernetesServiceInterface`, so every caller (`IngressController`,
`KubernetesTask`) depends on the interface, not a concrete class — that's
what makes the mock swap possible via a single `services.php`/`console.php`
DI factory branch instead of scattering `if` checks through application
code.

All are constructor-injected with their dependencies rather than pulling
services out of the DI container internally — e.g. `IngressRequestService`
takes just the configured node IP as a constructor argument, not
`$di->get(...)` calls inside its methods. This makes every service's
dependencies visible at the registration site in `services.php` rather
than hidden inside the class body.

---

## GoogleAuthService

Thin wrapper around `Google\Client` (the `google/apiclient` package).

```php
public function __construct(string $clientId, string $clientSecret, string $redirectUri, string $hostedDomain)
```

| Method | Purpose |
|---|---|
| `getAuthUrl(string $state): string` | Builds the Google authorize redirect URL |
| `exchangeCode(string $code): array` | Trades an auth code for tokens |
| `verifyIdTokenAndGetClaims(string $idToken): ?array` | Signature/audience/issuer verification via the library, returns claims or null |
| `isAllowedHostedDomain(array $claims): bool` | The actual `hd === 'advws.com'` enforcement point |

Full OAuth flow and the client-hint-vs-server-enforcement distinction in
[../mdSource/Google-SSO-Authentication.md](../mdSource/Google-SSO-Authentication.md).

---

## AuthService

```php
public function __construct(SessionManager $session)
```

| Method | Purpose |
|---|---|
| `findOrCreateByGoogle($sub, $email, $name, $picture, $hostedDomain): Users` | Look up by `google_sub`, create with `role='viewer'` if new, refresh profile fields either way |
| `login(Users $user): void` | `session->set('user_id', $user->id)` |
| `logout(): void` | Remove `user_id`, destroy session |
| `currentUser(): ?Users` | Resolve the session's `user_id` back to a `Users` row |

Unlike hr-advws's `AuthService` (fully built but left commented out of
`services.php`, with `LoginController` doing raw SQL auth instead), this
`AuthService` **is** the live authentication path — there is no parallel,
inconsistent auth mechanism.

---

## KubernetesClient

```php
public function __construct(string $apiHost, int $apiPort, string $bearerToken, string $caCertPath)
```

| Method | Purpose |
|---|---|
| `get(string $path): array` | GET, JSON-decoded |
| `post(string $path, array $body): array` | POST with JSON body |
| `delete(string $path): array` | DELETE |

All three funnel through a private `request()` that catches Guzzle's
`RequestException`/`GuzzleException` and rethrows as
`KubernetesApiException`, unwrapping the Kubernetes API's own
`Status.message` field from the error response body when present — so
callers see "Deployment foo not found" rather than a generic HTTP error.

---

## KubernetesService

Built on `KubernetesClient`; this is the layer that knows about
Kubernetes *resources*, not just HTTP:

| Method | Purpose |
|---|---|
| `listNamespaces(): array` | Names only, for the namespace picker |
| `listDeployments(string $namespace): array` | `{name, replicas}` pairs, for the dependent picker |
| `getDeployment(string $namespace, string $name): ?array` | Full object, null on 404 |
| `createNodePortService($namespace, $deploymentName, $targetPort, $requestId): array` | The core mutating operation, idempotency-guarded on `$requestId` — see [../mdSource/Kubernetes-Integration.md](../mdSource/Kubernetes-Integration.md) |
| `deleteService(string $namespace, string $name): void` | Idempotent — treats "not found" as success |
| `getLastRequest(): ?array` | The `{method, path, body}` of the most recent attempt, for logging into `k8s_commands.request_payload` |

Only `KubernetesTask` calls the two mutating methods now — see
`IngressRequestService` below.

Also owns input validation (`assertValidLabel()` — DNS-1123 regex,
`assertValidPort()` — 1–65535) applied to every namespace/deployment/port
value *before* it's interpolated into a REST path, closing off
injection via a malicious form value.

---

## AuditLogService

```php
public function log(string $eventType, string $actorLabel, array $context = []): AuditLog
```

A single method. `$context` is an associative array of optional keys
(`ingress_request_id`, `actor_user_id`, `namespace`, `deployment_name`,
`node_port`, `node_ip`, `detail`) mapped onto `AuditLog` columns —
`detail` is JSON-encoded if present. Every write is an insert; nothing in
this service ever updates or deletes a row. See
[../mdSource/Audit-Logging-Design.md](../mdSource/Audit-Logging-Design.md).

Unlike hr-advws's `ErrorService` pattern (services return error arrays,
checked with `isError()`), this codebase uses ordinary PHP exceptions —
`AuditLogService` doesn't participate in error handling at all, it's a
pure side-effect writer called from `catch` blocks elsewhere.

---

## IngressRequestService

**Not** an orchestrator that calls Kubernetes anymore — it validates input,
persists an `ingress_requests` row, and enqueues a `k8s_commands` row.
Nothing else. It has neither a `KubernetesService`/`KubernetesServiceInterface`
nor an `AuditLogService` dependency, because it never needs either — both
the actual Kubernetes call and the audit write happen later, in
`KubernetesTask::processCommandsAction()` (see
[../mdSourceWorkflow/05-Command-Processing-Workflow.md](../mdSourceWorkflow/05-Command-Processing-Workflow.md)).

```php
public function __construct(string $nodeIp)
```

| Method | Purpose |
|---|---|
| `create(array $data, Users $user): IngressRequests` | Validate → save `ingress_requests` row (`status='pending'`) → enqueue `k8s_commands` (`action='create'`) → return row |
| `deleteManually(IngressRequests $row, Users $user): void` | Set `status='deleting'` → enqueue `k8s_commands` (`action='delete'`) |
| `retry(IngressRequests $row, Users $user): void` | Look up the row's most recent `k8s_commands` action → reset status (`pending`/`deleting`), clear `last_error` → enqueue a fresh command repeating that action |

All three only ever throw on *validation* failures (bad input) or a DB
save failure — there is no Kubernetes API exception to catch here, since
this service never calls it. The calling controller (`IngressController`)
is still responsible for turning any exception into a flash message. Full
step-by-step trace in
[../mdSourceWorkflow/02-Create-Ingress-Request-Workflow.md](../mdSourceWorkflow/02-Create-Ingress-Request-Workflow.md),
[../mdSourceWorkflow/04-Manual-Deletion-Workflow.md](../mdSourceWorkflow/04-Manual-Deletion-Workflow.md),
and
[../mdSourceWorkflow/05-Command-Processing-Workflow.md](../mdSourceWorkflow/05-Command-Processing-Workflow.md).
