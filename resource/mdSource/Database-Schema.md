# Database Schema

Covers the four tables the ingress self-service tool uses, how they relate,
and why the schema is shaped this way. See migration files under
`app/migrations/` for the authoritative DDL.

---

## Tables

### `users`
One row per Google identity that has ever logged in.

| Column | Notes |
|---|---|
| `google_sub` | Google's stable subject ID — the real foreign key to a Google account, not `email` (emails can theoretically be reassigned; `sub` never is) |
| `email`, `name`, `avatar_url` | Denormalized copy of the Google profile, refreshed on every login |
| `hosted_domain` | The `hd` claim recorded at signup, expected to always be `advws.com` |
| `role` | `devops` \| `viewer`, default `viewer` — see [RBAC-and-Authorization.md](RBAC-and-Authorization.md) |
| `is_active` | Soft kill-switch; `AuthMiddleware` treats an inactive user as logged out |
| `last_login_at` | Updated on every successful login |

Source: `app/models/Users.php`, `app/migrations/0002_create_users.sql`.

### `ingress_requests`
Current-state table — one row per NodePort `Service` the tool has created
(or is still trying to), alive or not. This is what the admin list, the
AJAX pickers, and the sweeper all query.

| Column | Maps to |
|---|---|
| `developer_name` | Thai form field "ใคร" — free text, see note below |
| `namespace` | Thai form field "ที่ Namespace อะไร" |
| `deployment_name` | Thai form field "ใช้อะไร" |
| `target_port` | Thai form field "Port" (default 80) |
| `service_name`, `k8s_uid` | Identity of the actual K8s `Service` object, needed to delete it later — **nullable**, unset until the create command is actually processed (see below) |
| `node_port`, `node_ip` | Together form the "ออกที่ไหน" address, e.g. `192.168.33.31:31234` — `node_port` **nullable** for the same reason |
| `schedule_end_minutes`, `expires_at` | Thai form field "Schedule End" and its resolved absolute deadline — `expires_at` is **nullable** and only computed once the create actually succeeds (`now() + schedule_end_minutes` at *processing* time, not submission time) |
| `created_by_user_id` | FK to `users.id` — the *real* actor, independent of `developer_name` |
| `status` | `pending` (queued, not yet sent) → `active` (created) → `expired` (swept) / `deleted` (manual) / `failed`; or `deleting` (delete queued, not yet sent) → `deleted` |
| `deleted_at`, `deleted_by` | Set by whichever path tore it down (`sweeper` or `manual`) |
| `last_error` | Last Kubernetes API failure, whether from the sweeper or the command processor |

Source: `app/models/IngressRequests.php`, `app/migrations/0003_create_ingress_requests.sql`
(widened to nullable + the `pending`/`deleting` statuses in
`0005_create_k8s_commands.sql`).

### `k8s_commands`
The outbox: one row per Kubernetes-mutating command the app has ever
enqueued, independent of `ingress_requests`' current-state view. Exists so
that "we intend to do X" is durable *before* anything is sent to
Kubernetes — see
[../mdSourceWorkflow/05-Command-Processing-Workflow.md](../mdSourceWorkflow/05-Command-Processing-Workflow.md)
for why.

| Column | Notes |
|---|---|
| `ingress_request_id` | FK to `ingress_requests.id` — every command belongs to exactly one row, which already holds the real parameters (namespace, deployment, port, service_name), so this table doesn't duplicate them |
| `action` | `create` \| `delete` |
| `status` | `pending` → `success` \| `failed` |
| `requested_by_user_id` | FK to `users.id` — the session user who submitted the web request that enqueued this command (never null; the expiry sweeper doesn't go through this table, see [Kubernetes-Integration.md](Kubernetes-Integration.md)) |
| `request_payload` | JSON `{method, path, body}`. Set twice: first as a **preview** by `IngressRequestService::enqueue()` at request time (no Kubernetes call — for `create`, `body.spec.selector`/service name are `null` since those need a live Deployment lookup; for `delete`, already exact), then overwritten by `KubernetesTask` with the literal request actually sent once it processes the command. `NULL` only if both the preview build and the real attempt failed to produce anything. |
| `payload_source` | `preview` \| `sent` \| `NULL` — which of the two writes above `request_payload` currently reflects |
| `result` | JSON snapshot of what Kubernetes returned, on success |
| `error_message` | Set on failure |
| `processed_at` | `NULL` while `pending` |

Source: `app/models/K8sCommands.php`, `app/migrations/0005_create_k8s_commands.sql`,
`0006_add_request_payload_to_k8s_commands.sql`,
`0012_add_payload_source_to_k8s_commands.sql`.

**Why `developer_name` is separate from `created_by_user_id`**: the form
field is a free-text, editable label (prefilled from the SSO display name,
see [Google-SSO-Authentication.md](Google-SSO-Authentication.md)) so a lead
can create an exposure on behalf of a teammate. It is never authoritative —
`created_by_user_id` (and `audit_log.actor_user_id`) always holds the
verified SSO identity that actually performed the action.

### `audit_log`
Append-only ledger. Never updated, never deleted from the application layer.

| Column | Notes |
|---|---|
| `event_type` | `login`, `login_rejected`, `ingress_create`, `ingress_create_failed`, `ingress_delete`, `ingress_delete_failed` |
| `ingress_request_id` | Nullable — some events (rejected logins) have no associated row |
| `actor_user_id`, `actor_label` | `actor_label` is always populated even when `actor_user_id` is null (e.g. `system:sweeper`, or the email of a rejected non-`advws.com` login attempt) |
| `detail` | JSON blob for anything that doesn't warrant its own column (error messages, rejection reasons) |

Source: `app/models/AuditLog.php`, `app/migrations/0004_create_audit_log.sql`.

## Why three tables instead of one

`ingress_requests` is mutable current-state (its `status` flips over time) and
needs to be cheap to query for the picker/list/sweeper. `audit_log` must
capture events that never produce a persisted row at all (a rejected login,
a failed K8s API call before anything was saved) without forcing the
mutable table into an event-sourced shape. Folding both into one table would
mean either mutating "immutable" audit rows or contorting the state table's
schema to represent things that aren't ingress requests.

`k8s_commands` answers a different question than either of those: not "what
happened" (that's `audit_log`) and not "what's the current state" (that's
`ingress_requests`), but "did we actually finish sending this yet, and if
so what exactly did we send." It's what lets a web request enqueue a
command with **zero** calls to Kubernetes and return immediately, and lets
a separate bot process pick it up, execute it, and record success/failure
durably even if the bot itself crashes mid-flight — see
[../mdSourceWorkflow/05-Command-Processing-Workflow.md](../mdSourceWorkflow/05-Command-Processing-Workflow.md).

## Migration convention

Each schema change is a numbered `NNNN_description.sql` file in
`app/migrations/`, applied in order by `app/migrations/migrate.php`, which
tracks what has already run in a `schema_migrations` table so re-running the
script is a no-op. See [../mdSourceWorkflow](../mdSourceWorkflow) for how
this fits into deployment.
