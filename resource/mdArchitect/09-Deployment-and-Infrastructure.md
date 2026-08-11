# Deployment and Infrastructure

This document details the Docker image, the Kubernetes manifests this app
deploys itself with, and the migration/first-run process.

---

## Dockerfile

Single-stage, deliberately simpler than hr-advws's 2-stage Node+PHP build:

```dockerfile
FROM phalconphp/cphalcon:v5.9.2-php8.4
RUN docker-php-ext-install pdo_mysql
WORKDIR /app
COPY composer.json composer.lock* ./
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && composer install --no-dev --no-interaction --optimize-autoloader
COPY . .
RUN chown -R www-data:www-data /app
EXPOSE 9000
CMD ["php-fpm"]
```

No SSH server, no boot-time `git clone` (hr-advws's `start.sh` did both),
no `development` vs. production branching — the same image is used
unmodified everywhere. Only the `pdo_mysql` PHP extension is installed;
`dom`, `bcmath`, and `amqp` (present in hr-advws's image) aren't needed
since this app has no queue and no XML/arbitrary-precision-math
requirement.

**Open question, not yet resolved**: the image only exposes PHP-FPM on
`:9000`, matching hr-advws's pattern — which implies an nginx sidecar or a
shared cluster ingress-controller config already fronts Phalcon apps like
this one. Confirm with whoever operates the target cluster before first
deploy; see `k8s/deployment.yaml`'s header comment.

---

## Kubernetes Manifests (`k8s/`)

This is the significant addition over hr-advws's infrastructure files —
hr-advws is deployed as a normal web app; this tool *manages* Kubernetes
resources on behalf of others, so it needs its own RBAC identity in
addition to the usual Deployment/Service pair.

| File | Purpose |
|---|---|
| `namespace.yaml` | Creates the `ingress-selfservice` namespace — apply first |
| `serviceaccount.yaml` | The `ingress-selfservice` ServiceAccount this app runs as |
| `clusterrole.yaml` / `clusterrolebinding.yaml` | Grants that ServiceAccount its Kubernetes API permissions — see [../mdSource/RBAC-and-Authorization.md](../mdSource/RBAC-and-Authorization.md) |
| `configmap.yaml` | Non-secret env vars (node IP, base URI, `GOOGLE_HD`, redirect URI) |
| `secret.example.yaml` | Template for the real `secret.yaml` (DB creds, Google client secret, cookie key) — gitignored once filled in |
| `kubeconfig-secret.example.yaml` | Template for `kubeconfig-secret.yaml` — a single `kubeconfig` key holding the full kubeconfig YAML (server + CA + ServiceAccount token), mounted as a **file**, not env vars |
| `deployment.yaml` | The app's own web Pod, `serviceAccountName: ingress-selfservice`, `envFrom` the ConfigMap and Secret, plus a `SERVER_CONFIG` env var + volume mount pointing at the mounted kubeconfig |
| `service.yaml` | `ClusterIP` fronting the app's own UI — unrelated to the ephemeral NodePort Services the app creates for other Deployments |
| `cronjob.yaml` | The expiry sweeper, `schedule: "*/1 * * * *"`, `concurrencyPolicy: Forbid`, same kubeconfig mount as the Deployment — see [../mdSourceWorkflow/03-Expiry-Sweeper-Workflow.md](../mdSourceWorkflow/03-Expiry-Sweeper-Workflow.md) |

### Deploy order

```
kubectl apply -f k8s/namespace.yaml
kubectl apply -f k8s/serviceaccount.yaml
kubectl apply -f k8s/clusterrole.yaml
kubectl apply -f k8s/clusterrolebinding.yaml
kubectl apply -f k8s/configmap.yaml
kubectl apply -f k8s/secret.yaml            # copied from secret.example.yaml, real values filled in
kubectl apply -f k8s/kubeconfig-secret.yaml # copied from kubeconfig-secret.example.yaml — token MUST come from the ingress-selfservice ServiceAccount
kubectl apply -f k8s/deployment.yaml
kubectl apply -f k8s/service.yaml
kubectl apply -f k8s/cronjob.yaml
```

The `ClusterRole`/`ClusterRoleBinding` must exist before the token embedded
in `kubeconfig-secret.yaml` is generated (`kubectl create token
ingress-selfservice ...`), since that command needs the ServiceAccount to
already exist and — for the token to actually be useful — already be bound
to its RBAC permissions.

Namespace and RBAC objects first (nothing else can schedule without
them), Secret/ConfigMap before the Deployment/CronJob that consume them
via `envFrom`.

---

## Migrations

`app/migrations/migrate.php` — a single generic PDO-based runner (unlike
hr-advws's one-runner-script-per-feature convention):

1. Connects directly via PDO using the same `config.php` DB values.
2. Ensures a `schema_migrations` tracking table exists.
3. Iterates every `*.sql` file in the directory in filename order,
   skipping any already recorded in `schema_migrations`.
4. Runs each new file's SQL inside a transaction, records it on success.

This closes a gap flagged during design: hr-advws's migration runners have
no tracking table at all, so re-running one is not guaranteed idempotent.
Invoked manually (or as a one-off Job/init step) — never automatically on
container boot:

```
kubectl exec -it deploy/ingress-selfservice -n ingress-selfservice -- php app/migrations/migrate.php
```

---

## First-Run Setup

New Google logins default to `role='viewer'` (see
[../mdSource/RBAC-and-Authorization.md](../mdSource/RBAC-and-Authorization.md)) —
there is no seed data and no bootstrap admin account. The very first
`devops` user must be promoted manually after their first login:

```sql
UPDATE users SET role = 'devops' WHERE email = 'someone@advws.com';
```

---

## Local Development

No cluster, no MySQL server, and no Google OAuth app required to just
check PHP syntax (`php -l`), but all three are required to actually run
the app. Point `SERVER_CONFIG` at any kubeconfig file (a developer's own
`~/.kube/config` works) when developing outside a real Pod — see
[../mdSource/Configuration-and-Secrets.md](../mdSource/Configuration-and-Secrets.md#local-development-without-a-cluster).
`php -S localhost:8000 -t public` is sufficient for quick local checks of
the web routes (not the CronJob-only sweeper task).
