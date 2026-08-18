<?php

use Phalcon\Config\Config;

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__, 2));
defined('APP_PATH') || define('APP_PATH', BASE_PATH . '/app');

// Loads BASE_PATH/.env into the process environment for local runs (e.g.
// `php -S`) where nothing else does this — docker-compose's `env_file: .env`
// already handles it in containers, so a real env var always wins over the
// file. Single-line values only; not a full dotenv parser.
(function () {
    $envPath = BASE_PATH . '/.env';
    if (!is_readable($envPath)) {
        return;
    }

    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        if ($key !== '' && getenv($key) === false) {
            putenv($key . '=' . trim($value));
        }
    }
})();

return new Config([
    'application' => [
        'controllersDir' => APP_PATH . '/controllers/',
        'middlewareDir'  => APP_PATH . '/middleware/',
        'modelsDir'      => APP_PATH . '/models/',
        'servicesDir'    => APP_PATH . '/services/',
        'tasksDir'       => APP_PATH . '/tasks/',
        'migrationsDir'  => APP_PATH . '/migrations/',
        'viewsDir'       => APP_PATH . '/views/',
        'baseUri'        => getenv('BASE_URI') ?: '/',
    ],

    'database' => [
        'adapter'    => 'Mysql',
        'host'       => getenv('DB_HOST') ?: 'localhost',
        'port'       => (int) (getenv('DB_PORT') ?: 3306),
        'username'   => getenv('DB_USER') ?: '',
        'password'   => getenv('DB_PASS') ?: '',
        'dbname'     => getenv('DB_NAME') ?: '',
        'charset'    => 'utf8mb4',
    ],

    'google' => [
        'client_id'     => getenv('GOOGLE_CLIENT_ID') ?: '',
        'client_secret' => getenv('GOOGLE_CLIENT_SECRET') ?: '',
        'redirect_uri'  => getenv('GOOGLE_REDIRECT_URI') ?: '',
        'hosted_domain' => getenv('GOOGLE_HD') ?: 'advws.com',
    ],

    'cookie' => [
        'sign_key' => getenv('COOKIE_SIGN_KEY') ?: '',
    ],

    'kubernetes' => [
        'node_ip'           => getenv('K8S_NODE_IP') ?: '192.168.33.31',
        'server_config_path' => getenv('SERVER_CONFIG') ?: '',
    ],
]);
