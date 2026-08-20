<?php

namespace App\Services;

/**
 * The subset of KubernetesService's public API the rest of the app depends
 * on. Exists so a MockKubernetesService can stand in for local demos
 * without a real cluster — see MockKubernetesService.
 */
interface KubernetesServiceInterface
{
    public function listNamespaces(): array;

    /**
     * Each item also carries 'container_names' (the pod spec's container
     * name(s), not the Deployment's own metadata.name) — a Deployment can be
     * named anything while running e.g. a "nodered" container, so callers
     * that need to recognize what's actually running must match against
     * container_names, not the Deployment name itself.
     */
    public function listDeployments(string $namespace): array;

    /**
     * Same shape as listDeployments() but across every namespace at once —
     * each item additionally carries its 'namespace' key. Lets the
     * create/edit form's Deployment picker be usable before a Namespace is
     * chosen (see clusterrole.yaml: deployments list/get is already
     * cluster-wide, so this needs no extra RBAC).
     */
    public function listAllDeployments(): array;

    /**
     * TLS-typed Secret names available in the namespace, for populating the
     * secretName choices on the Ingress+TLS create form.
     *
     * @return string[]
     */
    public function listSecrets(string $namespace): array;

    /**
     * $requestId is the owning ingress_requests.id — implementations use it
     * as a stable label to detect and return an already-created Service
     * instead of creating a duplicate on retry (see KubernetesService).
     *
     * node_admin_path reports whether the target Deployment defines a
     * NODE_ADMIN_PATH env var (found) and whether it needed patching to
     * /nodeadmin (patched) — false/false if it isn't defined at all. A
     * failed patch attempt (e.g. missing RBAC) never fails this call: it's
     * reported as found=true, patched=false, error=<message> instead.
     *
     * @return array{service_name: string, node_port: int, k8s_uid: string, node_admin_path: array{found: bool, patched: bool, error?: string}}
     */
    public function createNodePortService(string $namespace, string $deploymentName, int $targetPort, int $requestId): array;

    public function deleteService(string $namespace, string $name): void;

    /**
     * Counterpart to createNodePortService()/createIngress()'s node_admin_path
     * patch — called when a request is deleted or expires, to put
     * NODE_ADMIN_PATH back to its original value on the target Deployment.
     * Callers must check no other active request still targets the same
     * Deployment before calling this — it has no way to know that itself and
     * will unconditionally revert whatever it finds. found=false (with no
     * error) if the Deployment no longer exists or never had the env var.
     *
     * @return array{found: bool, reverted: bool, error?: string}
     */
    public function revertNodeAdminPathEnv(string $namespace, string $deploymentName): array;

    /**
     * Creates a backing ClusterIP Service (same idempotency behaviour as
     * createNodePortService) plus an Ingress routing $host through it with
     * TLS terminated using the given (pre-existing) Secret.
     *
     * See createNodePortService() for what node_admin_path reports.
     *
     * @return array{service_name: string, ingress_name: string, k8s_uid: string, node_admin_path: array{found: bool, patched: bool, error?: string}}
     */
    public function createIngress(string $namespace, string $deploymentName, int $targetPort, string $host, string $secretName, int $requestId): array;

    public function deleteIngress(string $namespace, string $ingressName, string $serviceName): void;

    /**
     * Every mutating create/delete request attempted since the last
     * resetRequestLog() call, in call order — [] if nothing has been sent
     * yet. Used by KubernetesTask to log the literal command(s) sent to
     * Kubernetes alongside the outcome. A single action can involve more
     * than one entry (e.g. createIngress()/deleteIngress() each make a
     * Service call and an Ingress call).
     */
    public function getRequestLog(): array;

    /**
     * Clears the request log. KubernetesTask calls this before processing
     * each k8s_commands row so getRequestLog() afterward reflects only that
     * row's own call(s), not a previous command's in the same batch.
     */
    public function resetRequestLog(): void;

    /**
     * Builds the {method, path, body} of the Service create request WITHOUT
     * sending it — no HTTP calls — wrapped in a 1-element array for shape
     * consistency with previewCreateIngressPayload(). Used by
     * IngressRequestService to store a preview in
     * k8s_commands.request_payload at enqueue time, before the bot ever
     * runs. Since the real body needs the target Deployment's live
     * selector (only knowable via a real Kubernetes call), `body.spec.selector`
     * is always `null` here — a placeholder until KubernetesTask actually
     * processes the command and overwrites request_payload with the real,
     * fully-resolved request.
     */
    public function previewCreateNodePortServicePayload(string $namespace, string $deploymentName, int $targetPort, int $requestId): array;

    /**
     * Same idea as previewCreateNodePortServicePayload(), but for the
     * Ingress create path. Returns a 2-element array in real call order:
     * [0] the backing Service create request, [1] the Ingress create
     * request.
     */
    public function previewCreateIngressPayload(string $namespace, string $deploymentName, int $targetPort, string $host, string $secretName, int $requestId): array;

    /**
     * Builds the {method, path, body} of the Service delete request WITHOUT
     * sending it, wrapped in a 1-element array. Unlike the create previews,
     * this is exact — a delete needs no data beyond what's already on the
     * ingress_requests row, so this always matches what deleteService()
     * will actually send.
     */
    public function previewDeleteServicePayload(string $namespace, string $name): array;

    /**
     * Same idea as previewDeleteServicePayload(), but for the Ingress
     * delete path. Returns a 2-element array in real call order: [0] the
     * Ingress delete request, [1] the Service delete request that
     * deleteIngress() always issues afterward.
     */
    public function previewDeleteIngressPayload(string $namespace, string $ingressName, string $serviceName): array;
}
