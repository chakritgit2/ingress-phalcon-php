# Kubernetes Integration

How the app talks to the Kubernetes API, and why it's a hand-rolled client
instead of an off-the-shelf SDK.

---

## Components

| File | Role |
|---|---|
| `app/services/KubernetesClient.php` | Raw REST wrapper: auth header, base URI, error unwrapping |
| `app/services/KubernetesService.php` | The 5 domain operations the app actually needs, built on the client |
| `app/services/KubernetesApiException.php` | Thrown on any non-2xx response, carrying the K8s `Status.message` |
| `app/services/KubeconfigLoader.php` | Parses the kubeconfig pointed to by `SERVER_CONFIG` into host/port/token/CA |

## Authentication via kubeconfig (`SERVER_CONFIG`)

The app runs as a Pod with its own `ServiceAccount` (`ingress-selfservice`,
see `k8s/serviceaccount.yaml`), but rather than relying on Kubernetes'
automatic ServiceAccount token mount
(`/var/run/secrets/kubernetes.io/serviceaccount/token`), credentials are
supplied explicitly via a **kubeconfig file** — the same YAML format as
`~/.kube/config` — whose path comes from the `SERVER_CONFIG` env var.

`KubeconfigLoader::load($path)` (`app/services/KubeconfigLoader.php`,
using `symfony/yaml`) parses that file and extracts:
- the cluster's `server` URL → split into host/port
- `certificate-authority-data` (base64) → decoded to a temp file for
  Guzzle's `verify` option, or `certificate-authority` used as a path
  directly if present instead
- the current context's user `token` (or `tokenFile`) → the bearer token

`app/config/services.php` and `app/console.php` both build the shared
`kubernetesClient` service the same way:
```php
$kubeconfig = KubeconfigLoader::load($config->kubernetes->server_config_path);
return new KubernetesClient($kubeconfig['host'], $kubeconfig['port'], $kubeconfig['token'], $kubeconfig['ca_path']);
```

In-cluster, `SERVER_CONFIG` points at a kubeconfig mounted from the
`ingress-selfservice-kubeconfig` Secret (see
`k8s/kubeconfig-secret.example.yaml`) — that Secret's embedded token
**must** be generated from the `ingress-selfservice` ServiceAccount
(`kubectl create token ingress-selfservice ...`), so the identity making
API calls is still the one scoped by `k8s/clusterrole.yaml`. Locally,
`SERVER_CONFIG` can point at a developer's own `~/.kube/config` (or any
kubeconfig with a token-based user entry — client-certificate auth isn't
supported by `KubeconfigLoader`).

**Why not the automatic ServiceAccount mount**: kept configuration
uniform — the same `SERVER_CONFIG`-pointing-at-a-kubeconfig code path
works identically whether the Pod is running with a mounted Secret
in-cluster or a developer is pointing it at their own kubeconfig locally,
rather than having one code path for in-cluster auto-mount and a separate
env-var-based fallback for local dev.

## Why a hand-rolled client instead of an SDK

Only four operations are needed: list namespaces, list deployments, create
a NodePort `Service`, delete a `Service`. A community package like
`renoki-co/php-k8s` is Laravel-shaped (Illuminate collections, a class per
Kind) and would pull in a large dependency surface for a tool that mutates
cluster networking — auditability mattered more here than convenience.
`KubernetesClient` is under 100 lines and every request it can make is
visible in `KubernetesService`.

## Operations

`KubernetesService`:

- `listNamespaces()` — `GET /api/v1/namespaces`, used to populate the
  namespace `<select>` on the create form. Called synchronously from the
  web request (read-only, fast, no reason to queue it).
- `listDeployments($namespace)` — `GET /apis/apps/v1/namespaces/{ns}/deployments`,
  used by the AJAX endpoint `GET /ingress/api/deployments?namespace=` that
  populates the dependent deployment dropdown. Also synchronous.
- `getDeployment($namespace, $name)` — fetches a single Deployment to read
  its `spec.selector.matchLabels`, needed to build a matching Service.
- `createNodePortService($namespace, $deploymentName, $targetPort, $requestId)` —
  the core mutating operation. See below. **Not** called from the web
  request — only from `KubernetesTask::processCommandsAction()`, see
  [../mdSourceWorkflow/05-Command-Processing-Workflow.md](../mdSourceWorkflow/05-Command-Processing-Workflow.md).
- `deleteService($namespace, $name)` — used by the command processor (manual
  delete) and the expiry sweeper; treats "already gone" as success rather
  than an error, since the sweeper may race with a manual delete.
