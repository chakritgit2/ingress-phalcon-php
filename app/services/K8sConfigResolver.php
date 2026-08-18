<?php

namespace App\Services;

/**
 * Picks how to authenticate to the Kubernetes API: the Pod's own
 * automatically-mounted ServiceAccount token when running in-cluster (every
 * Pod gets one unless automountServiceAccountToken is explicitly disabled —
 * no Secret, no manually-issued token, no SERVER_CONFIG needed), falling
 * back to SERVER_CONFIG (see KubeconfigLoader) for anywhere else — local
 * dev via docker-compose, or a CLI run outside the cluster.
 *
 * In-cluster takes priority unconditionally, including over a misconfigured
 * APP_ENV=local: a real Pod always has real cluster credentials available,
 * so there's never a reason to fall through to SERVER_CONFIG/mock once
 * KUBERNETES_SERVICE_HOST is present.
 */
class K8sConfigResolver
{
    private const SA_DIR = '/var/run/secrets/kubernetes.io/serviceaccount';

    public static function isInCluster(): bool
    {
        return getenv('KUBERNETES_SERVICE_HOST') !== false
            && is_readable(self::SA_DIR . '/token');
    }

    /**
     * @return array{host: string, port: int, token: string, ca_path: ?string}
     */
    public static function resolve(string $serverConfigPath): array
    {
        if (self::isInCluster()) {
            return [
                'host' => getenv('KUBERNETES_SERVICE_HOST'),
                'port' => (int) (getenv('KUBERNETES_SERVICE_PORT') ?: 443),
                'token' => trim((string) file_get_contents(self::SA_DIR . '/token')),
                'ca_path' => self::SA_DIR . '/ca.crt',
            ];
        }

        return KubeconfigLoader::load($serverConfigPath);
    }
}
