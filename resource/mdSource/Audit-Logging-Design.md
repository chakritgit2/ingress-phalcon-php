# Audit Logging Design

The core requirement driving this whole tool: "all Ingress changes must be
logged with executed user." This document covers how that's actually
guaranteed, not just where the log table lives.

---

## Three places audit information lives

1. **`ingress_requests`** — every row already carries `created_by_user_id`,
   `created_at`, `expires_at`, `deleted_at`, `deleted_by`. For the primary
   "Log" page (`GET /audit`), this is the source of truth, because it
   already has all six fields the spec asked for (ใคร/ใช้อะไร/Namespace/
   ออกที่ไหน/เมื่อไหร/นานเท่าไหร — see
   [../mdSourceWorkflow/02-Create-Ingress-Request-Workflow.md](../mdSourceWorkflow/02-Create-Ingress-Request-Workflow.md)).
2. **`audit_log`** — an append-only ledger, written by
   `AuditLogService::log()`, that also captures events with **no**
   corresponding successful `ingress_requests` row: a rejected login, a
   failed Kubernetes API call before anything was persisted, a failed
   delete. See [Database-Schema.md](Database-Schema.md) for the column
   list.
3. **`k8s_commands`** — not a narrative log like the other two, but the
   literal request/response of every create/delete attempt
   (`request_payload`/`result`/`error_message`). Surfaced alongside the
   `audit_log` trail on the `GET /audit/{id}` drill-down page. See
   [Database-Schema.md](Database-Schema.md#k8s_commands) and
   [../mdSourceWorkflow/05-Command-Processing-Workflow.md](../mdSourceWorkflow/05-Command-Processing-Workflow.md).

Every code path that mutates cluster state or authenticates a user writes
to `audit_log` — there is no create/delete/login path that skips it,
including failure paths (`ingress_create_failed`, `ingress_delete_failed`,
`login_rejected`).

**Where that write happens moved**: `audit_log` entries for
create/delete are no longer written inline by `IngressRequestService` —
that service only ever enqueues a `k8s_commands` row now. The actual
`ingress_create(_failed)`/`ingress_delete(_failed)` audit write happens in
`KubernetesTask::processCommandsAction()`, at the point the command is
actually attempted against Kubernetes (or fails to be). Login events
(`login`, `login_rejected`) are unaffected — those still write inline from
`LoginController`, since there's no async queue involved in authentication.

## The "executed user" is never the free-text form field

This is the part most likely to be gotten wrong, so it's called out
explicitly: `developer_name` on `ingress_requests` is a free-text, editable
label. It is **not** what gets logged as the actor. Every write to
`audit_log` uses `actor_label`/`actor_user_id` sourced from the
authenticated session (`AuthService::currentUser()`), i.e. the verified
Google identity — see [Google-SSO-Authentication.md](Google-SSO-Authentication.md).
Concretely, in `app/tasks/KubernetesTask.php::processCommandsAction()`:

```php
$actorLabel = $command->requestedBy->email ?? 'system:unknown';
// ...
$this->auditLogService->log('ingress_create', $actorLabel, [
    'actor_user_id' => $command->requested_by_user_id,
    ...
]);
```

`$command->requestedBy` resolves `k8s_commands.requested_by_user_id` — set
once, at enqueue time in `IngressRequestService`, from the session user who
submitted the original web request (`$this->currentUser()`), never from
`$data['developer_name']`. By the time the bot processes the command there
is no HTTP session to read from at all, which is exactly why that identity
has to be captured durably in `k8s_commands` up front rather than looked up
later. A lead creating an exposure "for" a teammate still shows up in the
audit trail under their own login.

## System-initiated events

The expiry sweeper (`app/tasks/KubernetesTask.php`) is not a human actor,
so it logs under the literal string `'system:sweeper'` rather than a user
row — `actor_user_id` is left null, `actor_label` carries the system
identity. This keeps `audit_log` queryable by "was this a human or the
sweeper" without a separate boolean column.

## Immutability

Nothing in the codebase updates or deletes an `audit_log` row after
insert — `AuditLogService::log()` only ever inserts. `ingress_requests`
rows, by contrast, are mutated in place (`status`, `deleted_at`, etc.)
because they represent current state, not history; the full history of
what happened to a given request is reconstructed by joining
`ingress_request_id` back into both `audit_log` **and** `k8s_commands` (see
the drill-down view at `GET /audit/{id}`, which renders both as separate
tables — the narrative trail and the raw request/response record).
