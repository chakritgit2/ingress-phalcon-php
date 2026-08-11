# Google SSO Authentication

How login works, and — more importantly — where the `advws.com`-only
restriction is actually enforced.

---

## Components

| File | Role |
|---|---|
| `app/services/GoogleAuthService.php` | Thin wrapper around `google/apiclient`'s `Google\Client`: builds the auth URL, exchanges the code, verifies the ID token |
| `app/services/AuthService.php` | Session handling + find-or-create against the `users` table |
| `app/controllers/LoginController.php` | Wires the two services together across the OAuth redirect/callback routes |
| `app/middleware/AuthMiddleware.php` | Enforces "must be logged in" / "must be devops" on every request via the dispatcher's events manager |

## The `hd` domain restriction — client hint vs. server enforcement

`GoogleAuthService`'s constructor calls `$client->setHostedDomain('advws.com')`.
**This only affects the Google consent screen UX** (it pre-filters which
accounts Google shows the user) — it is not a security boundary, because
nothing stops a client from hitting the OAuth endpoints directly with a
different account.

The actual enforcement is in `LoginController::googleCallbackAction()`:
after verifying the ID token's signature via
`GoogleAuthService::verifyIdTokenAndGetClaims()`, it explicitly checks
`GoogleAuthService::isAllowedHostedDomain($claims)` (`claims['hd'] === 'advws.com'`)
and rejects the login — writing a `login_rejected` audit entry — if it
doesn't match. This is the only line that matters for the "Google SSO
Server for @advws only" requirement; the `setHostedDomain()` call is UX
polish on top of it.

## Login flow

```
GET /login/google
  → LoginController::googleAction()
  → generates random `state`, stores in session
  → redirects to Google's authorize URL

GET /login/google/callback?code=...&state=...
  → LoginController::googleCallbackAction()
  → 1. compare `state` against session (CSRF protection on the OAuth dance itself)
  → 2. GoogleAuthService::exchangeCode($code) → id_token
  → 3. GoogleAuthService::verifyIdTokenAndGetClaims($idToken) → claims or null
  → 4. GoogleAuthService::isAllowedHostedDomain($claims) → reject + audit if false
  → 5. AuthService::findOrCreateByGoogle($sub, $email, $name, $picture, $hd)
  → 6. AuthService::login($user) → session->set('user_id', ...)
  → 7. AuditLogService::log('login', ...)
  → redirect to /ingress
```

## Identity storage — find-or-create by `sub`, not `email`

`AuthService::findOrCreateByGoogle()` looks up `users` by `google_sub`
(Google's stable, non-reassignable subject identifier), creating a new row
with `role = 'viewer'` if none exists. Every subsequent login refreshes
`email`/`name`/`avatar_url`/`last_login_at` on the existing row rather than
matching by email — this avoids account-confusion if Google email aliasing
or a Workspace rename ever changes the display email.

## New users start with zero privilege

A successful `advws.com` login is necessary but not sufficient to create or
delete anything — see [RBAC-and-Authorization.md](RBAC-and-Authorization.md)
for why `role='viewer'` is the default and how promotion to `devops` works.
