# Workflow: Manual Early Deletion

The escape hatch for tearing down a NodePort exposure before its scheduled
end time — e.g. testing finished early, or it was created against the
wrong Deployment by mistake. Like creation, this only covers the web-facing
half (button → enqueue); the actual `deleteService()` call happens later in
the bot, see
[05-Command-Processing-Workflow.md](05-Command-Processing-Workflow.md).

---

## Steps

1. On `GET /ingress` (`IngressController::indexAction`), every row with
   `status = 'active'` renders a "ลบ" (delete) button (see
   `app/views/ingress/index.volt`) behind a JS `confirm()` prompt.
2. **`POST /ingress/{id}/delete`** → `IngressController::deleteAction($id)`:
   - Rejects (flash error, redirect) if not POST or CSRF token check fails.
   - Looks up the row by id; rejects with a flash error if it doesn't
     exist or `status !== 'active'` (prevents double-deleting an already
     pending/deleting/expired/deleted row).
   - Delegates to `IngressRequestService::deleteManually($row, $this->currentUser())`.
3. **`IngressRequestService::deleteManually()`** — like `create()`, this
   never calls Kubernetes itself:
   1. Sets `status='deleting'` and saves the row immediately — this both
      records intent durably and blocks a second delete/retry click from
      racing this one (the `status !== 'active'` guard above now rejects
      it).
   2. Enqueues a `k8s_commands` row (`action='delete'`, `status='pending'`,
      `requested_by_user_id` = the current session user's id).
4. Back in `deleteAction()`: flashes
   `"ส่งคำขอลบแล้ว กำลังดำเนินการ"` and redirects to `/ingress`. Validation
   failures (row not found/not active) flash an error instead — there's no
   way for this step to fail once the row and command both save
   successfully, since nothing has touched Kubernetes yet.

## Retrying a failed delete

If the bot's actual delete attempt later fails (row ends up
`status='failed'`, see
[05-Command-Processing-Workflow.md](05-Command-Processing-Workflow.md)), a
"ลองใหม่" (retry) button appears on that row instead of "ลบ". `POST
/ingress/{id}/retry` → `IngressController::retryAction($id)` →
`IngressRequestService::retry()` looks at the most recent `k8s_commands` row
for this `ingress_request_id`, sees its `action` was `delete`, and
re-enqueues the same action (`status='deleting'` again, new `k8s_commands`
row, old failed one kept as history).

## How this interacts with the sweeper

`deleted_by` distinguishes the two paths (`'manual'` vs `'sweeper'`) on
both the row and implicitly via `audit_log.actor_label`
(`'system:sweeper'` vs the real user's email) — see
[03-Expiry-Sweeper-Workflow.md](03-Expiry-Sweeper-Workflow.md). The sweeper
still calls Kubernetes directly and is unaffected by the command-queue
change described above — it only queries `status = 'active'`, so a row
already moved to `deleting` (or `pending`) by this workflow is invisible to
it, and vice versa: once the sweeper flips a row to `expired`, this
workflow's `status !== 'active'` guard clause rejects a manual delete
attempt on it. Whichever path reads the row as `active` first "wins" the
race; `deleteService()` being idempotent (treats "not found" as success)
means the worst outcome of a narrow race window is a redundant no-op
delete call, never a crash or a double-charge against the K8s API.
