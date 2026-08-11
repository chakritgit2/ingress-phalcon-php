# Routing and Dispatcher

This document details the (flat, non-tenant) routing table and the
events-manager-based auth middleware that gates every request. Replaces
what would be a "multi-tenant" document in a larger Phalcon app — this
tool has no tenancy concept at all.

---

## Router Configuration

`app/config/router.php` builds a `Phalcon\Mvc\Router` directly (not pulled
from the DI container's default):

```php
$router = new Router(false);           // false = don't register default routes
$router->removeExtraSlashes(true);
$router->setDefaultNamespace('App\\Controllers');
```

Every route is declared explicitly, flat — no `RouterGroup`, no dynamic
`{tenant_slug}` prefix, because there is exactly one tenant: this
deployment.

## Route Table

| Method | Path | Controller | Action |
|---|---|---|---|
| GET | `/` | ingress | index |
| GET | `/login` | login | index |
| GET | `/login/google` | login | google |
| GET | `/login/google/callback` | login | googleCallback |
| POST | `/logout` | login | logout |
| GET | `/ingress` | ingress | index |
| GET | `/ingress/create` | ingress | create |
| GET | `/ingress/api/deployments` | ingress | deploymentsApi |
| POST | `/ingress/store` | ingress | store |
| POST | `/ingress/{id:[0-9]+}/delete` | ingress | delete |
| GET | `/audit` | audit | index |
| GET | `/audit/{id:[0-9]+}` | audit | show |
| (404 fallback) | — | error | notFound |

Every route is defined directly in `router.php` — there is no separate
"public routes" vs. "tenant routes" split. What differs per route is
handled entirely by the dispatcher's auth middleware, not by which routes
are registered.

---

## AuthMiddleware — The Single Guard Point

`app/middleware/AuthMiddleware.php`, attached to `dispatch:beforeExecuteRoute`
in `app/config/services.php` (see
[02-Config-and-DI-Services.md](02-Config-and-DI-Services.md#dispatcher--the-intentional-deviation)).
This is the **one place** authentication and authorization are enforced —
individual controllers never re-check the session themselves.

```php
class AuthMiddleware extends Injectable
{
    private const PUBLIC_CONTROLLERS = ['login', 'error'];
    private const DEVOPS_ONLY_CONTROLLERS = ['ingress', 'audit'];

    public function beforeExecuteRoute(Event $event, Dispatcher $dispatcher): bool
    {
        $controller = $dispatcher->getControllerName();

        if (in_array($controller, self::PUBLIC_CONTROLLERS, true)) {
            return true;
        }

        $user = $this->getDI()->get('authService')->currentUser();

        if ($user === null || !$user->is_active) {
            $dispatcher->forward(['controller' => 'login', 'action' => 'index']);
            return false;
        }

        if (in_array($controller, self::DEVOPS_ONLY_CONTROLLERS, true) && !$user->isDevops()) {
            $this->getDI()->get('flash')->error('บัญชีของคุณไม่มีสิทธิ์ devops สำหรับหน้านี้');
            $dispatcher->forward(['controller' => 'login', 'action' => 'index']);
            return false;
        }

        return true;
    }
}
```

### Three-tier gate, controller-name based

1. **`login`, `error`** — always accessible, no session required (you
   can't log in if the login page itself requires being logged in).
2. **Everything else** — requires a valid, active session user. An
   inactive or missing session forwards to `login/index` and halts
   dispatch (`return false` stops the original action from ever running).
3. **`ingress`, `audit`** specifically — additionally requires
   `role === 'devops'`. A `viewer` gets a flash error and the same
   forward-to-login treatment.

Full reasoning for why SSO success alone isn't enough to reach tier 3 is in
[../mdSource/RBAC-and-Authorization.md](../mdSource/RBAC-and-Authorization.md).

### Why controller-name string matching instead of an ACL component

With only 4 controllers total, a full `Phalcon\Acl` resource/role matrix
would be more machinery than the problem needs. Two `const` arrays checked
with `in_array()` are the entire policy, and adding a 5th controller means
deciding which one of those two arrays (or neither) it belongs in — an
obvious, low-ceremony extension point at this scale.

---

## ControllerBase

`app/controllers/ControllerBase.php` — thin base class every controller
extends:

```php
class ControllerBase extends Controller
{
    protected function currentUser(): ?Users
    {
        return $this->authService->currentUser();
    }

    protected function initialize(): void
    {
        $this->view->setVar('currentUser', $this->currentUser());
    }
}
```

`initialize()` runs once per dispatched request (after `beforeExecuteRoute`
has already gated access), and only does one thing: exposes the current
user to every Volt template so the layout can render the logged-in email
and role without every controller action setting that variable itself. See
[06-Controller-Patterns.md](06-Controller-Patterns.md).
