<?php

namespace App\Controllers;

use App\Models\AuditLog;
use App\Models\IngressRequests;
use App\Models\K8sCommands;

class AuditController extends ControllerBase
{
    private const PAGE_SIZE = 50;

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
