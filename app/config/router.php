<?php

use Phalcon\Mvc\Router;

$router = new Router(false);
$router->removeExtraSlashes(true);
$router->setDefaultNamespace('App\\Controllers');

$router->add('/', ['controller' => 'ingress', 'action' => 'index']);

$router->add('/login', ['controller' => 'login', 'action' => 'index'])->via(['GET']);
$router->add('/login', ['controller' => 'login', 'action' => 'login'])->via(['POST']);
$router->add('/login/google', ['controller' => 'login', 'action' => 'google'])->via(['GET']);
$router->add('/login/google/callback', ['controller' => 'login', 'action' => 'googleCallback'])->via(['GET']);
$router->add('/login/mock', ['controller' => 'login', 'action' => 'mockLogin'])->via(['GET']); // dev-only, see LoginController::mockLoginAction
$router->add('/logout', ['controller' => 'login', 'action' => 'logout'])->via(['POST']);

$router->add('/ingress', ['controller' => 'ingress', 'action' => 'index'])->via(['GET']);
$router->add('/ingress/create', ['controller' => 'ingress', 'action' => 'create'])->via(['GET']);
$router->add('/ingress/api/deployments', ['controller' => 'ingress', 'action' => 'deploymentsApi'])->via(['GET']);
$router->add('/ingress/api/secrets', ['controller' => 'ingress', 'action' => 'secretsApi'])->via(['GET']);
$router->add('/ingress/store', ['controller' => 'ingress', 'action' => 'store'])->via(['POST']);
$router->add('/ingress/{id:[0-9]+}/edit', ['controller' => 'ingress', 'action' => 'edit'])->via(['GET']);
$router->add('/ingress/{id:[0-9]+}/update', ['controller' => 'ingress', 'action' => 'update'])->via(['POST']);
$router->add('/ingress/{id:[0-9]+}/delete', ['controller' => 'ingress', 'action' => 'delete'])->via(['POST']);
$router->add('/ingress/{id:[0-9]+}/retry', ['controller' => 'ingress', 'action' => 'retry'])->via(['POST']);
$router->add('/ingress/toggle-bot', ['controller' => 'ingress', 'action' => 'toggleBot'])->via(['POST']);
$router->add('/ingress/export', ['controller' => 'ingress', 'action' => 'export'])->via(['GET']);
$router->add('/ingress/bulk-delete', ['controller' => 'ingress', 'action' => 'bulkDelete'])->via(['POST']);
$router->add('/ingress/bulk-retry', ['controller' => 'ingress', 'action' => 'bulkRetry'])->via(['POST']);

$router->add('/users', ['controller' => 'users', 'action' => 'index'])->via(['GET']);
$router->add('/users/{id:[0-9]+}/edit', ['controller' => 'users', 'action' => 'edit'])->via(['GET']);
$router->add('/users/{id:[0-9]+}/email', ['controller' => 'users', 'action' => 'updateEmail'])->via(['POST']);
$router->add('/users/{id:[0-9]+}/role', ['controller' => 'users', 'action' => 'updateRole'])->via(['POST']);
$router->add('/users/{id:[0-9]+}/toggle-active', ['controller' => 'users', 'action' => 'toggleActive'])->via(['POST']);
$router->add('/users/{id:[0-9]+}/reset-password', ['controller' => 'users', 'action' => 'resetPassword'])->via(['POST']);

$router->add('/audit', ['controller' => 'audit', 'action' => 'index'])->via(['GET']);
$router->add('/audit/security', ['controller' => 'audit', 'action' => 'security'])->via(['GET']);
$router->add('/audit/security/export', ['controller' => 'audit', 'action' => 'securityExport'])->via(['GET']);
$router->add('/audit/{id:[0-9]+}', ['controller' => 'audit', 'action' => 'show'])->via(['GET']);

$router->notFound(['controller' => 'error', 'action' => 'notFound']);

return $router;
