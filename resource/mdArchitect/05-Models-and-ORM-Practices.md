# Models and ORM Practices

This document details the four Phalcon MVC models, the relationships
between them, and where deliberate design choices depart from the
hr-advws pattern (UUIDs, multi-tenancy) that don't apply here.

---

## Model Inventory

| Model | Table | Purpose |
|---|---|---|
| `Users` | `users` | One row per Google identity that has logged in |
| `IngressRequests` | `ingress_requests` | Current-state record of every NodePort Service created (or still being created/deleted) |
| `AuditLog` | `audit_log` | Append-only event ledger |
| `K8sCommands` | `k8s_commands` | Outbox — one row per Kubernetes-mutating command enqueued, its literal request/response, and its outcome |

Full column-level schema in
[../mdSource/Database-Schema.md](../mdSource/Database-Schema.md). This
document covers the ORM-level patterns.

---

## Common Pattern

All four models: typed public properties matching table columns,
`setSource()` in `initialize()` to map to the actual table name, and
relationships declared via `hasMany()`/`belongsTo()`:

```php
class Users extends Model
{
    public int $id;
    public string $google_sub;
    // ...
    public function initialize(): void
    {
        $this->setSource('users');
        $this->hasMany('id', IngressRequests::class, 'created_by_user_id', ['alias' => 'ingressRequests']);
    }
}
```

No custom base `ModelBase` class — four models is still too few to justify
one, and none of them share cross-cutting behavior beyond what
`Phalcon\Mvc\Model` already provides.

---

## No UUID System

Unlike hr-advws's `TranslateUuidTrait` (every public-facing reference is a
UUIDv4, translated to/from an internal integer ID on every request),
`ingress_requests` uses its integer `id` directly in URLs
(`POST /ingress/{id:[0-9]+}/delete`, `GET /audit/{id:[0-9]+}`).

**Why this is an acceptable departure here**: the UUID approach exists to
prevent enumeration attacks and hide record counts on a *multi-tenant,
externally-reachable* system. This tool is internal-only, gated behind
Google SSO + a `devops` role check on every route (see
[03-Routing-and-Dispatcher.md](03-Routing-and-Dispatcher.md)), and record
IDs correspond to ephemeral NodePort exposures, not sensitive business
entities — there's nothing meaningfully protected by hiding them.

`IngressRequests` does still generate a `public_id` (UUIDv4, via
`beforeValidationOnCreate()`) as a stable external identifier independent
of the auto-increment `id`, in case a future integration needs to
reference a request without leaking row-count/ordering information — it
just isn't used in routes today.

---

## Relationships

```
Users (1) ──< (many) IngressRequests    [alias: ingressRequests / creator]
IngressRequests (1) ──< (many) AuditLog  [alias: auditEvents / ingressRequest]
IngressRequests (1) ──< (many) K8sCommands [alias: commands / ingressRequest]
Users (1) ──< (many) AuditLog            [alias: actor]
Users (1) ──< (many) K8sCommands         [alias: requestedBy]
```

```php
// IngressRequests.php
$this->belongsTo('created_by_user_id', Users::class, 'id', ['alias' => 'creator']);
$this->hasMany('id', AuditLog::class, 'ingress_request_id', ['alias' => 'auditEvents']);
$this->hasMany('id', K8sCommands::class, 'ingress_request_id', ['alias' => 'commands']);

// AuditLog.php
$this->belongsTo('ingress_request_id', IngressRequests::class, 'id', ['alias' => 'ingressRequest']);
$this->belongsTo('actor_user_id', Users::class, 'id', ['alias' => 'actor']);

// K8sCommands.php
$this->belongsTo('ingress_request_id', IngressRequests::class, 'id', ['alias' => 'ingressRequest']);
$this->belongsTo('requested_by_user_id', Users::class, 'id', ['alias' => 'requestedBy']);
```

Used concretely by the audit drill-down page (`AuditController::showAction()`),
which loads an `IngressRequests` row plus both its associated `AuditLog`
events and `K8sCommands` rows by `ingress_request_id` to render the full
trail — narrative events and raw request/response — for one exposure. Also
by `KubernetesTask::processCommandsAction()`, which reads
`$command->ingressRequest` and `$command->requestedBy` to get the real
parameters and actor identity for a queued command (see
[../mdSourceWorkflow/05-Command-Processing-Workflow.md](../mdSourceWorkflow/05-Command-Processing-Workflow.md)).

---

## No Multi-Tenant Isolation

There is no `company_id`/tenant-scoping column on any table — every row
belongs to the single organization running this tool. This is the direct
model-layer consequence of the routing decision documented in
[03-Routing-and-Dispatcher.md](03-Routing-and-Dispatcher.md): one flat
deployment, no tenant concept anywhere in the stack.

---

## Query Patterns

Almost entirely ORM (`::find()`, `::findFirst()`), not raw SQL — unlike
hr-advws's heavy reliance on raw `$db->fetchAll()` for translation-joined
queries. The one query with a manual `bind` array is the sweeper's
expiry check:

```php
IngressRequests::find([
    'conditions' => 'status = :status: AND expires_at <= :now:',
    'bind' => ['status' => 'active', 'now' => date('Y-m-d H:i:s')],
]);
```

No raw SQL appears anywhere in `app/` outside of `app/migrations/*.sql`
and `migrate.php`'s own bootstrap logic (which necessarily runs before any
ORM metadata exists).
