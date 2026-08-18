<?php

namespace App\Services;

class KubernetesService implements KubernetesServiceInterface
{
    private const DNS_1123_LABEL = '/^[a-z0-9]([-a-z0-9]*[a-z0-9])?$/';
    // DNS-1123 subdomain: one or more dot-separated DNS-1123 labels — the
    // format Kubernetes actually validates Secret names and Ingress hosts
    // against, unlike Namespace/Service names which are restricted to a
    // single label (no dots).
    private const DNS_1123_SUBDOMAIN = '/^[a-z0-9]([-a-z0-9]*[a-z0-9])?(\.[a-z0-9]([-a-z0-9]*[a-z0-9])?)*$/';
    private const REQUEST_ID_LABEL = 'ingress-selfservice.advws.com/request-id';

    private KubernetesClient $client;

    public function __construct(KubernetesClient $client)
    {
        $this->client = $client;
    }

    public function listNamespaces(): array
    {
        $result = $this->client->get('/api/v1/namespaces');
        return array_map(
            fn (array $item) => $item['metadata']['name'],
            $result['items'] ?? []
        );
    }

    public function listDeployments(string $namespace): array
    {
        $namespace = $this->assertValidLabel($namespace, 'namespace');
        $result = $this->client->get("/apis/apps/v1/namespaces/{$namespace}/deployments");

        return array_map(
            fn (array $item) => [
                'name' => $item['metadata']['name'],
                'replicas' => $item['spec']['replicas'] ?? 0,
            ],
            $result['items'] ?? []
        );
    }

    public function getDeployment(string $namespace, string $name): ?array
    {
        $namespace = $this->assertValidLabel($namespace, 'namespace');
        $name = $this->assertValidLabel($name, 'deployment name');

        try {
            return $this->client->get("/apis/apps/v1/namespaces/{$namespace}/deployments/{$name}");
        } catch (KubernetesApiException $e) {
            return null;
        }
    }

    /**
     * @return string[]
     */
    public function listSecrets(string $namespace): array
    {
        $namespace = $this->assertValidLabel($namespace, 'namespace');
        $query = http_build_query(['fieldSelector' => 'type=kubernetes.io/tls']);
        $result = $this->client->get("/api/v1/namespaces/{$namespace}/secrets?{$query}");

        return array_map(
            fn (array $item) => $item['metadata']['name'],
            $result['items'] ?? []
        );
    }

    /**
     * @return array{service_name: string, node_port: int, k8s_uid: string}
     */
    public function createNodePortService(string $namespace, string $deploymentName, int $targetPort, int $requestId): array
    {
        $namespace = $this->assertValidLabel($namespace, 'namespace');
        $deploymentName = $this->assertValidLabel($deploymentName, 'deployment name');
        $targetPort = $this->assertValidPort($targetPort);

        // Idempotency guard: if a previous attempt for this exact
        // ingress_requests row already created a Service (e.g. the bot
        // crashed/was killed after the POST succeeded but before it could
        // record success), find it by its stable label instead of creating
        // a second, orphaned one.
        $existing = $this->findServiceByRequestId($namespace, $requestId);
        if ($existing !== null) {
            return $existing;
        }

        $deployment = $this->getDeployment($namespace, $deploymentName);
        if ($deployment === null) {
            throw new KubernetesApiException("Deployment {$deploymentName} not found in namespace {$namespace}");
        }

        $selector = $deployment['spec']['selector']['matchLabels'] ?? [];
        if (empty($selector)) {
            throw new KubernetesApiException("Deployment {$deploymentName} has no matchLabels selector to target");
        }

        $body = $this->buildServiceBody($namespace, $deploymentName, $targetPort, $requestId, $selector, 'tmp-nodeport-', 'NodePort');

        $created = $this->client->post("/api/v1/namespaces/{$namespace}/services", $body);

        return [
            'service_name' => $created['metadata']['name'],
            'node_port' => $created['spec']['ports'][0]['nodePort'],
            'k8s_uid' => $created['metadata']['uid'],
        ];
    }

