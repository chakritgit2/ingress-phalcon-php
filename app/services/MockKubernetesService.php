<?php

namespace App\Services;

/**
 * DEV-ONLY stand-in for KubernetesService, for previewing the full
 * create-ingress flow without a reachable cluster. Only wired in when
 * APP_ENV=local and no SERVER_CONFIG is set (see app/config/services.php) —
 * never used when a real kubeconfig is configured. Remove before shipping.
 */
class MockKubernetesService implements KubernetesServiceInterface
{
    private ?array $lastRequest = null;

    private const NAMESPACES = ['qa', 'staging', 'production'];

    private const DEPLOYMENTS = [
        'qa' => [
            ['name' => 'checkout-api', 'replicas' => 2],
            ['name' => 'notification-worker', 'replicas' => 1],
        ],
        'staging' => [
            ['name' => 'reporting-service', 'replicas' => 1],
        ],
        'production' => [
            ['name' => 'checkout-api', 'replicas' => 4],
            ['name' => 'reporting-service', 'replicas' => 2],
        ],
    ];

    public function listNamespaces(): array
    {
        return self::NAMESPACES;
    }

    public function listDeployments(string $namespace): array
    {
        return self::DEPLOYMENTS[$namespace] ?? [];
    }

    public function createNodePortService(string $namespace, string $deploymentName, int $targetPort, int $requestId): array
    {
        $exists = array_filter(
            self::DEPLOYMENTS[$namespace] ?? [],
            fn (array $d) => $d['name'] === $deploymentName
        );
        if (empty($exists)) {
            throw new KubernetesApiException("Deployment {$deploymentName} not found in namespace {$namespace}");
        }

        $suffix = substr(bin2hex(random_bytes(4)), 0, 6);

        // NOTE: unlike the real KubernetesService, this can't actually
        // detect a duplicate across separate CLI invocations (no persistent
        // state) — $requestId is only threaded through for interface
        // parity and to show up in the logged payload.
        $this->lastRequest = [
            'method' => 'POST',
            'path' => "/api/v1/namespaces/{$namespace}/services",
            'body' => [
                'apiVersion' => 'v1',
                'kind' => 'Service',
                'metadata' => [
                    'generateName' => 'tmp-nodeport-',
                    'namespace' => $namespace,
                    'labels' => ['ingress-selfservice.advws.com/request-id' => (string) $requestId],
                ],
                'spec' => ['type' => 'NodePort', 'ports' => [['port' => $targetPort, 'targetPort' => $targetPort]]],
            ],
        ];

        return [
            'service_name' => "tmp-nodeport-{$suffix}",
            'node_port' => random_int(30000, 32767),
            'k8s_uid' => sprintf(
                '%08x-%04x-%04x-%04x-%012x',
                random_int(0, 0xffffffff),
                random_int(0, 0xffff),
                random_int(0, 0xffff),
                random_int(0, 0xffff),
                random_int(0, 0xffffffffffff)
            ),
        ];
    }

    public function deleteService(string $namespace, string $name): void
    {
        $this->lastRequest = [
            'method' => 'DELETE',
            'path' => "/api/v1/namespaces/{$namespace}/services/{$name}",
            'body' => null,
        ];
        // No-op: nothing real to delete.
    }

    public function getLastRequest(): ?array
    {
        return $this->lastRequest;
    }
}
