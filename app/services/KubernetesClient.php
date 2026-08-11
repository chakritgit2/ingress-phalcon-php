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
    private ?array $lastRequest = null;

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
     * The method/path/body of the most recent request this client attempted
     * to send — recorded *before* the HTTP call, so it reflects what was
     * actually sent even when the call then fails. Used to log the literal
     * outbound command for audit purposes (see KubernetesTask).
     */
    public function getLastRequest(): ?array
    {
        return $this->lastRequest;
    }

    private function request(string $method, string $path, ?array $body = null): array
    {
        $this->lastRequest = ['method' => $method, 'path' => $path, 'body' => $body];

        try {
            $options = $body !== null ? ['json' => $body] : [];
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
