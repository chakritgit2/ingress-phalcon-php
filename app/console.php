<?php

use App\Services\AuditLogService;
use App\Services\KubeconfigLoader;
use App\Services\KubernetesClient;
use App\Services\KubernetesService;
use App\Services\MockKubernetesService;
use Phalcon\Autoload\Loader;
use Phalcon\Cli\Console;
use Phalcon\Cli\Dispatcher as CliDispatcher;
use Phalcon\Db\Adapter\Pdo\Mysql as MysqlAdapter;
use Phalcon\Di\FactoryDefault\Cli as CliDi;

error_reporting(E_ALL);

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
    $kubeconfig = KubeconfigLoader::load($config->kubernetes->server_config_path);
    return new KubernetesClient($kubeconfig['host'], $kubeconfig['port'], $kubeconfig['token'], $kubeconfig['ca_path']);
});

// DEV-ONLY: mirrors the same fallback in app/config/services.php so the
// bot (processCommands/pruneExpired) is testable in the docker-compose
// mockup without a reachable cluster. Never takes effect once SERVER_CONFIG
// is configured.
$di->setShared('kubernetesService', function () use ($config) {
    if (getenv('APP_ENV') === 'local' && $config->kubernetes->server_config_path === '') {
        return new MockKubernetesService();
    }

    return new KubernetesService($this->get('kubernetesClient'));
});

$di->setShared('auditLogService', fn () => new AuditLogService());

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
