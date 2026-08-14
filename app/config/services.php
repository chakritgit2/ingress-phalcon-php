<?php

use App\Middleware\AuthMiddleware;
use App\Services\AuditLogService;
use App\Services\AuthService;
use App\Services\GoogleAuthService;
use App\Services\IngressRequestService;
use App\Services\KubeconfigLoader;
use App\Services\KubernetesClient;
use App\Services\KubernetesService;
use App\Services\MockKubernetesService;
use App\Services\SettingsService;
use Phalcon\Cache\Cache;
use Phalcon\Db\Adapter\Pdo\Mysql as MysqlAdapter;
use Phalcon\Di\FactoryDefault;
use Phalcon\Encryption\Security;
use Phalcon\Events\Manager as EventsManager;
use Phalcon\Flash\Session as Flash;
use Phalcon\Mvc\Dispatcher;
use Phalcon\Mvc\Model\Metadata\Memory as MetaDataMemory;
use Phalcon\Mvc\Url as UrlResolver;
use Phalcon\Mvc\View;
use Phalcon\Session\Adapter\Stream as SessionStream;
use Phalcon\Session\Manager as SessionManager;
use Phalcon\Cache\Adapter\Stream as CacheStream;
use Phalcon\Storage\SerializerFactory;

/** @var \Phalcon\Config\Config $config */

$di = new FactoryDefault();

$di->setShared('config', fn () => $config);

$di->setShared('url', function () use ($config) {
    $url = new UrlResolver();
    $url->setBaseUri($config->application->baseUri);
    return $url;
});

$di->setShared('session', function () use ($config) {
    // 'secure' is env-gated: a secure cookie is silently dropped by the
    // browser on a non-HTTPS origin, which would break login there. Local
    // dev runs over plain HTTP, hence the APP_ENV check. APP_HTTPS=0 is a
    // separate, explicit opt-out for deployments that are legitimately
    // HTTP-only forever (e.g. an internal IP:NodePort with no domain/TLS)
    // — anything else (unset included) keeps the safe HTTPS-assumed default.
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => getenv('APP_ENV') !== 'local' && getenv('APP_HTTPS') !== '0',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    $session = new SessionManager();
    $files = new SessionStream(['savePath' => sys_get_temp_dir()]);
    $session->setAdapter($files);
    $session->start();
    return $session;
});

$di->setShared('security', function () {
    $security = new Security($this->get('session'));
    $security->setWorkFactor(12);
    return $security;
});

$di->setShared('db', function () use ($config) {
    $params = [
        'host'     => $config->database->host,
        'port'     => $config->database->port,
        'username' => $config->database->username,
        'password' => $config->database->password,
        'dbname'   => $config->database->dbname,
        'charset'  => $config->database->charset,
    ];
    return new MysqlAdapter($params);
});

$di->setShared('modelsMetadata', fn () => new MetaDataMemory());

// Session-backed (not Direct): every controller flashes a message and then
// redirects, so the message must survive into the *next* request to be
// rendered — Direct only echoes within the same response.
$di->setShared('flash', function () {
    $flash = new Flash(null, $this->get('session'));
    $flash->setCssClasses([
        'error'   => "mb-4 flex items-center gap-2 rounded-lg border-l-4 border-red-500 bg-red-50 px-4 py-3 text-sm text-red-800 before:content-['✕']",
        'success' => "mb-4 flex items-center gap-2 rounded-lg border-l-4 border-green-500 bg-green-50 px-4 py-3 text-sm text-green-800 before:content-['✓']",
        'notice'  => "mb-4 flex items-center gap-2 rounded-lg border-l-4 border-blue-500 bg-blue-50 px-4 py-3 text-sm text-blue-800 before:content-['ℹ']",
        'warning' => "mb-4 flex items-center gap-2 rounded-lg border-l-4 border-yellow-500 bg-yellow-50 px-4 py-3 text-sm text-yellow-800 before:content-['⚠']",
    ]);
    return $flash;
});

$di->setShared('cache', function () {
    $serializerFactory = new SerializerFactory();
    $adapter = new CacheStream($serializerFactory, [
        'storageDir' => sys_get_temp_dir() . '/ingress-cache/',
    ]);
    return new Cache($adapter);
});

$di->setShared('view', function () use ($config) {
    $view = new View();
    $view->setViewsDir($config->application->viewsDir);

    $voltCompiledPath = sys_get_temp_dir() . '/ingress-volt/';
    if (!is_dir($voltCompiledPath)) {
        mkdir($voltCompiledPath, 0755, true);
    }

    $view->registerEngines([
        '.volt' => function ($view) use ($voltCompiledPath) {
            $volt = new \Phalcon\Mvc\View\Engine\Volt($view, $this);
            $volt->setOptions([
                'path'      => $voltCompiledPath,
                'separator' => '_',
                // DEV-ONLY: forces recompilation on every request so bind-mounted
                // template edits (see docker-compose.yml) show up immediately.
                'always'    => getenv('APP_ENV') === 'local',
            ]);
            return $volt;
        },
        '.phtml' => \Phalcon\Mvc\View\Engine\Php::class,
    ]);
    return $view;
});

// Events-manager-backed dispatcher: this is the intentional deviation from
// the sibling hr-advws app, where auth was checked ad hoc per-controller
// instead of via a wired dispatcher middleware.
$di->setShared('dispatcher', function () {
    $eventsManager = new EventsManager();
    $eventsManager->attach('dispatch:beforeExecuteRoute', new AuthMiddleware());

    $dispatcher = new Dispatcher();
    $dispatcher->setDefaultNamespace('App\\Controllers');
    $dispatcher->setEventsManager($eventsManager);
    return $dispatcher;
});

// --- Kubernetes -------------------------------------------------------

// SERVER_CONFIG points at a standard kubeconfig file (in-cluster: mounted
// from a Secret; local dev: a developer's own kubeconfig). See
// App\Services\KubeconfigLoader and resource/mdSource/Kubernetes-Integration.md.
$di->setShared('kubernetesClient', function () use ($config) {
    $kubeconfig = KubeconfigLoader::load($config->kubernetes->server_config_path);

    return new KubernetesClient(
        $kubeconfig['host'],
        $kubeconfig['port'],
        $kubeconfig['token'],
        $kubeconfig['ca_path']
    );
});

// DEV-ONLY: when APP_ENV=local and no SERVER_CONFIG is set, fall back to
// MockKubernetesService so the create-ingress flow can be previewed without
// a reachable cluster. Never takes effect once SERVER_CONFIG is configured.
$di->setShared('kubernetesService', function () use ($config) {
    if (getenv('APP_ENV') === 'local' && $config->kubernetes->server_config_path === '') {
        return new MockKubernetesService();
    }

    return new KubernetesService($this->get('kubernetesClient'));
});

// --- Auth ---------------------------------------------------------------

$di->setShared('googleAuthService', function () use ($config) {
    return new GoogleAuthService(
        $config->google->client_id,
        $config->google->client_secret,
        $config->google->redirect_uri,
        $config->google->hosted_domain
    );
});

$di->setShared('authService', function () {
    return new AuthService($this->get('session'));
});

$di->setShared('auditLogService', function () {
    return new AuditLogService();
});

$di->setShared('settingsService', function () {
    return new SettingsService($this->get('auditLogService'));
});

$di->setShared('ingressRequestService', function () use ($config) {
    return new IngressRequestService($config->kubernetes->node_ip, $this->get('auditLogService'));
});

return $di;
