<?php

use Phalcon\Mvc\Application;

error_reporting(E_ALL);

require dirname(__DIR__) . '/vendor/autoload.php';

try {
    /** @var \Phalcon\Config\Config $config */
    $config = require dirname(__DIR__) . '/app/config/config.php';

    /** @var \Phalcon\Di\FactoryDefault $di */
    $di = require dirname(__DIR__) . '/app/config/services.php';

    require dirname(__DIR__) . '/app/config/loader.php';

    $di->setShared('router', fn () => require dirname(__DIR__) . '/app/config/router.php');

    $application = new Application($di);

    echo $application->handle($_SERVER['REQUEST_URI'])->getContent();
} catch (\Throwable $e) {
    error_log($e->getMessage() . "\n" . $e->getTraceAsString());
    http_response_code(500);
    echo 'Internal Server Error';
}
