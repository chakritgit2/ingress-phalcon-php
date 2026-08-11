# Workflow: Google SSO Login

End-to-end trace of a login attempt, success and failure branches. See
[../mdSource/Google-SSO-Authentication.md](../mdSource/Google-SSO-Authentication.md)
for the design rationale behind each step.

---

## Happy path

1. User visits any page while logged out → `AuthMiddleware::beforeExecuteRoute()`
   finds no `session->get('user_id')`, forwards to `LoginController::indexAction()`.
2. `login/index.volt` renders a single "เข้าสู่ระบบด้วย Google" button linking
   to `/login/google`.
3. `LoginController::googleAction()`:
   - generates a random `state` (`bin2hex(random_bytes(16))`)
   - stores it in `session->set('oauth_state', $state)`
   - redirects to `GoogleAuthService::getAuthUrl($state)`
4. User authenticates with Google, consents, Google redirects to
   `/login/google/callback?code=...&state=...`.
5. `LoginController::googleCallbackAction()`:
   1. Reads `state`/`code` from the query string, `oauth_state` from
      session, immediately removes `oauth_state` from session (single use).
   2. `hash_equals($expectedState, $state)` — if this fails (missing,
      tampered, or replayed), flash an error and redirect to `/login`. No
      audit entry is written here since no identity has been established yet.
   3. `GoogleAuthService::exchangeCode($code)` → access/id token pair.
   4. `GoogleAuthService::verifyIdTokenAndGetClaims($token['id_token'])` →
      verified claims array (signature/audience/issuer checked by
      `google/apiclient` internally) or `null` on failure.
   5. `GoogleAuthService::isAllowedHostedDomain($claims)` → if false, see
      **Rejection path** below.
   6. `AuthService::findOrCreateByGoogle($sub, $email, $name, $picture, $hd)` —
      looks up `users` by `google_sub`; creates a new row with
      `role='viewer'` if none exists, otherwise refreshes the profile
      fields and `last_login_at` on the existing row.
   7. `AuthService::login($user)` → `session->set('user_id', $user->id)`.
   8. `AuditLogService::log('login', $user->email, ['actor_user_id' => $user->id])`.
   9. Redirect to `/ingress`.

## Rejection path — non-`advws.com` account

If step 5 above finds `claims['hd'] !== 'advws.com'`:

1. `AuditLogService::log('login_rejected', $claims['email'] ?? 'unknown', ['detail' => ['reason' => 'hosted_domain_mismatch', 'hd' => $claims['hd'] ?? null]])`
   — written even though no `users` row exists or is created for this identity.
2. Flash error: "อนุญาตเฉพาะบัญชี Google ของ advws.com เท่านั้น".
3. Redirect to `/login`. No session is established.

## Logout

`POST /logout` → `LoginController::logoutAction()`:
1. If a user is currently logged in, write one more `audit_log` entry
   (`event_type='login'`, `detail={'event':'logout'}` — logout reuses the
   `login` event type rather than adding a dedicated one, since it's not
   part of the six required audit fields and mainly useful for session
   duration forensics).
2. `AuthService::logout()` — clears `user_id` from session and destroys the
   session outright.
3. Redirect to `/login`.
