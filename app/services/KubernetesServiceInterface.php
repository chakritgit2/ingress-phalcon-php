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

    public function listDeployments(string $namespace): array;

    /**
     * $requestId is the owning ingress_requests.id — implementations use it
     * as a stable label to detect and return an already-created Service
     * instead of creating a duplicate on retry (see KubernetesService).
     *
     * @return array{service_name: string, node_port: int, k8s_uid: string}
     */
    public function createNodePortService(string $namespace, string $deploymentName, int $targetPort, int $requestId): array;

    public function deleteService(string $namespace, string $name): void;

    /**
     * The method/path/body of the most recent create/delete call attempted,
     * regardless of whether it succeeded — null if nothing has been sent
     * yet. Used by KubernetesTask to log the literal command sent to
     * Kubernetes alongside its outcome.
     */
    public function getLastRequest(): ?array;
}
