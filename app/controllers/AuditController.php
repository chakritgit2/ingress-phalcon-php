<?php

namespace App\Controllers;

use App\Models\AuditLog;
use App\Models\IngressRequests;
use App\Models\K8sCommands;

class AuditController extends ControllerBase
{
    private const PAGE_SIZE = 50;

    // Safety bound on securityExportAction() — see IngressController's
    // matching EXPORT_ROW_LIMIT for the same reasoning.
    private const EXPORT_ROW_LIMIT = 5000;

    public function indexAction(): void
    {
        $page = max(1, (int) $this->request->getQuery('page', 'int', 1));

        $rows = IngressRequests::find([
            'order' => 'created_at DESC',
            'limit' => self::PAGE_SIZE,
            'offset' => (self::PAGE_SIZE) * ($page - 1),
        ]);

        $this->view->setVar('rows', $rows);
        $this->view->setVar('page', $page);
    }

    /**
     * Events that aren't tied to any ingress_requests row (login attempts,
     * bot toggles) never show up in indexAction/showAction above — those
     * only ever query by ingress_request_id. This is the one place to see
     * them without querying audit_log directly.
     */
    public function securityAction(): void
    {
        $page = max(1, (int) $this->request->getQuery('page', 'int', 1));

        $events = AuditLog::find([
            'conditions' => "event_type IN ('login', 'login_rejected', 'bot_enabled', 'bot_disabled', 'user_role_changed', 'user_activated', 'user_deactivated', 'user_password_reset', 'user_email_changed')",
            'order' => 'created_at DESC',
            'limit' => self::PAGE_SIZE,
            'offset' => self::PAGE_SIZE * ($page - 1),
        ]);

        $this->view->setVar('events', $events);
        $this->view->setVar('page', $page);
    }

    public function securityExportAction()
    {
        $events = AuditLog::find([
            'conditions' => "event_type IN ('login', 'login_rejected', 'bot_enabled', 'bot_disabled', 'user_role_changed', 'user_activated', 'user_deactivated', 'user_password_reset', 'user_email_changed')",
            'order' => 'created_at DESC',
            'limit' => self::EXPORT_ROW_LIMIT,
        ]);

        $csvRows = [];
        foreach ($events as $event) {
            $csvRows[] = [$event->created_at, $event->event_type, $event->actor_label, $event->detail];
        }

        return $this->csvResponse(
            'security-log-' . date('Ymd-His') . '.csv',
            ['Timestamp', 'Event Type', 'Actor', 'Detail'],
            $csvRows
        );
    }

    public function showAction($id)
    {
        $row = IngressRequests::findFirst((int) $id);

        if ($row === null) {
            $this->flash->error('ไม่พบรายการ');
            return $this->response->redirect('/audit');
        }

        $events = AuditLog::find([
            'conditions' => 'ingress_request_id = :id:',
            'bind' => ['id' => $row->id],
            'order' => 'created_at ASC',
        ]);

        $commands = K8sCommands::find([
            'conditions' => 'ingress_request_id = :id:',
            'bind' => ['id' => $row->id],
            'order' => 'created_at ASC',
        ]);

        $this->view->setVar('row', $row);
        $this->view->setVar('events', $events);
        $this->view->setVar('commands', $commands);
    }
}