    /**
     * @return array{service_name: string, ingress_name: string, k8s_uid: string}
     */
    public function createIngress(string $namespace, string $deploymentName, int $targetPort, string $host, string $secretName, int $requestId): array
    {
        $namespace = $this->assertValidLabel($namespace, 'namespace');
        $deploymentName = $this->assertValidLabel($deploymentName, 'deployment name');
        $targetPort = $this->assertValidPort($targetPort);
        $host = $this->assertValidHost($host);
        $secretName = $this->assertValidSubdomain($secretName, 'secret name');

        // Same idempotency guard as createNodePortService, keyed off the
        // Ingress this time (its backend service name is read back from it).
        $existingIngress = $this->findIngressByRequestId($namespace, $requestId);
        if ($existingIngress !== null) {
            return $existingIngress;
        }

        $deployment = $this->getDeployment($namespace, $deploymentName);
        if ($deployment === null) {
            throw new KubernetesApiException("Deployment {$deploymentName} not found in namespace {$namespace}");
        }

        $selector = $deployment['spec']['selector']['matchLabels'] ?? [];
        if (empty($selector)) {
            throw new KubernetesApiException("Deployment {$deploymentName} has no matchLabels selector to target");
        }

        $labels = $this->buildManagedLabels($deploymentName, $requestId);

        $existingService = $this->findServiceByRequestId($namespace, $requestId);
        if ($existingService !== null) {
            $serviceName = $existingService['service_name'];
        } else {
            $serviceBody = $this->buildServiceBody($namespace, $deploymentName, $targetPort, $requestId, $selector, 'tmp-ingress-svc-', 'ClusterIP');
            $createdService = $this->client->post("/api/v1/namespaces/{$namespace}/services", $serviceBody);
            $serviceName = $createdService['metadata']['name'];
        }

        $ingressBody = $this->buildIngressBody($namespace, $host, $secretName, $serviceName, $targetPort, $labels);

        $createdIngress = $this->client->post("/apis/networking.k8s.io/v1/namespaces/{$namespace}/ingresses", $ingressBody);

        return [
            'service_name' => $serviceName,
            'ingress_name' => $createdIngress['metadata']['name'],
            'k8s_uid' => $createdIngress['metadata']['uid'],
        ];
    }

    private function buildManagedLabels(string $deploymentName, int $requestId): array
    {
        return [
            'app.kubernetes.io/managed-by' => 'ingress-selfservice',
            'advws-group' => 'company',
            'k8s-app' => $deploymentName,
            self::REQUEST_ID_LABEL => (string) $requestId,
        ];
    }