- `getRequestLog(): array` / `resetRequestLog(): void` — `getRequestLog()`
  returns every mutating (`POST`/`DELETE`) request this instance has
  attempted since the last reset, in call order, recorded *before* each
  HTTP call so an entry is present even when that call then throws.
  Read-only `GET`s (idempotency checks, Deployment lookups) are never
  recorded. `createIngress()`/`deleteIngress()` each issue two mutating
  calls (a Service call and an Ingress call), so the log can hold more than
  one entry per action — `createNodePortService()`/`deleteService()` only
  ever produce one. `KubernetesTask` calls `resetRequestLog()` before each
  command and reads `getRequestLog()` afterward to persist the literal
  outbound command(s) into `k8s_commands.request_payload` — see
  [Audit-Logging-Design.md](Audit-Logging-Design.md).
- `previewCreateNodePortServicePayload()` / `previewCreateIngressPayload()` /
  `previewDeleteServicePayload()` / `previewDeleteIngressPayload()` — each
  returns an **array** of one or more `{method, path, body}` entries (one
  for nodeport-type, two for ingress-type, in the same order the real calls
  happen), built purely locally, no HTTP calls at all.
  `IngressRequestService::enqueue()` calls these right after saving a
  `k8s_commands` row so `request_payload` isn't blank until the bot's next
  tick. For `create`, the preview body's `spec.selector` (and, for Ingress,
  the backing Service's name) is always `null` — the real value only exists
  once the live Deployment lookup below actually happens. For `delete`, the
  preview is exact: everything it needs (`namespace`,
  `service_name`/`ingress_name`) is already on the `ingress_requests` row
  by the time a delete is enqueued.

## How a NodePort Service is constructed

`createNodePortService($namespace, $deploymentName, $targetPort, $requestId)`:

1. **Idempotency check first**: looks up any existing Service in the
   namespace labeled `ingress-selfservice.advws.com/request-id=<requestId>`
   (`GET .../services?labelSelector=...`). If one is already there, returns
   its info immediately instead of creating a second one. `$requestId` is
   the owning `ingress_requests.id` — stable across retries of the *same*
   row, unlike the Service's own generated name. This exists because the
   command processor could crash after the `POST` below succeeds but
   before it records that success, and would otherwise create a duplicate,
   orphaned Service on its next pass over the still-`pending` command.
2. Fetches the target Deployment and reads `spec.selector.matchLabels` —
   this is what makes the new Service actually route traffic to that
   Deployment's pods, without needing the user to know or supply a label
   selector themselves.
3. Builds a Service body with `metadata.generateName: 'tmp-nodeport-'`
   (Kubernetes assigns a collision-free suffix, so the app never has to
   invent unique names itself), `spec.type: NodePort`, and the
   `ingress-selfservice.advws.com/request-id` label from step 1.
4. `POST /api/v1/namespaces/{ns}/services`, then reads back
   `metadata.name`, `metadata.uid`, and the API-server-assigned
   `spec.ports[0].nodePort` — none of these are chosen by the app; K8s
   picks the NodePort from its configured range (typically 30000–32767).

This is why "the address" a user gets (`192.168.33.31:<nodePort>`) isn't
knowable at form-submission time at all — it's only resolved once the
command processor's bot actually runs this method, up to a minute later.
See
[../mdSourceWorkflow/02-Create-Ingress-Request-Workflow.md](../mdSourceWorkflow/02-Create-Ingress-Request-Workflow.md)
and
[../mdSourceWorkflow/05-Command-Processing-Workflow.md](../mdSourceWorkflow/05-Command-Processing-Workflow.md).

`MockKubernetesService` (dev-only, see
[Configuration-and-Secrets.md](Configuration-and-Secrets.md)) implements the
same interface for local demos without a cluster, but can't replicate the
idempotency check across separate CLI invocations — it has no persistent
state, so `$requestId` is only threaded through for interface parity and to
show up in the logged payload.

## Input validation before it touches a URL path

`KubernetesService::assertValidLabel()` checks `namespace` and
`deployment_name` against the Kubernetes DNS-1123 label regex
(`^[a-z0-9]([-a-z0-9]*[a-z0-9])?$`) and `assertValidPort()` checks the port
is 1–65535 — both run *before* the values are interpolated into any REST
path string, closing off path/API injection via a malicious namespace or
deployment name typed into the form.

## RBAC — what this ServiceAccount can and can't do

See `k8s/clusterrole.yaml`. Cluster-wide (not per-namespace) `get`/`list` on
`namespaces` and `deployments`, and `get`/`list`/`create`/`delete` on
`services` — nothing else. It cannot read Pods, Secrets, or modify a
Deployment. Full reasoning in
[RBAC-and-Authorization.md](RBAC-and-Authorization.md).
