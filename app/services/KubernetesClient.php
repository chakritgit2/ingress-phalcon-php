<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

/**
 * Thin REST wrapper over the in-cluster Kubernetes API server, authenticated
 * with the Pod's own ServiceAccount token. Deliberately hand-rolled instead
 * of pulling in a full Kubernetes SDK — only a handful of endpoints are used
 * (see KubernetesService), so a small, fully-auditable client is preferable
 * for a tool that mutates cluster networking.
 */
class KubernetesClient
{
    private Client $http;
    private array $requestLog = [];

    public function __construct(string $apiHost, int $apiPort, string $bearerToken, string $caCertPath)
    {
        $options = [
            'base_uri' => sprintf('https://%s:%d', $apiHost, $apiPort),
            'headers' => [
                'Authorization' => 'Bearer ' . $bearerToken,
                'Accept' => 'application/json',
            ],
            'timeout' => 10,
        ];

        if (is_readable($caCertPath)) {
            $options['verify'] = $caCertPath;
        }

        $this->http = new Client($options);
    }

    public function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    public function post(string $path, array $body): array
    {
        return $this->request('POST', $path, $body);
    }

    public function delete(string $path): array
    {
        return $this->request('DELETE', $path);
    }

    /**
     * $ops is a JSON Patch document (RFC 6902) — a list of {op, path, value}
     * operations — sent with the content type the Kubernetes API requires
     * for it, since the default `application/json` used by post()/delete()
     * only works for full-resource bodies, not patches.
     */
    public function patch(string $path, array $ops): array
    {
        return $this->request('PATCH', $path, $ops, 'application/json-patch+json');
    }

    /**
     * The method/path/body of every mutating (POST/DELETE) request this
     * client has attempted to send since the last resetRequestLog() call,
     * in call order — recorded *before* each HTTP call, so an entry is
     * present even when that call then fails. Read-only GETs (idempotency
     * checks, Deployment lookups) are deliberately not recorded here; only
     * commands actually sent to Kubernetes matter for the audit trail (see
     * KubernetesTask).
     */
    public function getRequestLog(): array
    {
        return $this->requestLog;
    }

    public function resetRequestLog(): void
    {
        $this->requestLog = [];
    }

    private function request(string $method, string $path, ?array $body = null, ?string $contentType = null): array
    {
        if ($method !== 'GET') {
            $this->requestLog[] = ['method' => $method, 'path' => $path, 'body' => $body];
        }

        try {
            if ($body === null) {
                $options = [];
            } elseif ($contentType !== null) {
                $options = ['headers' => ['Content-Type' => $contentType], 'body' => json_encode($body)];
            } else {
                $options = ['json' => $body];
            }
            $response = $this->http->request($method, $path, $options);
            $contents = (string) $response->getBody();
            return $contents !== '' ? json_decode($contents, true) : [];
        } catch (RequestException $e) {
            $message = $e->getMessage();
            if ($e->hasResponse()) {
                $decoded = json_decode((string) $e->getResponse()->getBody(), true);
                $message = $decoded['message'] ?? $message;
            }
            throw new KubernetesApiException($message, 0, $e);
        } catch (GuzzleException $e) {
            throw new KubernetesApiException($e->getMessage(), 0, $e);
        }
    }
}
