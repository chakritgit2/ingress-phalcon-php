# Workflow: Create Ingress Request (NodePort)

The core feature — a `devops`-role user turning a Deployment into a
reachable `192.168.33.31:<port>` address. This only covers the web-facing
half (form → enqueue); what actually talks to Kubernetes afterward is a
separate bot, see
[05-Command-Processing-Workflow.md](05-Command-Processing-Workflow.md). See
also
[../mdSource/Kubernetes-Integration.md](../mdSource/Kubernetes-Integration.md)
and
[../mdSource/Audit-Logging-Design.md](../mdSource/Audit-Logging-Design.md)
for the mechanics referenced below.

---

## Steps

1. **`GET /ingress/create`** → `IngressController::createAction()`:
   - `AuthMiddleware` has already confirmed the session user has
     `role='devops'` before this action runs at all.
   - Calls `kubernetesService->listNamespaces()` and renders the namespace
     `<select>` server-side (this one call is still synchronous — it's a
     read, not a mutation, see
     [../mdSource/Kubernetes-Integration.md](../mdSource/Kubernetes-Integration.md)).
   - Prefills the "ใคร (Developer Name)" text input with
     `$this->currentUser()->name` (editable — see the note on this field in
     [../mdSource/Database-Schema.md](../mdSource/Database-Schema.md)).
   - The "ใช้อะไร (Deployment)" `<select>` starts disabled with no options;
     it has nothing to show until a namespace is picked.

2. **Namespace selected (client-side)** — a `change` listener on the
   namespace `<select>` (inline script in `ingress/create.volt`) fires a
   `fetch('/ingress/api/deployments?namespace=...')`.

3. **`GET /ingress/api/deployments?namespace=`** →
   `IngressController::deploymentsApiAction()`:
   - Calls `kubernetesService->listDeployments($namespace)` — also
     synchronous, also a read.
   - Returns `{"deployments": [{"name": ..., "replicas": ...}, ...]}` as
     JSON, or a 400 with `{"error": ...}` if the namespace is invalid or
     unreachable.
   - The client-side script populates the deployment `<select>` and
     re-enables it.

4. **User fills in Port (default 80) and Schedule End (minutes), submits.**

5. **`POST /ingress/store`** → `IngressController::storeAction()`:
   - Rejects immediately (flash error, redirect back) if the request isn't
     POST or fails `$this->security->checkToken()` (CSRF).
   - Delegates everything else to
     `IngressRequestService::create($data, $this->currentUser())`.

6. **`IngressRequestService::create()`** (`app/services/IngressRequestService.php`)
   — this is the part that changed: **it never calls Kubernetes**.
   1. Trims/casts inputs; validates `developer_name` is non-empty,
      `target_port` is 1–65535, `schedule_end_minutes` is between 1 and
      10080 (7 days) — the hardcoded upper guardrail against someone
      fat-fingering an effectively-permanent exposure.
   2. Saves an `ingress_requests` row immediately with `status='pending'`
      and `service_name`/`node_port`/`expires_at` all left `NULL` — there's
      nothing real to fill them with yet.
   3. Enqueues a matching `k8s_commands` row (`action='create'`,
      `status='pending'`, `requested_by_user_id` = the current session
      user's id).
   4. Returns the saved (still-`pending`) row.
   - If step (i)'s validation fails, an exception is thrown before either
     row is ever created — same as before.

7. Back in `storeAction()`: flashes
   `"ส่งคำขอแล้ว กำลังดำเนินการสร้าง Ingress (ดูสถานะได้ที่รายการด้านล่าง)"`
   and redirects to `/ingress`; on any exception (validation failure),
   flashes the error message instead. Either way, redirect — this
   controller never renders a view directly for `storeAction`.

## Why the reachable address can't be shown on submission at all

The NodePort is assigned by the Kubernetes API server at Service-creation
time (see
[../mdSource/Kubernetes-Integration.md](../mdSource/Kubernetes-Integration.md#how-a-nodeport-service-is-constructed))
— and that creation call doesn't even happen during this request anymore.
`storeAction()` returns as soon as the row is queued; the actual `POST` to
Kubernetes happens later, out-of-band, in
[05-Command-Processing-Workflow.md](05-Command-Processing-Workflow.md) (up
to ~1 minute later, per the bot's schedule). The address only becomes
knowable once that separate process succeeds.

## Where this shows up afterward

- `GET /ingress` (`IngressController::indexAction`) — operational list.
  While `status='pending'` the "ออกที่ไหน" column shows a "รอดำเนินการ"
  placeholder instead of an address, and there's no delete button yet
  (nothing exists in the cluster to delete). Once the bot flips it to
  `active`, the real address appears and the "ลบ" (delete) button shows up
  (see [04-Manual-Deletion-Workflow.md](04-Manual-Deletion-Workflow.md)). If
  the bot instead marks it `failed`, a "ลองใหม่" (retry) button appears —
  see [05-Command-Processing-Workflow.md](05-Command-Processing-Workflow.md#retrying-a-failed-command).
- `GET /audit` (`AuditController::indexAction`) — the Log page proper, using
  the same row data mapped to the six Thai-labeled fields (see
  [../mdSource/Audit-Logging-Design.md](../mdSource/Audit-Logging-Design.md)).
- `GET /audit/{id}` — drill-down showing both the `audit_log` trail and the
  raw `k8s_commands` request/response for this row.
- Eventually torn down automatically — see
  [03-Expiry-Sweeper-Workflow.md](03-Expiry-Sweeper-Workflow.md).
