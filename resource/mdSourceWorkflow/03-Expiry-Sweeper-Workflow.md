# Workflow: Expiry Sweeper

How a "Schedule End" deadline actually results in the NodePort `Service`
disappearing, with no human involved.

---

## Trigger

`k8s/cronjob.yaml` defines a `CronJob` (`ingress-selfservice-sweeper`)
running `schedule: "*/1 * * * *"` (every minute), `concurrencyPolicy: Forbid`
so a slow run can never overlap with the next tick and race on the same
rows. Each run is a fresh, short-lived Pod using the same container image
and the same `serviceAccountName` as the main app (it needs the `services:
delete` RBAC verb — see
[../mdSource/RBAC-and-Authorization.md](../mdSource/RBAC-and-Authorization.md)),
executing:

```
php app/console.php kubernetes processCommands; php app/console.php kubernetes pruneExpired
```

Two separate tasks share this one CronJob rather than each getting their
own: `processCommands` (the command-queue bot — see
[05-Command-Processing-Workflow.md](05-Command-Processing-Workflow.md)) runs
first, then this sweeper. They're joined with `;`, not `&&` — the sweeper
must still run even if `processCommands` fails outright (e.g. a transient
DB error), so expiry enforcement doesn't silently stop for a cycle just
because the other task had a bad run.

`app/console.php` bootstraps a CLI-specific DI container (separate from the
web `services.php` — no session/view/dispatcher needed) and dispatches
`pruneExpired` to `App\Tasks\KubernetesTask::pruneExpiredAction()`.

## What the task does

Unlike the create/manual-delete paths (see
[02-Create-Ingress-Request-Workflow.md](02-Create-Ingress-Request-Workflow.md)
and
[04-Manual-Deletion-Workflow.md](04-Manual-Deletion-Workflow.md)),
`pruneExpiredAction()` does **not** go through the `k8s_commands` outbox —
it calls Kubernetes directly, the same way it always has. It's already a
bot running on its own schedule with no web request to decouple from, so
the outbox pattern (built to solve "a web request died before recording
what it did") doesn't apply here.

`KubernetesTask::pruneExpiredAction()` (`app/tasks/KubernetesTask.php`):

1. Queries `IngressRequests::find()` with
   `status = 'active' AND expires_at <= NOW()`.
2. For each matching row:
   - `kubernetesService->deleteService($row->namespace, $row->service_name)`.
     `deleteService()` treats a 404 ("already gone") as success — this
     covers the case where a devops user manually deleted the same row via
     `POST /ingress/{id}/delete` moments before the sweeper got to it (see
     [04-Manual-Deletion-Workflow.md](04-Manual-Deletion-Workflow.md)).
   - **On success**: sets `status='expired'`, `deleted_at=now()`,
     `deleted_by='sweeper'`, saves the row, writes an `ingress_delete`
     audit entry with actor label `'system:sweeper'`.
   - **On Kubernetes API failure** (anything other than "not found"):
     records the error in `last_error` on the row (leaving `status='active'`
     so the next run retries it), writes an `ingress_delete_failed` audit
     entry instead.
3. Prints a one-line summary per row to stdout/stderr — this is what shows
   up in `kubectl logs` for the CronJob's Pods.

## Why a Kubernetes CronJob instead of an in-process PHP scheduler

- **Stateless**: the only truth about "what's due" lives in MySQL
  (`ingress_requests.expires_at`). A CronJob has nothing to lose on
  restart — it just re-queries.
- **Survives app Pod restarts/rolling deploys**: an in-process scheduler
  running inside the main web Pod would need to somehow persist or replay
  missed ticks across a deploy; a CronJob just runs independently on its
  own schedule regardless of what's happening to the web Deployment.
- **No daemon to babysit**: nothing needs to be kept alive, monitored for
  crashes, or restarted — each run is a normal, disposable Job Pod.
- **Extends the existing task convention** — `app/tasks/*Task.php` run via
  `console.php` is the same shape as `hr-advws`'s CLI tasks
  (`ApiWorkerTask`, `EmailTask`), just triggered by Kubernetes instead of a
  hypothetical always-on worker process.

## Manual verification

```
kubectl create job --from=cronjob/ingress-selfservice-sweeper manual-sweep-test -n ingress-selfservice
kubectl logs job/manual-sweep-test -n ingress-selfservice
```
runs one sweep immediately without waiting for the next scheduled minute.
