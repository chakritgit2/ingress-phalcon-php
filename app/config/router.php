<?php

use Phalcon\Mvc\Router;

$router = new Router(false);
$router->removeExtraSlashes(true);
$router->setDefaultNamespace('App\\Controllers');

$router->add('/', ['controller' => 'ingress', 'action' => 'index']);

$router->add('/login', ['controller' => 'login', 'action' => 'index'])->via(['GET']);
$router->add('/login/google', ['controller' => 'login', 'action' => 'google'])->via(['GET']);
$router->add('/login/google/callback', ['controller' => 'login', 'action' => 'googleCallback'])->via(['GET']);
$router->add('/login/mock', ['controller' => 'login', 'action' => 'mockLogin'])->via(['GET']); // dev-only, see LoginController::mockLoginAction
$router->add('/logout', ['controller' => 'login', 'action' => 'logout'])->via(['POST']);

$router->add('/ingress', ['controller' => 'ingress', 'action' => 'index'])->via(['GET']);
$router->add('/ingress/create', ['controller' => 'ingress', 'action' => 'create'])->via(['GET']);
$router->add('/ingress/api/deployments', ['controller' => 'ingress', 'action' => 'deploymentsApi'])->via(['GET']);
$router->add('/ingress/store', ['controller' => 'ingress', 'action' => 'store'])->via(['POST']);
$router->add('/ingress/{id:[0-9]+}/delete', ['controller' => 'ingress', 'action' => 'delete'])->via(['POST']);
$router->add('/ingress/{id:[0-9]+}/retry', ['controller' => 'ingress', 'action' => 'retry'])->via(['POST']);

$router->add('/audit', ['controller' => 'audit', 'action' => 'index'])->via(['GET']);
$router->add('/audit/{id:[0-9]+}', ['controller' => 'audit', 'action' => 'show'])->via(['GET']);

$router->notFound(['controller' => 'error', 'action' => 'notFound']);

return $router;
