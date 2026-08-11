# RBAC and Authorization

Two separate, easily-confused layers: **who Kubernetes lets the app's Pod
talk to** (cluster RBAC), and **who the app lets create/delete things**
(application-level role).

---

## Layer 1: Kubernetes RBAC (the Pod's own permissions)

Defined in `k8s/clusterrole.yaml` + `k8s/clusterrolebinding.yaml`, bound to
`ServiceAccount: ingress-selfservice`. This governs what the *application
itself* can do against the Kubernetes API, regardless of which human is
using it.

```yaml
rules:
  - apiGroups: [""]
    resources: ["namespaces"]
    verbs: ["get", "list"]
  - apiGroups: ["apps"]
    resources: ["deployments"]
    verbs: ["get", "list"]
  - apiGroups: [""]
    resources: ["services"]
    verbs: ["get", "list", "create", "delete"]
```

**Deliberately cluster-wide, not per-namespace**: the namespace/deployment
picker (`GET /ingress/api/deployments`) needs to discover across every
namespace, and a per-namespace `Role`/`RoleBinding` would need manual
onboarding every time a new namespace should be self-serviceable —
defeating the point of self-service.

**Accepted risk**: if this Pod is compromised, an attacker inherits the
ability to create or delete `Service` objects in *any* namespace — but
cannot read Pods, Secrets, ConfigMaps, or modify/delete Deployments. This
was judged acceptable for an internal, trusted-team, no-public-ingress tool
(see [Kubernetes-Integration.md](Kubernetes-Integration.md) for how the
Pod's own credentials are obtained). Revisit toward a namespace allowlist
with per-namespace `RoleBinding`s if usage ever expands beyond the current
trusted group.

## Layer 2: Application role (`users.role`)

A Google login from `advws.com` proves *identity* ("this is an advws.com
employee"), not *authorization* ("this person should be allowed to mutate
the cluster"). Those are deliberately kept separate:

- Every new SSO login is provisioned with `role = 'viewer'`
  (`AuthService::findOrCreateByGoogle()`).
- `AuthMiddleware::beforeExecuteRoute()` requires `role === 'devops'` for
  any `ingress/*` or `audit/*` controller action — a `viewer` is redirected
  back to `/login` with a flash error.
- Promotion to `devops` is a manual, out-of-band action:
  ```sql
  UPDATE users SET role = 'devops' WHERE email = 'someone@advws.com';
  ```
  There is intentionally no self-service "become devops" button.

## Why split this way

If SSO success alone granted cluster-mutating rights, then simply having an
`@advws.com` Google account — which could be a large, loosely-controlled
group — would be equivalent to devops access to the cluster's networking
layer. Requiring an explicit, manually-maintained allowlist means the blast
radius of "anyone in the company can log in" stays limited to read-only
`viewer` access (browsing `/audit`), while the ability to actually create
or tear down NodePort Services stays with a small, deliberately-curated
group.
