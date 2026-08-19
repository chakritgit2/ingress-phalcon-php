<?php

use Phalcon\Mvc\Application;

error_reporting(E_ALL);
//show errors
ini_set('display_errors', '1');
date_default_timezone_set('Asia/Bangkok');
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
    $errorId = substr(bin2hex(random_bytes(4)), 0, 8);
    error_log(sprintf(
        "[%s] %s %s: %s\n%s",
        $errorId,
        $_SERVER['REQUEST_METHOD'] ?? '-',
        $_SERVER['REQUEST_URI'] ?? '-',
        $e->getMessage(),
        $e->getTraceAsString()
    ));
    http_response_code(500);
    echo "Internal Server Error (ref: {$errorId})";
}
