# Security Practices

This document details the security mechanisms actually implemented in this
codebase — authentication enforcement, CSRF, input validation, secrets
handling, and cluster-facing RBAC. Cross-references the dedicated
[../mdSource/](../mdSource/) docs where a topic has its own deeper writeup.

---

## Security Overview

| Concern | Mechanism | Detail |
|---|---|---|
| Identity | Google OAuth 2.0, `advws.com`-only | [../mdSource/Google-SSO-Authentication.md](../mdSource/Google-SSO-Authentication.md) |
| Authorization | `users.role` allowlist (`devops`/`viewer`) | [../mdSource/RBAC-and-Authorization.md](../mdSource/RBAC-and-Authorization.md) |
| Session | Signed, file-backed, `session->destroy()` on logout | Below |
| CSRF | `Phalcon\Encryption\Security` token on every mutating form | Below |
| Input validation | DNS-1123 label regex + port range, before any K8s API call | [../mdSource/Kubernetes-Integration.md](../mdSource/Kubernetes-Integration.md) |
| Secrets | Environment variables only, nothing hardcoded/committed | [../mdSource/Configuration-and-Secrets.md](../mdSource/Configuration-and-Secrets.md) |
| Cluster access | Scoped `ClusterRole`, no Pod/Secret access | [../mdSource/RBAC-and-Authorization.md](../mdSource/RBAC-and-Authorization.md) |
| Audit | Every mutation logged with the real SSO identity | [../mdSource/Audit-Logging-Design.md](../mdSource/Audit-Logging-Design.md) |

---

## No Password Storage

Unlike hr-advws (which stores password hashes in `admin_users` and has two
parallel, partially-inconsistent auth mechanisms), this app has **no
password storage at all** — the `users` table has no password column.
Identity is entirely delegated to Google; the only thing this app persists
about a user is their Google `sub`, profile fields, and application role.
This eliminates an entire class of risk (hash algorithm choice, brute-force
protection, credential stuffing) by not owning credentials in the first
place.

---

## Session Management

```php
$di->setShared('session', function () use ($config) {
    $session = new SessionManager();
    $files = new SessionStream(['savePath' => sys_get_temp_dir()]);
    $session->setAdapter($files);
    $session->start();
    return $session;
});
```

File-backed (`Phalcon\Session\Adapter\Stream`), same as hr-advws. Session
holds exactly one meaningful key: `user_id` (an integer, not a serialized
user object — `AuthService::currentUser()` re-fetches the `Users` row on
every request, so role/active-status changes take effect immediately
rather than being stale until re-login). `oauth_state` is also
session-stored, but only transiently during the login redirect round-trip
(removed immediately on callback, whether or not it matches).

`AuthService::logout()` calls `session->remove('user_id')` **and**
`session->destroy()` — full session teardown, not just clearing one key.

---

## CSRF Protection

Unlike hr-advws (where CSRF handling is present in code but commented out
in both `LoginController` and `services.php`), CSRF checks are **live**
here on every state-changing POST:

```php
if (!$this->request->isPost() || !$this->security->checkToken()) {
    $this->flash->error('คำขอไม่ถูกต้อง (CSRF)');
    return $this->response->redirect(...);
}
```

Applied in `IngressController::storeAction()` and `::deleteAction()` — the
two actions that mutate cluster state. Every form that posts to them
includes the token:

```twig
<input type="hidden" name="{{ security.getTokenKey() }}" value="{{ security.getToken() }}">
```

The OAuth login flow has its own, separate CSRF-style protection: a random
`state` value round-tripped through the session (see
[../mdSourceWorkflow/01-Google-SSO-Login-Workflow.md](../mdSourceWorkflow/01-Google-SSO-Login-Workflow.md)),
checked with `hash_equals()` rather than `===` to avoid timing side-channels.

---

## Input Validation Before Kubernetes API Calls

Every value that ends up interpolated into a Kubernetes REST path is
validated first — see
[../mdSource/Kubernetes-Integration.md](../mdSource/Kubernetes-Integration.md)
for the exact regex and where it's enforced
(`KubernetesService::assertValidLabel()`/`assertValidPort()`). This is the
closest analog to hr-advws's "parameterized SQL queries" section — the
equivalent risk here isn't SQL injection, it's a crafted namespace/
deployment name reaching an unintended Kubernetes API path.

`IngressRequestService::create()` additionally enforces application-level
bounds that aren't about injection but about sane limits:
`schedule_end_minutes` capped at 10080 (7 days), `target_port` restricted
to 1–65535, `developer_name` required non-empty.

---

## Secrets — Never Hardcoded, Never Committed

`app/config/config.php` reads every credential via `getenv()` — no
default value for anything sensitive (DB password, Google client secret,
cookie sign key). This directly reverses hr-advws's approach of hardcoding
all secrets, including a Google service-account JSON path, directly into
a committed `config.php`. Full breakdown of what's a `ConfigMap` vs.
`Secret` key at deploy time in
[../mdSource/Configuration-and-Secrets.md](../mdSource/Configuration-and-Secrets.md).

The one credential that isn't even an env var — the Kubernetes
ServiceAccount bearer token — is read directly from the path Kubernetes
mounts it at, so it never needs to be provisioned or rotated through this
app's own Secret at all.

---

## Cluster-Facing RBAC

This app's own blast radius against the Kubernetes cluster is scoped by a
`ClusterRole` granting only `get`/`list` on namespaces and deployments and
`get`/`list`/`create`/`delete` on services — no Pod, Secret, ConfigMap, or
Deployment-mutation access. See
[../mdSource/RBAC-and-Authorization.md](../mdSource/RBAC-and-Authorization.md)
for the accepted-risk discussion (cluster-wide scope, chosen for
self-service usability over stricter per-namespace isolation).

---

## What This App Deliberately Does Not Have

- No password hashing/storage (identity is fully delegated to Google)
- No custom encryption layer (no equivalent to hr-advws's `CredentialEncryption` — nothing here needs at-rest encryption of third-party API credentials)
- No remember-me / long-lived auth cookie — a session lasts until the browser session ends or explicit logout
- No public, unauthenticated routes other than the login flow itself
