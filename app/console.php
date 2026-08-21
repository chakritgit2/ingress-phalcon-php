<?php

use App\Services\AuditLogService;
use App\Services\K8sConfigResolver;
use App\Services\KubernetesClient;
use App\Services\KubernetesService;
use App\Services\LineLoginService;
use App\Services\MockKubernetesService;
use App\Services\SettingsService;
use MongoDB\Client as MongoClient;
use Phalcon\Autoload\Loader;
use Phalcon\Cli\Console;
use Phalcon\Cli\Dispatcher as CliDispatcher;
use Phalcon\Db\Adapter\Pdo\Mysql as MysqlAdapter;
use Phalcon\Di\FactoryDefault\Cli as CliDi;

error_reporting(E_ALL);
date_default_timezone_set('Asia/Bangkok');

require __DIR__ . '/../vendor/autoload.php';

/** @var \Phalcon\Config\Config $config */
$config = require __DIR__ . '/config/config.php';

$loader = new Loader();
$loader->setNamespaces([
    'App\\Models'   => $config->application->modelsDir,
    'App\\Services' => $config->application->servicesDir,
    'App\\Tasks'    => $config->application->tasksDir,
]);
$loader->register();

$di = new CliDi();

$di->setShared('config', fn () => $config);

$di->setShared('db', function () use ($config) {
    return new MysqlAdapter([
        'host'     => $config->database->host,
        'port'     => $config->database->port,
        'username' => $config->database->username,
        'password' => $config->database->password,
        'dbname'   => $config->database->dbname,
        'charset'  => $config->database->charset,
    ]);
});

$di->setShared('kubernetesClient', function () use ($config) {
    $kubeconfig = K8sConfigResolver::resolve($config->kubernetes->server_config_path);
    return new KubernetesClient($kubeconfig['host'], $kubeconfig['port'], $kubeconfig['token'], $kubeconfig['ca_path']);
});

// Mirrors the same logic in app/config/services.php: in-cluster (the
// CronJob pod) always uses its own ServiceAccount token via
// K8sConfigResolver, regardless of APP_ENV. DEV-ONLY: outside a cluster with
// no SERVER_CONFIG, fall back to MockKubernetesService so the bot
// (processCommands/pruneExpired) is testable in the docker-compose mockup.
$di->setShared('kubernetesService', function () use ($config) {
    if (!K8sConfigResolver::isInCluster() && getenv('APP_ENV') === 'local' && $config->kubernetes->server_config_path === '') {
        return new MockKubernetesService();
    }

    return new KubernetesService($this->get('kubernetesClient'));
});

$di->setShared('auditLogService', fn () => new AuditLogService());

$di->setShared('settingsService', function () {
    return new SettingsService($this->get('auditLogService'));
});

// Mirrors app/config/services.php's 'mongo'/'lineLoginService' wiring —
// this console DI is a separate container from the web app's, so it needs
// its own copy. KubernetesTask (run from here, see k8s/cronjob.yaml) is
// the only consumer of lineLoginService.
$di->setShared('mongo', function () use ($config) {
    $auth = $config->mongo->username !== ''
        ? rawurlencode($config->mongo->username) . ':' . rawurlencode($config->mongo->password) . '@'
        : '';
    $uri = "mongodb://{$auth}{$config->mongo->host}:{$config->mongo->port}";

    $client = new MongoClient($uri);
    return $client->selectDatabase($config->mongo->dbname);
});

$di->setShared('lineLoginService', function () {
    return new LineLoginService($this->get('mongo'));
});

$di->setShared('dispatcher', function () {
    $dispatcher = new CliDispatcher();
    $dispatcher->setDefaultNamespace('App\\Tasks');
    return $dispatcher;
});

$console = new Console($di);

$argv = $_SERVER['argv'];
$task = $argv[1] ?? 'main';
$action = $argv[2] ?? 'main';
$params = array_slice($argv, 3);

$console->handle([
    'task'   => $task,
    'action' => $action,
    'params' => $params,
]);
