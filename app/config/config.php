<?php

use Phalcon\Config\Config;

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__, 2));
defined('APP_PATH') || define('APP_PATH', BASE_PATH . '/app');

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
        'password'   => getenv('DB_PASSWORD') ?: '',
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
