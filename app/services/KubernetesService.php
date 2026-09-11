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
    // Distinct from REQUEST_ID_LABEL: statefulset_requests.id and
    // ingress_requests.id are independent auto-increment sequences, so a
    // shared label key could match the wrong Service across the two flows
    // (e.g. ingress request #5 and statefulset request #5 in the same
    // namespace) when findServiceByRequestId()/findServiceByStatefulSetRequestId()
    // look one up by id alone.
    private const STATEFULSET_REQUEST_ID_LABEL = 'ingress-selfservice.advws.com/statefulset-request-id';
    private const NODE_ADMIN_PATH_ENV_NAME = 'NODE_ADMIN_PATH';
    private const NODE_ADMIN_PATH_VALUE = '/nodeadmin';
    private const NODE_ADMIN_PATH_ORIGINAL_VALUE = '/hello-world';
    private const NO_LINELOGIN_ENV_NAME = 'NO_LINELOGIN';
    private const NO_LINELOGIN_VALUE = 'yes';
    private const NO_LINELOGIN_ORIGINAL_VALUE = 'false';

    // The Service's own exposed port is always 80 — for NodePort-type it's
    // irrelevant anyway (external access goes through the k8s-assigned
    // nodePort instead), and for Ingress-type the backend just needs a
    // fixed, known port to address the Service on. Only targetPort (which
    // container port traffic actually gets forwarded to) varies, and that's
    // determined by the Deployment's own container name, not user input.
    private const SERVICE_PORT = 80;
    private const NODERED_CONTAINER_NAME = 'nodered';
    private const NODERED_TARGET_PORT = 1880;
    private const DEFAULT_TARGET_PORT = 80;

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
                'namespace' => $namespace,
                'replicas' => $item['spec']['replicas'] ?? 0,
                'container_names' => $this->extractContainerNames($item),
            ],
            $result['items'] ?? []
        );
    }

    public function listAllDeployments(): array
    {
        $result = $this->client->get('/apis/apps/v1/deployments');

        return array_map(
            fn (array $item) => [
                'name' => $item['metadata']['name'],
                'namespace' => $item['metadata']['namespace'],
                'replicas' => $item['spec']['replicas'] ?? 0,
                'container_names' => $this->extractContainerNames($item),
            ],
            $result['items'] ?? []
        );
    }

    /**
     * The Deployment's own metadata.name is an arbitrary label chosen per
     * app and tells you nothing about what's actually running in it — two
     * unrelated apps can share a name, and the same logical service (e.g.
     * "nodered") can be deployed under any Deployment name. The pod spec's
     * container name(s) are what actually identify the running image, so
     * that's what callers (e.g. the nodered default-port picker) should
     * match against instead of $item['metadata']['name'].
     *
     * @return string[]
     */
    private function extractContainerNames(array $item): array
    {
        $containers = $item['spec']['template']['spec']['containers'] ?? [];
        return array_map(fn (array $c) => $c['name'] ?? '', $containers);
    }

    /**
     * The real target port to forward to, per $deployment's own container
     * name(s) — not whatever the ingress request's target_port happens to
     * hold. nodered's own container listens on 1880; everything else
     * defaults to 80.
     */
    private function resolveTargetPort(array $deployment): int
    {
        $containerNames = $this->extractContainerNames($deployment);

        return in_array(self::NODERED_CONTAINER_NAME, $containerNames, true)
            ? self::NODERED_TARGET_PORT
            : self::DEFAULT_TARGET_PORT;
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

    public function listStatefulSets(string $namespace): array
    {
        $namespace = $this->assertValidLabel($namespace, 'namespace');
        $result = $this->client->get("/apis/apps/v1/namespaces/{$namespace}/statefulsets");

        return array_map(
            fn (array $item) => [
                'name' => $item['metadata']['name'],
                'namespace' => $namespace,
                'replicas' => $item['spec']['replicas'] ?? 0,
                'container_names' => $this->extractContainerNames($item),
            ],
            $result['items'] ?? []
        );
    }

    public function listAllStatefulSets(): array
    {
        $result = $this->client->get('/apis/apps/v1/statefulsets');

        return array_map(
            fn (array $item) => [
                'name' => $item['metadata']['name'],
                'namespace' => $item['metadata']['namespace'],
                'replicas' => $item['spec']['replicas'] ?? 0,
                'container_names' => $this->extractContainerNames($item),
            ],
            $result['items'] ?? []
        );
    }

    public function getStatefulSet(string $namespace, string $name): ?array
    {
        $namespace = $this->assertValidLabel($namespace, 'namespace');
        $name = $this->assertValidLabel($name, 'statefulset name');

        try {
            return $this->client->get("/apis/apps/v1/namespaces/{$namespace}/statefulsets/{$name}");
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
     * @return array{service_name: string, node_port: int, k8s_uid: string, node_admin_path: ?array, login_bypass: ?array}
     */
    public function createNodePortService(string $namespace, string $deploymentName, int $targetPort, int $requestId, ?int $preferredNodePort = null, bool $manageNodeAdminPath = true, bool $manageLoginBypass = false): array
    {
        $namespace = $this->assertValidLabel($namespace, 'namespace');
        $deploymentName = $this->assertValidLabel($deploymentName, 'deployment name');
        $targetPort = $this->assertValidPort($targetPort);
        if ($preferredNodePort !== null) {
            $preferredNodePort = $this->assertValidPort($preferredNodePort);
        }

        // Idempotency guard: if a previous attempt for this exact
        // ingress_requests row already created a Service (e.g. the bot
        // crashed/was killed after the POST succeeded but before it could
        // record success), find it by its stable label instead of creating
        // a second, orphaned one.
        $existing = $this->findServiceByRequestId($namespace, $requestId);
        if ($existing !== null) {
            return $existing + ['node_admin_path' => null, 'login_bypass' => null];
        }

        $deployment = $this->getDeployment($namespace, $deploymentName);
        if ($deployment === null) {
            throw new KubernetesApiException("Deployment {$deploymentName} not found in namespace {$namespace}");
        }

        $selector = $deployment['spec']['selector']['matchLabels'] ?? [];
        if (empty($selector)) {
            throw new KubernetesApiException("Deployment {$deploymentName} has no matchLabels selector to target");
        }

        $nodeAdminPath = $manageNodeAdminPath ? $this->syncNodeAdminPathEnv($namespace, $deploymentName, $deployment) : null;
        $loginBypass = $manageLoginBypass ? $this->syncLoginBypassEnv($namespace, $deploymentName, $deployment) : null;

        $resolvedTargetPort = $this->resolveTargetPort($deployment);

        $body = $this->buildServiceBody($namespace, $deploymentName, $resolvedTargetPort, $requestId, $selector, 'tmp-nodeport-', 'NodePort', $preferredNodePort);

        $created = $preferredNodePort !== null
            ? $this->postServiceWithPortFallback($namespace, $body, $preferredNodePort)
            : $this->client->post("/api/v1/namespaces/{$namespace}/services", $body);

        return [
            'service_name' => $created['metadata']['name'],
            'node_port' => $created['spec']['ports'][0]['nodePort'],
            'k8s_uid' => $created['metadata']['uid'],
            'node_admin_path' => $nodeAdminPath,
            'login_bypass' => $loginBypass,
        ];
    }

    /**
     * Tries to (re)create the Service pinned to $preferredNodePort — used by
     * the nightly reopen sweep so a developer's IP:port doesn't change every
     * morning. If the k8s API rejects the pinned port (something else
     * claimed it while this Service was closed overnight), retries once
     * with no nodePort field so k8s assigns a fresh one instead of failing
     * the whole reopen.
     */
    private function postServiceWithPortFallback(string $namespace, array $body, int $preferredNodePort): array
    {
        try {
            return $this->client->post("/api/v1/namespaces/{$namespace}/services", $body);
        } catch (KubernetesApiException $e) {
            if (stripos($e->getMessage(), 'already allocated') === false) {
                throw $e;
            }

            unset($body['spec']['ports'][0]['nodePort']);
            return $this->client->post("/api/v1/namespaces/{$namespace}/services", $body);
        }
    }

    /**
     * @return array{service_name: string, node_port: int, k8s_uid: string}
     */
    public function createNodePortServiceForStatefulSet(string $namespace, string $statefulSetName, int $targetPort, int $requestId, ?int $preferredNodePort = null): array
    {
        $namespace = $this->assertValidLabel($namespace, 'namespace');
        $statefulSetName = $this->assertValidLabel($statefulSetName, 'statefulset name');
        $targetPort = $this->assertValidPort($targetPort);
        if ($preferredNodePort !== null) {
            $preferredNodePort = $this->assertValidPort($preferredNodePort);
        }

        $existing = $this->findServiceByStatefulSetRequestId($namespace, $requestId);
        if ($existing !== null) {
            return $existing;
        }

        $statefulSet = $this->getStatefulSet($namespace, $statefulSetName);
        if ($statefulSet === null) {
            throw new KubernetesApiException("StatefulSet {$statefulSetName} not found in namespace {$namespace}");
        }

        $selector = $statefulSet['spec']['selector']['matchLabels'] ?? [];
        if (empty($selector)) {
            throw new KubernetesApiException("StatefulSet {$statefulSetName} has no matchLabels selector to target");
        }

        $resolvedTargetPort = $this->resolveTargetPort($statefulSet);

        $body = $this->buildServiceBody($namespace, $statefulSetName, $resolvedTargetPort, $requestId, $selector, 'tmp-sts-nodeport-', 'NodePort', $preferredNodePort, self::STATEFULSET_REQUEST_ID_LABEL);

        $created = $preferredNodePort !== null
            ? $this->postServiceWithPortFallback($namespace, $body, $preferredNodePort)
            : $this->client->post("/api/v1/namespaces/{$namespace}/services", $body);

        return [
            'service_name' => $created['metadata']['name'],
            'node_port' => $created['spec']['ports'][0]['nodePort'],
            'k8s_uid' => $created['metadata']['uid'],
        ];
    }

    /**
     * @return array{service_name: string, ingress_name: string, k8s_uid: string, node_admin_path: ?array, login_bypass: ?array}
     */
    public function createIngress(string $namespace, string $deploymentName, int $targetPort, string $host, string $secretName, int $requestId, bool $manageNodeAdminPath = true, bool $manageLoginBypass = false): array
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
            return $existingIngress + ['node_admin_path' => null, 'login_bypass' => null];
        }

        $deployment = $this->getDeployment($namespace, $deploymentName);
        if ($deployment === null) {
            throw new KubernetesApiException("Deployment {$deploymentName} not found in namespace {$namespace}");
        }

        $selector = $deployment['spec']['selector']['matchLabels'] ?? [];
        if (empty($selector)) {
            throw new KubernetesApiException("Deployment {$deploymentName} has no matchLabels selector to target");
        }

        $nodeAdminPath = $manageNodeAdminPath ? $this->syncNodeAdminPathEnv($namespace, $deploymentName, $deployment) : null;
        $loginBypass = $manageLoginBypass ? $this->syncLoginBypassEnv($namespace, $deploymentName, $deployment) : null;

        $labels = $this->buildManagedLabels($deploymentName, $requestId);

        $existingService = $this->findServiceByRequestId($namespace, $requestId);
        if ($existingService !== null) {
            $serviceName = $existingService['service_name'];
        } else {
            $resolvedTargetPort = $this->resolveTargetPort($deployment);
            $serviceBody = $this->buildServiceBody($namespace, $deploymentName, $resolvedTargetPort, $requestId, $selector, 'tmp-ingress-svc-', 'ClusterIP');
            $createdService = $this->client->post("/api/v1/namespaces/{$namespace}/services", $serviceBody);
            $serviceName = $createdService['metadata']['name'];
        }

        $ingressBody = $this->buildIngressBody($namespace, $host, $secretName, $serviceName, self::SERVICE_PORT, $labels);

        $createdIngress = $this->client->post("/apis/networking.k8s.io/v1/namespaces/{$namespace}/ingresses", $ingressBody);

        return [
            'service_name' => $serviceName,
            'ingress_name' => $createdIngress['metadata']['name'],
            'k8s_uid' => $createdIngress['metadata']['uid'],
            'node_admin_path' => $nodeAdminPath,
            'login_bypass' => $loginBypass,
        ];
    }

    /**
     * Scans every container in the target Deployment's pod spec for an env
     * entry named NODE_ADMIN_PATH. If found anywhere with a value other than
     * NODE_ADMIN_PATH_VALUE, patches it there via a single JSON Patch call —
     * one `replace` op per occurrence, since a Deployment can (rarely) carry
     * more than one container defining it. Skips the PATCH entirely if every
     * occurrence already holds the target value: patching a Deployment's pod
     * template triggers a rollout, so a no-op patch would otherwise restart
     * pods on every retry/re-create against an already-correct Deployment.
     *
     * A failed PATCH (e.g. the ServiceAccount lacks `patch` on deployments)
     * is caught rather than propagated: this sync is a side effect of
     * creating the Ingress/Service, not the point of the request, so it
     * must never fail the whole create command. The error is returned
     * instead so the caller can still audit-log it.
     *
     * @return array{found: bool, patched: bool, error?: string}
     */
    private function syncNodeAdminPathEnv(string $namespace, string $deploymentName, array $deployment): array
    {
        return $this->patchDeploymentEnvIfPresent($namespace, $deploymentName, $deployment, self::NODE_ADMIN_PATH_ENV_NAME, self::NODE_ADMIN_PATH_VALUE);
    }

    /**
     * Counterpart to syncNodeAdminPathEnv() — called when an ingress/nodeport
     * request is deleted or expires, to put NODE_ADMIN_PATH back to its
     * original value. Callers (KubernetesTask) are responsible for checking
     * no other active request still targets the same Deployment first — this
     * method has no way to know that on its own, it just unconditionally
     * reverts whatever it finds.
     *
     * Unlike createNodePortService()/createIngress(), $deployment isn't
     * already in hand here (delete has no reason to look it up otherwise),
     * so this fetches it itself; a missing Deployment (e.g. already deleted
     * by its owner) is reported as found=false rather than an error.
     *
     * @return array{found: bool, reverted: bool, error?: string}
     */
    public function revertNodeAdminPathEnv(string $namespace, string $deploymentName): array
    {
        return $this->revertDeploymentEnv($namespace, $deploymentName, self::NODE_ADMIN_PATH_ENV_NAME, self::NODE_ADMIN_PATH_ORIGINAL_VALUE);
    }

    /**
     * Same idea as syncNodeAdminPathEnv(), but for the NO_LINELOGIN env var
     * that the "Login Bypass" checkbox on the ingress create/edit form
     * controls. Only invoked when that checkbox was checked (see
     * createNodePortService()/createIngress()'s $manageLoginBypass param) —
     * unlike NODE_ADMIN_PATH, this is opt-in per request rather than always
     * attempted.
     *
     * @return array{found: bool, patched: bool, error?: string}
     */
    private function syncLoginBypassEnv(string $namespace, string $deploymentName, array $deployment): array
    {
        return $this->patchDeploymentEnvIfPresent($namespace, $deploymentName, $deployment, self::NO_LINELOGIN_ENV_NAME, self::NO_LINELOGIN_VALUE);
    }

    /**
     * Counterpart to syncLoginBypassEnv() — called when a request whose
     * login_bypass flag was set is deleted or expires, to put NO_LINELOGIN
     * back to 'false'. Same "caller must check no other active bypassed
     * request still targets the same Deployment" contract as
     * revertNodeAdminPathEnv().
     *
     * @return array{found: bool, reverted: bool, error?: string}
     */
    public function revertLoginBypassEnv(string $namespace, string $deploymentName): array
    {
        return $this->revertDeploymentEnv($namespace, $deploymentName, self::NO_LINELOGIN_ENV_NAME, self::NO_LINELOGIN_ORIGINAL_VALUE);
    }

    /**
     * Shared by revertNodeAdminPathEnv() and revertLoginBypassEnv() — fetches
     * the Deployment fresh (delete has no reason to already have it in hand)
     * and patches $envName back to $originalValue if present.
     *
     * @return array{found: bool, reverted: bool, error?: string}
     */
    private function revertDeploymentEnv(string $namespace, string $deploymentName, string $envName, string $originalValue): array
    {
        $namespace = $this->assertValidLabel($namespace, 'namespace');
        $deploymentName = $this->assertValidLabel($deploymentName, 'deployment name');

        $deployment = $this->getDeployment($namespace, $deploymentName);
        if ($deployment === null) {
            return ['found' => false, 'reverted' => false];
        }

        $result = $this->patchDeploymentEnvIfPresent($namespace, $deploymentName, $deployment, $envName, $originalValue);

        $reverted = ['found' => $result['found'], 'reverted' => $result['patched']];
        if (isset($result['error'])) {
            $reverted['error'] = $result['error'];
        }

        return $reverted;
    }

    /**
     * Scans every container in the target Deployment's pod spec for an env
     * entry named $envName. If found anywhere with a value other than
     * $targetValue, patches it there via a single JSON Patch call — one
     * `replace` op per occurrence, since a Deployment can (rarely) carry
     * more than one container defining it. Never adds the env var to a
     * container that doesn't already declare it (found=false instead) —
     * callers only manage env vars a Deployment already opts into. Skips the
     * PATCH entirely if every occurrence already holds the target value:
     * patching a Deployment's pod template triggers a rollout, so a no-op
     * patch would otherwise restart pods on every retry/re-create against an
     * already-correct Deployment.
     *
     * A failed PATCH (e.g. the ServiceAccount lacks `patch` on deployments)
     * is caught rather than propagated: this sync is a side effect of
     * creating/deleting the Ingress/Service, not the point of the request,
     * so it must never fail the whole command. The error is returned instead
     * so the caller can still audit-log it.
     *
     * Shared by syncNodeAdminPathEnv()/revertNodeAdminPathEnv() and
     * syncLoginBypassEnv()/revertLoginBypassEnv() — same scan-every-
     * container-and-patch-what-differs logic, only $envName/$targetValue
     * differ.
     *
     * @return array{found: bool, patched: bool, error?: string}
     */
    private function patchDeploymentEnvIfPresent(string $namespace, string $deploymentName, array $deployment, string $envName, string $targetValue): array
    {
        $containers = $deployment['spec']['template']['spec']['containers'] ?? [];
        $ops = [];
        $found = false;

        foreach ($containers as $ci => $container) {
            foreach ($container['env'] ?? [] as $ei => $envVar) {
                if (($envVar['name'] ?? null) !== $envName) {
                    continue;
                }

                $found = true;

                if (($envVar['value'] ?? null) !== $targetValue) {
                    $ops[] = [
                        'op' => 'replace',
                        'path' => "/spec/template/spec/containers/{$ci}/env/{$ei}/value",
                        'value' => $targetValue,
                    ];
                }
            }
        }

        if (!$found) {
            return ['found' => false, 'patched' => false];
        }

        if (empty($ops)) {
            return ['found' => true, 'patched' => false];
        }

        try {
            $this->client->patch("/apis/apps/v1/namespaces/{$namespace}/deployments/{$deploymentName}", $ops);
        } catch (KubernetesApiException $e) {
            return ['found' => true, 'patched' => false, 'error' => $e->getMessage()];
        }

        return ['found' => true, 'patched' => true];
    }

    private function buildManagedLabels(string $workloadName, int $requestId, string $requestIdLabel = self::REQUEST_ID_LABEL): array
    {
        return [
            'app.kubernetes.io/managed-by' => 'ingress-selfservice',
            'advws-group' => 'company',
            'k8s-app' => $workloadName,
            $requestIdLabel => (string) $requestId,
        ];
    }

    /**
     * Shared by createNodePortService(), createNodePortServiceForStatefulSet(),
     * createIngress()'s backing Service, and the preview*Payload() methods
     * below. $selector is nullable so a preview (built with no live
     * Deployment/StatefulSet lookup) can represent "not yet resolved" as an
     * explicit `null` rather than guessing at a value. $requestIdLabel lets
     * createNodePortServiceForStatefulSet() key its idempotency label
     * separately from the Deployment flow's (see STATEFULSET_REQUEST_ID_LABEL).
     */
    private function buildServiceBody(string $namespace, string $workloadName, int $targetPort, int $requestId, ?array $selector, string $generateNamePrefix, string $serviceType, ?int $nodePort = null, string $requestIdLabel = self::REQUEST_ID_LABEL): array
    {
        $port = [
            'port' => self::SERVICE_PORT,
            'targetPort' => $targetPort,
            'protocol' => 'TCP',
        ];
        if ($nodePort !== null) {
            $port['nodePort'] = $nodePort;
        }

        return [
            'apiVersion' => 'v1',
            'kind' => 'Service',
            'metadata' => [
                'generateName' => $generateNamePrefix,
                'namespace' => $namespace,
                'labels' => $this->buildManagedLabels($workloadName, $requestId, $requestIdLabel),
            ],
            'spec' => [
                'type' => $serviceType,
                'selector' => $selector,
                'ports' => [$port],
            ],
        ];
    }

    /**
     * Shared by createIngress() and previewCreateIngressPayload(). $serviceName
     * is nullable so a preview (built before the idempotency lookup/service
     * create has happened) can represent "not yet resolved" explicitly.
     * $servicePort must match the backing Service's own exposed `port`
     * (always self::SERVICE_PORT, see buildServiceBody()) — Ingress talks to
     * the Service on that port, not the container's targetPort.
     */
    private function buildIngressBody(string $namespace, string $host, string $secretName, ?string $serviceName, int $servicePort, array $labels): array
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
                                    'port' => ['number' => $servicePort],
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

        // No live Deployment lookup here (IngressRequestService::buildPreviewPayload()
        // is pure local computation, no HTTP) — can't read the real container
        // name(s) to resolve targetPort the way createNodePortService() does,
        // so this just previews the shared 80 default. Overwritten with the
        // real value once the bot actually processes the command.
        return [[
            'method' => 'POST',
            'path' => "/api/v1/namespaces/{$namespace}/services",
            'body' => $this->buildServiceBody($namespace, $deploymentName, self::DEFAULT_TARGET_PORT, $requestId, null, 'tmp-nodeport-', 'NodePort'),
        ]];
    }

    public function previewCreateNodePortServiceForStatefulSetPayload(string $namespace, string $statefulSetName, int $targetPort, int $requestId): array
    {
        $namespace = $this->assertValidLabel($namespace, 'namespace');
        $statefulSetName = $this->assertValidLabel($statefulSetName, 'statefulset name');
        $targetPort = $this->assertValidPort($targetPort);

        // Same no-live-lookup caveat as previewCreateNodePortServicePayload().
        return [[
            'method' => 'POST',
            'path' => "/api/v1/namespaces/{$namespace}/services",
            'body' => $this->buildServiceBody($namespace, $statefulSetName, self::DEFAULT_TARGET_PORT, $requestId, null, 'tmp-sts-nodeport-', 'NodePort', null, self::STATEFULSET_REQUEST_ID_LABEL),
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

        // Same no-live-lookup caveat as previewCreateNodePortServicePayload().
        $servicePreview = [
            'method' => 'POST',
            'path' => "/api/v1/namespaces/{$namespace}/services",
            'body' => $this->buildServiceBody($namespace, $deploymentName, self::DEFAULT_TARGET_PORT, $requestId, null, 'tmp-ingress-svc-', 'ClusterIP'),
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
            'body' => $this->buildIngressBody($namespace, $host, $secretName, $placeholderServiceName, self::SERVICE_PORT, $labels),
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
     * Same idea as findServiceByRequestId(), but keyed off
     * STATEFULSET_REQUEST_ID_LABEL — see createNodePortServiceForStatefulSet().
     *
     * @return array{service_name: string, node_port: int, k8s_uid: string}|null
     */
    private function findServiceByStatefulSetRequestId(string $namespace, int $requestId): ?array
    {
        $query = http_build_query(['labelSelector' => self::STATEFULSET_REQUEST_ID_LABEL . '=' . $requestId]);
        $result = $this->client->get("/api/v1/namespaces/{$namespace}/services?{$query}");
        $items = $result['items'] ?? [];

        if (empty($items)) {
            return null;
        }

        $service = $items[0];

        return [
            'service_name' => $service['metadata']['name'],
            'node_port' => $service['spec']['ports'][0]['nodePort'] ?? 0,
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
        // 63, not 253: these values also get used as Kubernetes label
        // values (k8s-app, advws-group, etc.), and label values are capped
        // at 63 chars — a longer namespace/deployment/service/ingress name
        // would pass this check but then get rejected by the k8s API when
        // it's used as a label.
        if (!preg_match(self::DNS_1123_LABEL, $value) || strlen($value) > 63) {
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