    /**
     * Shared by createNodePortService(), createIngress()'s backing Service,
     * and the preview*Payload() methods below. $selector is nullable so a
     * preview (built with no live Deployment lookup) can represent "not yet
     * resolved" as an explicit `null` rather than guessing at a value.
     */
    private function buildServiceBody(string $namespace, string $deploymentName, int $targetPort, int $requestId, ?array $selector, string $generateNamePrefix, string $serviceType): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'Service',
            'metadata' => [
                'generateName' => $generateNamePrefix,
                'namespace' => $namespace,
                'labels' => $this->buildManagedLabels($deploymentName, $requestId),
            ],
            'spec' => [
                'type' => $serviceType,
                'selector' => $selector,
                'ports' => [[
                    'port' => $targetPort,
                    'targetPort' => $targetPort,
                    'protocol' => 'TCP',
                ]],
            ],
        ];
    }

    /**
     * Shared by createIngress() and previewCreateIngressPayload(). $serviceName
     * is nullable so a preview (built before the idempotency lookup/service
     * create has happened) can represent "not yet resolved" explicitly.
     */
    private function buildIngressBody(string $namespace, string $host, string $secretName, ?string $serviceName, int $targetPort, array $labels): array
    {
        return [
            'apiVersion' => 'networking.k8s.io/v1',
            'kind' => 'Ingress',
            'metadata' => [
                'generateName' => 'tmp-ingress-',
                'namespace' => $namespace,
                'labels' => $labels,
                'annotations' => [
                    'kubernetes.io/ingress.class' => 'nginx',
                ],
            ],
            'spec' => [
                'rules' => [[
                    'host' => $host,
                    'http' => [
                        'paths' => [[
                            'path' => '/',
                            'pathType' => 'ImplementationSpecific',
                            'backend' => [
                                'service' => [
                                    'name' => $serviceName,
                                    'port' => ['number' => $targetPort],
                                ],
                            ],
                        ]],
                    ],
                ]],
                'tls' => [[
                    'hosts' => [$host],
                    'secretName' => $secretName,
                ]],
            ],
        ];
    }

    public function previewCreateNodePortServicePayload(string $namespace, string $deploymentName, int $targetPort, int $requestId): array
    {
        $namespace = $this->assertValidLabel($namespace, 'namespace');
        $deploymentName = $this->assertValidLabel($deploymentName, 'deployment name');
        $targetPort = $this->assertValidPort($targetPort);

        return [[
            'method' => 'POST',
            'path' => "/api/v1/namespaces/{$namespace}/services",
            'body' => $this->buildServiceBody($namespace, $deploymentName, $targetPort, $requestId, null, 'tmp-nodeport-', 'NodePort'),
        ]];
    }

    public function previewCreateIngressPayload(string $namespace, string $deploymentName, int $targetPort, string $host, string $secretName, int $requestId): array
    {
        $namespace = $this->assertValidLabel($namespace, 'namespace');
        $deploymentName = $this->assertValidLabel($deploymentName, 'deployment name');
        $targetPort = $this->assertValidPort($targetPort);
        $host = $this->assertValidHost($host);
        $secretName = $this->assertValidSubdomain($secretName, 'secret name');

        $labels = $this->buildManagedLabels($deploymentName, $requestId);

        $servicePreview = [
            'method' => 'POST',
            'path' => "/api/v1/namespaces/{$namespace}/services",
            'body' => $this->buildServiceBody($namespace, $deploymentName, $targetPort, $requestId, null, 'tmp-ingress-svc-', 'ClusterIP'),
        ];

        // The real backing Service's name only exists once Kubernetes
        // actually assigns it (createIngress()'s generateName, or an
        // existing one found via the idempotency check) — neither is
        // knowable here without a live call. A locally-generated random
        // placeholder just avoids a bare `null` in the preview; it will
        // almost certainly NOT match the real name, and gets overwritten
        // like the rest of this payload once the bot actually sends it.
        $placeholderServiceName = 'nodered-' . substr(bin2hex(random_bytes(3)), 0, 6);

        $ingressPreview = [
            'method' => 'POST',
            'path' => "/apis/networking.k8s.io/v1/namespaces/{$namespace}/ingresses",
            'body' => $this->buildIngressBody($namespace, $host, $secretName, $placeholderServiceName, $targetPort, $labels),
        ];

        return [$servicePreview, $ingressPreview];
    }

    public function previewDeleteServicePayload(string $namespace, string $name): array
    {
        $namespace = $this->assertValidLabel($namespace, 'namespace');
        $name = $this->assertValidLabel($name, 'service name');

        return [[
            'method' => 'DELETE',
            'path' => "/api/v1/namespaces/{$namespace}/services/{$name}",
            'body' => null,
        ]];
    }

    public function previewDeleteIngressPayload(string $namespace, string $ingressName, string $serviceName): array
    {
        $namespace = $this->assertValidLabel($namespace, 'namespace');
        $ingressName = $this->assertValidLabel($ingressName, 'ingress name');
        $serviceName = $this->assertValidLabel($serviceName, 'service name');

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

    /**
     * @return array{service_name: string, node_port: ?int, k8s_uid: string}|null
     */
    private function findServiceByRequestId(string $namespace, int $requestId): ?array
    {
        $query = http_build_query(['labelSelector' => self::REQUEST_ID_LABEL . '=' . $requestId]);
        $result = $this->client->get("/api/v1/namespaces/{$namespace}/services?{$query}");
        $items = $result['items'] ?? [];

        if (empty($items)) {
            return null;
        }

        $service = $items[0];

        return [
            'service_name' => $service['metadata']['name'],
            'node_port' => $service['spec']['ports'][0]['nodePort'] ?? null,
            'k8s_uid' => $service['metadata']['uid'],
        ];
    }

    /**
     * @return array{service_name: string, ingress_name: string, k8s_uid: string}|null
     */
    private function findIngressByRequestId(string $namespace, int $requestId): ?array
    {
        $query = http_build_query(['labelSelector' => self::REQUEST_ID_LABEL . '=' . $requestId]);
        $result = $this->client->get("/apis/networking.k8s.io/v1/namespaces/{$namespace}/ingresses?{$query}");
        $items = $result['items'] ?? [];

        if (empty($items)) {
            return null;
        }

        $ingress = $items[0];

        return [
            'service_name' => $ingress['spec']['rules'][0]['http']['paths'][0]['backend']['service']['name'] ?? '',
            'ingress_name' => $ingress['metadata']['name'],
            'k8s_uid' => $ingress['metadata']['uid'],
        ];
    }

    public function deleteService(string $namespace, string $name): void
    {
        $namespace = $this->assertValidLabel($namespace, 'namespace');
        $name = $this->assertValidLabel($name, 'service name');

        try {
            $this->client->delete("/api/v1/namespaces/{$namespace}/services/{$name}");
        } catch (KubernetesApiException $e) {
            if (stripos($e->getMessage(), 'not found') === false) {
                throw $e;
            }
        }
    }

    public function deleteIngress(string $namespace, string $ingressName, string $serviceName): void
    {
        $namespace = $this->assertValidLabel($namespace, 'namespace');
        $ingressName = $this->assertValidLabel($ingressName, 'ingress name');

        try {
            $this->client->delete("/apis/networking.k8s.io/v1/namespaces/{$namespace}/ingresses/{$ingressName}");
        } catch (KubernetesApiException $e) {
            if (stripos($e->getMessage(), 'not found') === false) {
                throw $e;
            }
        }

        $this->deleteService($namespace, $serviceName);
    }

    public function getRequestLog(): array
    {
        return $this->client->getRequestLog();
    }

    public function resetRequestLog(): void
    {
        $this->client->resetRequestLog();
    }

    private function assertValidLabel(string $value, string $field): string
    {
        if (!preg_match(self::DNS_1123_LABEL, $value) || strlen($value) > 253) {
            throw new \InvalidArgumentException("Invalid {$field}: {$value}");
        }
        return $value;
    }

    private function assertValidPort(int $port): int
    {
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException("Invalid port: {$port}");
        }
        return $port;
    }

    private function assertValidHost(string $host): string
    {
        return $this->assertValidSubdomain($host, 'host');
    }

    private function assertValidSubdomain(string $value, string $field): string
    {
        if (!preg_match(self::DNS_1123_SUBDOMAIN, $value) || strlen($value) > 253) {
            throw new \InvalidArgumentException("Invalid {$field}: {$value}");
        }
        return $value;
    }
}
