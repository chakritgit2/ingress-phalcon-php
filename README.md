# Ingress Self-Service (Kubernetes NodePort admin tool)

PHP Phalcon + Volt app that lets developers self-service create temporary
`Service` (`type: NodePort`) exposures for existing Kubernetes Deployments,
with Google SSO (restricted to `advws.com`) and full audit logging. No
public ingress — reachable only via `<node-ip>:<nodePort>`.

See `C:\Users\IT-DEV-NB-02\.claude\plans\ingress-piped-feather.md` for the
full design/decision record.

## Local setup

1. `composer install`
2. Create a MySQL database, then set env vars (see below) and run:
   ```
   php app/migrations/migrate.php
   ```
3. `npm install`, then build the Tailwind CSS bundle (`docker build` does this
   automatically; for local dev, build once or watch for changes):
   ```
   npm run build:css
   # or, while editing views/CSS:
   npm run watch:css
   ```
4. Serve `public/` with php-fpm + nginx, or for a quick local check:
   ```
   php -S localhost:8000 -t public
   ```
5. Kubernetes API access is configured via a **kubeconfig file**, pointed
   to by the `SERVER_CONFIG` env var (standard `~/.kube/config` format —
   `clusters[].cluster.server`/`certificate-authority-data`,
   `users[].user.token`). Point it at any kubeconfig with a token-based
   user entry — your own `~/.kube/config` works for local testing.

## Required environment variables

| Var | Purpose |
|---|---|
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` | MySQL connection |
| `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI` | Google OAuth app credentials |
| `GOOGLE_HD` | Allowed Google Workspace hosted domain (default `advws.com`) |
| `COOKIE_SIGN_KEY` | Session/cookie signing secret |
| `K8S_NODE_IP` | Node IP shown/logged as the reachable address (default `192.168.33.31`) |
| `SERVER_CONFIG` | Path to a kubeconfig file (token-based user entry required — client-certificate auth is not supported) |

## Deploying

```
docker build -t registry.example.com/ingress-selfservice:latest .
docker push registry.example.com/ingress-selfservice:latest

kubectl apply -f k8s/namespace.yaml
kubectl apply -f k8s/serviceaccount.yaml
kubectl apply -f k8s/clusterrole.yaml
kubectl apply -f k8s/clusterrolebinding.yaml
kubectl apply -f k8s/configmap.yaml
# copy k8s/secret.example.yaml -> k8s/secret.yaml, fill in real values first
kubectl apply -f k8s/secret.yaml
# copy k8s/kubeconfig-secret.example.yaml -> k8s/kubeconfig-secret.yaml,
# fill in a token generated FROM the ingress-selfservice ServiceAccount
# (see comments in that file for the exact kubectl commands)
kubectl apply -f k8s/kubeconfig-secret.yaml
kubectl apply -f k8s/deployment.yaml
kubectl apply -f k8s/service.yaml
kubectl apply -f k8s/cronjob.yaml

# run once after first deploy (and after future schema changes):
kubectl exec -it deploy/ingress-selfservice -n ingress-selfservice -- php app/migrations/migrate.php
```

## First devops user

New Google logins default to `role='viewer'` (no create/delete rights).
Promote a user manually:

```sql
UPDATE users SET role = 'devops' WHERE email = 'someone@advws.com';
```

## Open items flagged during design (see plan doc for full context)

- Whether the app's own web UI needs a bundled nginx sidecar or relies on
  existing cluster ingress infra (the Dockerfile only exposes php-fpm on
  :9000, matching the pattern the sibling hr-advws app used).
- RBAC is cluster-wide (`ClusterRole`) for MVP simplicity; revisit toward a
  namespace allowlist if usage expands beyond the current trusted group.
