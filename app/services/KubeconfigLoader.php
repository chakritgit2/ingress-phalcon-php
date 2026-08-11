<?php

namespace App\Services;

use Symfony\Component\Yaml\Yaml;

/**
 * Parses a standard kubeconfig YAML file (the same format `~/.kube/config`
 * uses) into the pieces KubernetesClient needs. The path is supplied via
 * the SERVER_CONFIG env var — in-cluster this points at a kubeconfig
 * mounted from a Secret; locally it can point at a developer's own
 * kubeconfig or a scoped one generated for this app's ServiceAccount.
 */
class KubeconfigLoader
{
    /**
     * @return array{host: string, port: int, token: string, ca_path: ?string}
     */
    public static function load(string $path): array
    {
        if ($path === '' || !is_readable($path)) {
            throw new \RuntimeException("SERVER_CONFIG kubeconfig file not readable: {$path}");
        }

        $config = Yaml::parseFile($path);

        $currentContext = $config['current-context'] ?? null;
        $context = self::findNamed($config['contexts'] ?? [], $currentContext, 'context');

        $cluster = self::findNamed($config['clusters'] ?? [], $context['cluster'] ?? null, 'cluster');
        $user = self::findNamed($config['users'] ?? [], $context['user'] ?? null, 'user');

        $server = $cluster['server'] ?? null;
        if (!$server) {
            throw new \RuntimeException('Kubeconfig cluster entry is missing "server"');
        }

        $parsedUrl = parse_url($server);
        $host = $parsedUrl['host'] ?? null;
        if (!$host) {
            throw new \RuntimeException("Kubeconfig cluster server is not a valid URL: {$server}");
        }
        $port = $parsedUrl['port'] ?? (($parsedUrl['scheme'] ?? 'https') === 'http' ? 80 : 443);

        $caPath = self::resolveCaPath($cluster);
        $token = self::resolveToken($user);

        return [
            'host' => $host,
            'port' => (int) $port,
            'token' => $token,
            'ca_path' => $caPath,
        ];
    }

    private static function findNamed(array $items, ?string $name, string $key): array
    {
        foreach ($items as $item) {
            if (($item['name'] ?? null) === $name) {
                return $item[$key] ?? [];
            }
        }
        throw new \RuntimeException("Kubeconfig entry \"{$name}\" not found under \"{$key}s\"");
    }

    private static function resolveCaPath(array $cluster): ?string
    {
        if (!empty($cluster['certificate-authority-data'])) {
            $caPath = tempnam(sys_get_temp_dir(), 'k8s-ca-');
            file_put_contents($caPath, base64_decode($cluster['certificate-authority-data']));
            return $caPath;
        }

        if (!empty($cluster['certificate-authority'])) {
            return $cluster['certificate-authority'];
        }

        return null;
    }

    private static function resolveToken(array $user): string
    {
        if (!empty($user['token'])) {
            return $user['token'];
        }

        if (!empty($user['tokenFile'])) {
            return trim((string) file_get_contents($user['tokenFile']));
        }

        throw new \RuntimeException('Kubeconfig user entry has no "token" or "tokenFile" — client-certificate auth is not supported, use a token-based ServiceAccount kubeconfig');
    }
}
