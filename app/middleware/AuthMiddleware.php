<?php

namespace App\Middleware;

use Phalcon\Di\Injectable;
use Phalcon\Events\Event;
use Phalcon\Mvc\Dispatcher;

/**
 * Wired onto the dispatcher's events manager in services.php. This replaces
 * the ad hoc per-controller auth checks used in the sibling hr-advws app.
 */
class AuthMiddleware extends Injectable
{
    private const PUBLIC_CONTROLLERS = ['login', 'error'];

    /**
     * Only these mutating ingress actions require role='devops'. Everything
     * else (viewing /ingress, /audit) is open to any active logged-in user —
     * matches the README's "viewer = no create/delete rights" intent.
     *
     * Forwarding a devops violation back to login/index would bounce right
     * back here (login redirects logged-in users to /ingress), so violations
     * forward to ingress/index instead.
     */
    private const DEVOPS_ONLY_ACTIONS = [
        'ingress' => ['create', 'store', 'delete', 'deploymentsApi', 'toggleBot'],
    ];

    public function beforeExecuteRoute(Event $event, Dispatcher $dispatcher): bool
    {
        $controller = $dispatcher->getControllerName();

        if (in_array($controller, self::PUBLIC_CONTROLLERS, true)) {
            return true;
        }

        $authService = $this->getDI()->get('authService');
        $user = $authService->currentUser();

        if ($user === null || !$user->is_active) {
            $dispatcher->forward([
                'controller' => 'login',
                'action' => 'index',
            ]);
            return false;
        }

        $action = $dispatcher->getActionName();
        $devopsOnlyActions = self::DEVOPS_ONLY_ACTIONS[$controller] ?? [];

        if (in_array($action, $devopsOnlyActions, true) && !$user->isDevops()) {
            $this->getDI()->get('flash')->error('บัญชีของคุณไม่มีสิทธิ์ devops สำหรับหน้านี้');
            $dispatcher->forward([
                'controller' => 'ingress',
                'action' => 'index',
            ]);
            return false;
        }

        return true;
    }
}
