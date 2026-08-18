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
    private array $requestLog = [];

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

    private const SECRETS = [
        'qa' => ['qa-wildcard-tls'],
        'staging' => ['staging-wildcard-tls'],
        'production' => ['wildcard-advws-tls', 'kapooktopup-tls'],
    ];

    public function listNamespaces(): array
    {
        return self::NAMESPACES;
    }

    public function listDeployments(string $namespace): array
    {
        return self::DEPLOYMENTS[$namespace] ?? [];
    }

    public function listSecrets(string $namespace): array
    {
        return self::SECRETS[$namespace] ?? [];
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
        $this->requestLog[] = [
            'method' => 'POST',
            'path' => "/api/v1/namespaces/{$namespace}/services",
            'body' => [
                'apiVersion' => 'v1',
                'kind' => 'Service',
                'metadata' => [
                    'generateName' => 'tmp-nodeport-',
                    'namespace' => $namespace,
                    'labels' => [
                        'app.kubernetes.io/managed-by' => 'ingress-selfservice',
                        'advws-group' => 'company',
                        'k8s-app' => $deploymentName,
                        'ingress-selfservice.advws.com/request-id' => (string) $requestId,
                    ],
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
        $this->requestLog[] = [
            'method' => 'DELETE',
            'path' => "/api/v1/namespaces/{$namespace}/services/{$name}",
            'body' => null,
        ];
        // No-op: nothing real to delete.
    }

    public function createIngress(string $namespace, string $deploymentName, int $targetPort, string $host, string $secretName, int $requestId): array
    {
        $exists = array_filter(
            self::DEPLOYMENTS[$namespace] ?? [],
            fn (array $d) => $d['name'] === $deploymentName
        );
        if (empty($exists)) {
            throw new KubernetesApiException("Deployment {$deploymentName} not found in namespace {$namespace}");
        }

        $suffix = substr(bin2hex(random_bytes(4)), 0, 6);
        $serviceName = "tmp-ingress-svc-{$suffix}";
        $ingressName = "tmp-ingress-{$suffix}";

        $labels = [
            'app.kubernetes.io/managed-by' => 'ingress-selfservice',
            'advws-group' => 'company',
            'k8s-app' => $deploymentName,
            'ingress-selfservice.advws.com/request-id' => (string) $requestId,
        ];

        // Mirrors the real KubernetesService::createIngress(), which always
        // POSTs a backing ClusterIP Service before the Ingress itself — the
        // mock has no persistent state to actually create one, but the log
        // still needs to reflect both calls for the audit trail to be
        // representative of the real flow.
        $this->requestLog[] = [
            'method' => 'POST',
            'path' => "/api/v1/namespaces/{$namespace}/services",
            'body' => [
                'apiVersion' => 'v1',
                'kind' => 'Service',
                'metadata' => [
                    'generateName' => 'tmp-ingress-svc-',
                    'namespace' => $namespace,
                    'labels' => $labels,
                ],
                'spec' => ['type' => 'ClusterIP', 'ports' => [['port' => $targetPort, 'targetPort' => $targetPort]]],
            ],
        ];

        $this->requestLog[] = [
            'method' => 'POST',
            'path' => "/apis/networking.k8s.io/v1/namespaces/{$namespace}/ingresses",
            'body' => [
                'apiVersion' => 'networking.k8s.io/v1',
                'kind' => 'Ingress',
                'metadata' => [
                    'generateName' => 'tmp-ingress-',
                    'namespace' => $namespace,
                    'labels' => $labels,
                    'annotations' => ['kubernetes.io/ingress.class' => 'nginx'],
                ],
                'spec' => [
                    'rules' => [[
                        'host' => $host,
                        'http' => ['paths' => [[
                            'path' => '/',
                            'pathType' => 'ImplementationSpecific',
                            'backend' => ['service' => ['name' => $serviceName, 'port' => ['number' => $targetPort]]],
                        ]]],
                    ]],
                    'tls' => [['hosts' => [$host], 'secretName' => $secretName]],
                ],
            ],
        ];

        return [
            'service_name' => $serviceName,
            'ingress_name' => $ingressName,
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

    public function deleteIngress(string $namespace, string $ingressName, string $serviceName): void
    {
        // Mirrors the real KubernetesService::deleteIngress(), which
        // deletes the Ingress first and then always cascades into
        // deleteService() for its backing Service.
        $this->requestLog[] = [
            'method' => 'DELETE',
            'path' => "/apis/networking.k8s.io/v1/namespaces/{$namespace}/ingresses/{$ingressName}",
            'body' => null,
        ];
        $this->requestLog[] = [
            'method' => 'DELETE',
            'path' => "/api/v1/namespaces/{$namespace}/services/{$serviceName}",
            'body' => null,
        ];
        // No-op: nothing real to delete.
    }

    public function getRequestLog(): array
    {
        return $this->requestLog;
    }

    public function resetRequestLog(): void
    {
        $this->requestLog = [];
    }

    public function previewCreateNodePortServicePayload(string $namespace, string $deploymentName, int $targetPort, int $requestId): array
    {
        return [[
            'method' => 'POST',
            'path' => "/api/v1/namespaces/{$namespace}/services",
            'body' => [
                'apiVersion' => 'v1',
                'kind' => 'Service',
                'metadata' => [
                    'generateName' => 'tmp-nodeport-',
                    'namespace' => $namespace,
                    'labels' => [
                        'app.kubernetes.io/managed-by' => 'ingress-selfservice',
                        'advws-group' => 'company',
                        'k8s-app' => $deploymentName,
                        'ingress-selfservice.advws.com/request-id' => (string) $requestId,
                    ],
                ],
                'spec' => ['type' => 'NodePort', 'selector' => null, 'ports' => [['port' => $targetPort, 'targetPort' => $targetPort]]],
            ],
        ]];
    }

    public function previewCreateIngressPayload(string $namespace, string $deploymentName, int $targetPort, string $host, string $secretName, int $requestId): array
    {
        $labels = [
            'app.kubernetes.io/managed-by' => 'ingress-selfservice',
            'advws-group' => 'company',
            'k8s-app' => $deploymentName,
            'ingress-selfservice.advws.com/request-id' => (string) $requestId,
        ];

        $servicePreview = [
            'method' => 'POST',
            'path' => "/api/v1/namespaces/{$namespace}/services",
            'body' => [
                'apiVersion' => 'v1',
                'kind' => 'Service',
                'metadata' => [
                    'generateName' => 'tmp-ingress-svc-',
                    'namespace' => $namespace,
                    'labels' => $labels,
                ],
                'spec' => ['type' => 'ClusterIP', 'selector' => null, 'ports' => [['port' => $targetPort, 'targetPort' => $targetPort]]],
            ],
        ];

        $placeholderServiceName = 'nodered-' . substr(bin2hex(random_bytes(3)), 0, 6);

        $ingressPreview = [
            'method' => 'POST',
            'path' => "/apis/networking.k8s.io/v1/namespaces/{$namespace}/ingresses",
            'body' => [
                'apiVersion' => 'networking.k8s.io/v1',
                'kind' => 'Ingress',
                'metadata' => [
                    'generateName' => 'tmp-ingress-',
                    'namespace' => $namespace,
                    'labels' => $labels,
                    'annotations' => ['kubernetes.io/ingress.class' => 'nginx'],
                ],
                'spec' => [
                    'rules' => [[
                        'host' => $host,
                        'http' => ['paths' => [[
                            'path' => '/',
                            'pathType' => 'ImplementationSpecific',
                            'backend' => ['service' => ['name' => $placeholderServiceName, 'port' => ['number' => $targetPort]]],
                        ]]],
                    ]],
                    'tls' => [['hosts' => [$host], 'secretName' => $secretName]],
                ],
            ],
        ];

        return [$servicePreview, $ingressPreview];
    }

    public function previewDeleteServicePayload(string $namespace, string $name): array
    {
        return [[
            'method' => 'DELETE',
            'path' => "/api/v1/namespaces/{$namespace}/services/{$name}",
            'body' => null,
        ]];
    }

    public function previewDeleteIngressPayload(string $namespace, string $ingressName, string $serviceName): array
    {
        return [
            [
                'method' => 'DELETE',
                'path' => "/apis/networking.k8s.io/v1/namespaces/{$namespace}/ingresses/{$ingressName}",
                'body' => null,
            ],
            [
                'method' => 'DELETE',
                'path' => "/api/v1/namespaces/{$namespace}/services/{$serviceName}",
                'body' => null,
            ],
        ];
    }
}
