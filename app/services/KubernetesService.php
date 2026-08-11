<?php

namespace App\Services;

class KubernetesService implements KubernetesServiceInterface
{
    private const DNS_1123_LABEL = '/^[a-z0-9]([-a-z0-9]*[a-z0-9])?$/';
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

        $body = [
            'apiVersion' => 'v1',
            'kind' => 'Service',
            'metadata' => [
                'generateName' => 'tmp-nodeport-',
                'namespace' => $namespace,
                'labels' => [
                    'app.kubernetes.io/managed-by' => 'ingress-selfservice',
                    self::REQUEST_ID_LABEL => (string) $requestId,
                ],
            ],
            'spec' => [
                'type' => 'NodePort',
                'selector' => $selector,
                'ports' => [[
                    'port' => $targetPort,
                    'targetPort' => $targetPort,
                    'protocol' => 'TCP',
                ]],
            ],
        ];

        $created = $this->client->post("/api/v1/namespaces/{$namespace}/services", $body);

        return [
            'service_name' => $created['metadata']['name'],
            'node_port' => $created['spec']['ports'][0]['nodePort'],
            'k8s_uid' => $created['metadata']['uid'],
        ];
    }

    /**
     * @return array{service_name: string, node_port: int, k8s_uid: string}|null
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
            'node_port' => $service['spec']['ports'][0]['nodePort'],
            'k8s_uid' => $service['metadata']['uid'],
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

    public function getLastRequest(): ?array
    {
        return $this->client->getLastRequest();
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
}
