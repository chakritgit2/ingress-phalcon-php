# Workflow: Command Processing (the outbox bot)

The bot that actually talks to Kubernetes on behalf of the create and
manual-delete workflows. Neither
[02-Create-Ingress-Request-Workflow.md](02-Create-Ingress-Request-Workflow.md)
nor
[04-Manual-Deletion-Workflow.md](04-Manual-Deletion-Workflow.md) call
Kubernetes at all — they only enqueue a `k8s_commands` row. This is where
that intent actually gets carried out.

---

## Why this exists

Before this existed, `IngressRequestService::create()`/`deleteManually()`
called Kubernetes synchronously, inline in the web request, and only wrote
`ingress_requests`/`audit_log` *after* the call returned. If the PHP-FPM
worker died, timed out, or Kubernetes was slow in the window between the
mutation succeeding and the DB write, a real Service could exist in the
cluster with **no trace of it** in the audit trail — the opposite of what a
tool built specifically for a reliable audit trail should do (see
[../mdSource/Audit-Logging-Design.md](../mdSource/Audit-Logging-Design.md)).

The fix is the standard "transactional outbox" pattern: record the
intended command durably first (`k8s_commands`, `status='pending'`), then
have a separate process carry it out and record the outcome — success or
failure — no matter what happens to the original web request.

## Trigger

Same `CronJob` as the expiry sweeper (`k8s/cronjob.yaml`,
`schedule: "*/1 * * * *"`), run first in the combined command — see
[03-Expiry-Sweeper-Workflow.md](03-Expiry-Sweeper-Workflow.md#trigger):

```
php app/console.php kubernetes processCommands
```

dispatches to `App\Tasks\KubernetesTask::processCommandsAction()`. Can also
be run manually/ad hoc (e.g. in local dev without a CronJob, or to force an
immediate pass instead of waiting for the next tick):

```
docker compose exec app php app/console.php kubernetes processCommands
```

## What the task does

`KubernetesTask::processCommandsAction()` (`app/tasks/KubernetesTask.php`):

1. Loads every `k8s_commands` row with `status='pending'`, oldest first.
2. For each command, loads its linked `ingress_requests` row (the actual
   parameters — namespace, deployment, port, service_name — live there, not
   duplicated onto the command row).
3. **`action='create'`**:
   - Calls `kubernetesService->createNodePortService($namespace, $deploymentName, $targetPort, $row->id)`
     — see
     [../mdSource/Kubernetes-Integration.md](../mdSource/Kubernetes-Integration.md#how-a-nodeport-service-is-constructed)
     for the idempotency check this performs before creating anything.
   - On success: fills in `service_name`, `node_port`, `k8s_uid` on the
     `ingress_requests` row, computes `expires_at = now() + schedule_end_minutes`
     **at this point** (not when the row was originally enqueued), sets
     `status='active'`, writes an `ingress_create` audit entry.
4. **`action='delete'`**:
   - Calls `kubernetesService->deleteService($namespace, $serviceName)`.
   - On success: sets `status='deleted'`, `deleted_at`, `deleted_by='manual'`
     on the row, writes an `ingress_delete` audit entry.
5. **On any exception** from either branch: `k8s_commands.status='failed'`
   + `error_message`; `ingress_requests.status='failed'` + `last_error`;
   writes `ingress_create_failed`/`ingress_delete_failed` to `audit_log` —
   mirrors exactly what the old inline `IngressRequestService` code used to
   do, just triggered from here instead.
6. **Regardless of outcome** (`finally` block): reads
   `kubernetesService->getLastRequest()` and stores it as
   `k8s_commands.request_payload` — `null` if the attempt never got far
   enough to actually send anything (e.g. the create's Deployment lookup
   failed before a Service body was ever built). Stamps `processed_at`
   either way.
7. Prints a one-line summary per command to stdout/stderr — this is what
   shows up in `kubectl logs` for the CronJob's Pods.

## Idempotency — why a crash here can't create a duplicate Service

If this process is killed after step 3's `POST` succeeds but before the
`k8s_commands`/`ingress_requests` rows are saved as `active`, the command
is still `status='pending'` and will be picked up again on the next tick.
`createNodePortService()`'s idempotency check (label
`ingress-selfservice.advws.com/request-id=<ingress_requests.id>`) finds the
Service it already created and returns that instead of creating a second,
orphaned one. `deleteService()` needs no equivalent guard — it already
treats "not found" as success, so re-running a delete that actually
succeeded last time is naturally a no-op.

## Retrying a failed command

A `failed` row isn't retried automatically — see
[../mdSourceWorkflow/02-Create-Ingress-Request-Workflow.md](02-Create-Ingress-Request-Workflow.md)
and
[04-Manual-Deletion-Workflow.md](04-Manual-Deletion-Workflow.md#retrying-a-failed-delete).
A devops user has to explicitly click "ลองใหม่" (`POST
/ingress/{id}/retry` → `IngressRequestService::retry()`), which looks at
the most recently enqueued `k8s_commands` row for that `ingress_requests.id`
to determine which action (`create` or `delete`) to repeat, resets the
row's status accordingly (`pending` or `deleting`), clears `last_error`,
and enqueues a fresh `k8s_commands` row — the old failed one is left in
place as history, visible on the `GET /audit/{id}` drill-down.

## What's still synchronous (not part of this queue)

- `listNamespaces()`/`listDeployments()` (the create-form pickers) — reads,
  called directly from the web request. See
  [02-Create-Ingress-Request-Workflow.md](02-Create-Ingress-Request-Workflow.md).
- The expiry sweeper (`pruneExpiredAction()`) — already its own bot on its
  own schedule, calls Kubernetes directly. See
  [03-Expiry-Sweeper-Workflow.md](03-Expiry-Sweeper-Workflow.md).

## Local development without a CronJob

The docker-compose mockup has no scheduler, so `processCommands` has to be
run manually after every create/delete while testing (see
[../mdSource/Configuration-and-Secrets.md](../mdSource/Configuration-and-Secrets.md)
for the `MockKubernetesService` fallback used in that environment — its
`app/console.php` DI wiring mirrors the web `services.php` fallback so this
task is testable without a real cluster).
