# Volt Templates and Views

This document details view engine configuration, template inheritance, and
the (deliberately small) view layer.

---

## View Engine Configuration

Registered in `app/config/services.php`:

```php
$di->setShared('view', function () use ($config) {
    $view = new View();
    $view->setViewsDir($config->application->viewsDir);
    $view->registerEngines([
        '.volt' => function ($view, $di) use ($config) {
            $volt = new \Phalcon\Mvc\View\Engine\Volt($view, $di);
            $volt->setOptions([
                'compiledPath'      => sys_get_temp_dir() . '/ingress-volt/',
                'compiledSeparator' => '_',
            ]);
            return $volt;
        },
        '.phtml' => \Phalcon\Mvc\View\Engine\Php::class,
    ]);
    return $view;
});
```

No custom Volt compiler functions are registered — unlike hr-advws (which
registers date-formatting, math, and encoding helper functions for its
much larger view set), every value needed in these templates is either a
plain model property or already formatted before it reaches the view (e.g.
`IngressRequests::address()` returns the pre-joined `node_ip:node_port`
string rather than expecting the template to concatenate it).

---

## View Directory Structure

```
app/views/
├── layouts/
│   └── main.volt          # The only layout — no admin/auth/loginwrap split
├── login/
│   └── index.volt
├── ingress/
│   ├── create.volt
│   └── index.volt
└── audit/
    ├── index.volt
    └── show.volt
```

Six templates total. Controller/action → view path resolution follows
Phalcon's default convention (`views/{controller}/{action}.volt`) — no
`$this->view->pick()` overrides anywhere, because there's no case where an
action needs to borrow another controller's view.

Note: actions that only ever redirect or return JSON
(`store`, `delete`, `google`, `googleCallback`, `logout`, `deploymentsApi`)
have **no corresponding `.volt` file** — Phalcon skips auto-rendering
whenever an action returns a `Response` object directly, which is what all
of these do. Only actions that fall through without returning a
`Response` (`index`, `create`, `show` on the success path) render a view.

---

## Layout Inheritance

Uses Volt's `{% extends %}`/`{% block %}` pattern rather than Phalcon's
separate `Phalcon\Mvc\View` "layouts directory" auto-wrapping mechanism:

`layouts/main.volt`:
```twig
<body>
<header>...</header>
<main>
    {{ flash.output() }}
    {% block content %}{% endblock %}
</main>
</body>
```

Every page template:
```twig
{% extends "layouts/main.volt" %}
{% block content %}
...
{% endblock %}
```

This keeps the "what wraps what" relationship explicit and readable inside
each view file itself, rather than implicit in a separate layout-selection
config.

---

## Service Access from Templates

Volt resolves any variable name that isn't an explicit `setVar()` value
against the DI container — so `security`, `flash`, and `url` are usable
directly in templates without controllers passing them through manually:

```twig
<input type="hidden" name="{{ security.getTokenKey() }}" value="{{ security.getToken() }}">
```

used on every state-changing form (`ingress/create.volt`,
`ingress/index.volt`'s delete button) for CSRF protection — see
[08-Security-Practices.md](08-Security-Practices.md).

---

## Client-Side Behavior — One Inline Script

The only JavaScript in the entire app lives inline in
`app/views/ingress/create.volt`: a `change` listener on the namespace
`<select>` that `fetch()`es `GET /ingress/api/deployments?namespace=...`
and populates the dependent deployment `<select>`. See
[../mdSourceWorkflow/02-Create-Ingress-Request-Workflow.md](../mdSourceWorkflow/02-Create-Ingress-Request-Workflow.md)
for the full request/response shape. No JS framework, no bundler, no build
step — a single `<script>` tag, because a two-field cascading dropdown is
the entire client-side interaction surface this tool has.

---

## Styling

A single `<style>` block embedded in `layouts/main.volt` — plain CSS,
system font stack, no CSS framework. Status badges
(`.badge-active`, `.badge-expired`, `.badge-deleted`, `.badge-failed`) and
flash alert classes (`.alert-danger`, `.alert-success`, etc., matching the
`flash` service's configured class names in `services.php`) are the only
non-trivial styling concerns.
