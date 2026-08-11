# Controller Patterns

This document details the controller hierarchy, action naming, JSON
response pattern, and the CSRF-on-every-mutation convention.

---

## Controller Hierarchy

```
Phalcon\Mvc\Controller
└── ControllerBase              (currentUser() helper, sets view "currentUser")
    ├── LoginController         (public — Google SSO)
    ├── IngressController       (devops-only — create/list/delete)
    ├── AuditController         (devops-only — log view + drill-down)
    └── ErrorController         (public — 404)
```

Every controller extends `ControllerBase` — there is no "public vs.
tenant-aware" split like hr-advws's two-tier hierarchy, because access
control here is entirely handled by `AuthMiddleware` at the dispatcher
level (see [03-Routing-and-Dispatcher.md](03-Routing-and-Dispatcher.md)),
not by which base class a controller extends. `ControllerBase` exists only
for the shared `currentUser()` accessor.

---

## Controller Inventory

| Controller | Actions | Purpose |
|---|---|---|
| `LoginController` | index, google, googleCallback, logout | Google SSO flow |
| `IngressController` | index, create, deploymentsApi, store, delete, retry | Admin create/list/delete/retry NodePort exposures (create/delete/retry only *enqueue* — see [../mdSourceWorkflow/05-Command-Processing-Workflow.md](../mdSourceWorkflow/05-Command-Processing-Workflow.md)) |
| `AuditController` | index, show | Log page + per-request drill-down |
| `ErrorController` | notFound | 404 fallback |

---

## Action Naming Conventions

| Action Suffix | HTTP Method | Purpose |
|---|---|---|
| `indexAction` | GET | List view |
| `createAction` | GET | Show create form |
| `storeAction` | POST | Persist new record |
| `deleteAction` | POST | Delete a record (with int id param) |
| `showAction` | GET | Detail/drill-down view (with int id param) |
| `*ApiAction` | GET | JSON-returning endpoint, view disabled implicitly by returning a `Response` |

Consistent with hr-advws's naming convention, minus `edit`/`update`/
`approve`/`reject` — this tool has no editable records (an exposure is
either active or torn down, never modified in place) and nothing to
approve.

---

## JSON API Response Pattern

The one JSON endpoint, `IngressController::deploymentsApiAction()`:

```php
public function deploymentsApiAction()
{
    $namespace = (string) $this->request->getQuery('namespace', 'string', '');
    $this->response->setContentType('application/json');

    try {
        $deployments = $this->kubernetesService->listDeployments($namespace);
        return $this->response->setJsonContent(['deployments' => $deployments]);
    } catch (\Throwable $e) {
        $this->response->setStatusCode(400);
        return $this->response->setJsonContent(['error' => $e->getMessage()]);
    }
}
```

Pattern: set content type, try the operation, return
`$this->response->setJsonContent(...)` directly (which returns the
`Response` object itself) — no `$this->view->disable()` call needed,
because returning a `Response` instance from an action already tells
Phalcon's `Application` to skip view rendering entirely. This is simpler
than hr-advws's pattern of explicitly disabling the view *and* calling
`->send()` inside the action; here, every action either returns a
`Response` or falls through to auto-rendered view output — never both.

---

## Redirect Pattern — Always Return, Never Manually `send()`

Every action that redirects does so by **returning** the redirect:

```php
return $this->response->redirect('/ingress');
```

**Never** by calling `->send()` inside the action and separately
`return`ing. `public/index.php` does `echo $application->handle(...)->getContent();`
— if an action calls `send()` itself, the response is emitted immediately,
and then `Application::handle()` still hands back a `Response` object that
`index.php` would try to output *again*, causing "headers already sent" or
duplicate output. This was an actual bug caught during a code review pass
early in this project (in `LoginController::indexAction()` and
`AuditController::showAction()`) — fixed by consistently `return`ing
`Response` objects and letting `public/index.php` be the only place that
ever emits output.

---

## Flash Messaging

```php
$this->flash->error('บัญชีของคุณไม่มีสิทธิ์ devops สำหรับหน้านี้');
$this->flash->success('ส่งคำขอแล้ว กำลังดำเนินการสร้าง Ingress (ดูสถานะได้ที่รายการด้านล่าง)');
```

Note the success message for `storeAction()` no longer includes the
resulting address (`$row->address()`) — it can't, since the row is still
`status='pending'` at this point and the address isn't known until the
command-processing bot actually creates the Service. See
[../mdSourceWorkflow/02-Create-Ingress-Request-Workflow.md](../mdSourceWorkflow/02-Create-Ingress-Request-Workflow.md#why-the-reachable-address-cant-be-shown-on-submission-at-all).

Rendered in `layouts/main.volt` via `{{ flash.output() }}` — same
Bootstrap-style class mapping (`alert-danger`, `alert-success`, etc.)
configured in `services.php`, resolved automatically inside Volt templates
(see [04-Volt-Templates-and-Views.md](04-Volt-Templates-and-Views.md#service-access-from-templates)).

---

## Request Data Access

```php
// POST with type filtering
$this->request->getPost('developer_name', 'string');
$this->request->getPost('target_port', 'int', 80);

// Query string
$this->request->getQuery('namespace', 'string', '');

// Route parameter (from {id:[0-9]+})
public function deleteAction($id) { ... }
```

Same Phalcon request type-filtering convention as hr-advws — the second
argument sanitizes/casts the value at the framework level before it
reaches application code.
