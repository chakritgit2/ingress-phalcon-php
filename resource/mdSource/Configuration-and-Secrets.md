# Configuration and Secrets

Deliberately different from the sibling hr-advws app, which hardcoded all
secrets (DB password, Google service-account JSON, etc.) directly into a
committed `app/config/config.php`. This project reads everything from the
environment instead.

---

## `app/config/config.php`

Every value is `getenv()`, with a sane default only where one is safe
(`GOOGLE_HD` defaults to `advws.com`, `K8S_NODE_IP` defaults to
`192.168.33.31`). Nothing sensitive has a default. See the file itself for
the full key list; grouped by concern:

| Group | Keys |
|---|---|
| Database | `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` |
| Google OAuth | `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`, `GOOGLE_HD` |
| Session/cookies | `COOKIE_SIGN_KEY` |
| Kubernetes | `K8S_NODE_IP` (display/log address), `SERVER_CONFIG` (path to a kubeconfig file — see below) |

## Where these values actually come from at runtime

In-cluster, split across three Kubernetes objects (see `k8s/`):

- **`ingress-selfservice-config` (`ConfigMap`)** — non-sensitive: node IP,
  base URI, `GOOGLE_HD`, the OAuth redirect URI (a public-facing value by
  nature), and arguably `GOOGLE_CLIENT_ID` (Google itself doesn't treat
  client IDs as secret, though the current `configmap.yaml` leaves a note
  that it could move to the Secret if the team prefers keeping the Secret
  as the single place all Google-related config lives).
- **`ingress-selfservice-secrets` (`Secret`)** — DB credentials, Google
  client secret, cookie signing key. `k8s/secret.example.yaml` documents
  the required keys with placeholder values; the real, filled-in
  `secret.yaml` is gitignored and must never be committed.
- **`ingress-selfservice-kubeconfig` (`Secret`)** — a single `kubeconfig`
  key holding a full kubeconfig YAML document (cluster server + CA +
  ServiceAccount token). Mounted as a **file**, not exposed as env vars —
  see below.

The first two are wired into the Pod via `envFrom` in `k8s/deployment.yaml`
and `k8s/cronjob.yaml`, so `getenv()` in PHP sees them as ordinary process
environment variables — no PHP-side secret-fetching logic needed.

## The Kubernetes credential: a mounted kubeconfig file, not an env var

The Kubernetes API bearer token itself is never an env var — only the
*path* to it is (`SERVER_CONFIG=/etc/ingress-selfservice/kubeconfig`). The
actual kubeconfig content (server URL, CA, token) comes from the
`ingress-selfservice-kubeconfig` Secret mounted as a volume in
`k8s/deployment.yaml`/`k8s/cronjob.yaml`. `App\Services\KubeconfigLoader`
reads and parses that file at request/task time. See
[Kubernetes-Integration.md](Kubernetes-Integration.md) for the parsing
details and why this uniform kubeconfig-file approach was chosen over
relying on Kubernetes' automatic per-Pod ServiceAccount token mount.

The token embedded in that kubeconfig must itself be generated from the
`ingress-selfservice` ServiceAccount (`kubectl create token
ingress-selfservice ...` — see the comments in
`k8s/kubeconfig-secret.example.yaml`), so the RBAC scope enforced is still
exactly what `k8s/clusterrole.yaml` grants — this is a different
*transport* for the credential, not a different *identity*.

## Local development without a cluster

Point `SERVER_CONFIG` at any kubeconfig with a token-based user entry —
a developer's own `~/.kube/config` works fine, or a purpose-generated one
scoped to a test ServiceAccount.

## `APP_ENV=local` dev-only fallbacks (no cluster, no Google OAuth)

For the docker-compose mockup (no real cluster and no real Google OAuth app
configured), two fallbacks activate only when `APP_ENV=local` — neither
takes effect once real config is present, and both must not be enabled in
production:

- **`MockKubernetesService`** (`app/services/MockKubernetesService.php`) —
  wired into DI instead of the real `KubernetesService` when `APP_ENV=local`
  **and** `SERVER_CONFIG` is empty, in both `app/config/services.php` (web)
  and `app/console.php` (CLI, so `processCommands`/`pruneExpired` are also
  testable this way — see
  [../mdSourceWorkflow/05-Command-Processing-Workflow.md](../mdSourceWorkflow/05-Command-Processing-Workflow.md)).
  Returns fixed fake namespaces/deployments and random fake NodePort/UID
  values instead of calling Kubernetes at all.
- **Mock login** — `LoginController::mockLoginAction()` (route
  `GET /login/mock?email=...`), guarded by the same `APP_ENV=local` check,
  logs in as an existing user by email without going through Google OAuth.

Database credentials are never mocked — a real (if local/throwaway) MySQL
instance is still required in every environment, including this one.
