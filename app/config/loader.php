<?php

use Phalcon\Autoload\Loader;

$loader = new Loader();

$loader->setNamespaces([
    'App\\Controllers' => $config->application->controllersDir,
    'App\\Middleware'  => $config->application->middlewareDir,
    'App\\Models'      => $config->application->modelsDir,
    'App\\Services'    => $config->application->servicesDir,
    'App\\Tasks'       => $config->application->tasksDir,
]);

$loader->register();
