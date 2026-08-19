<?php

namespace App\Controllers;

use App\Models\IngressRequests;
use App\Models\K8sCommands;
use App\Services\AuditLogService;

class IngressController extends ControllerBase
{
    // 5x the 1-minute cron cadence (see k8s/cronjob.yaml) — long enough to
    // not false-positive on a slightly slow tick, short enough to surface a
    // dead bot pod quickly.
    private const STUCK_THRESHOLD_MINUTES = 5;

    // Safety bound on exportAction() — an internal tool never realistically
    // has more filtered rows than this; it's just a backstop against an
    // unbounded query, not a real usage limit.
    private const EXPORT_ROW_LIMIT = 5000;

    public function indexAction(): void
    {
        $filters = $this->readFilters();
        [$conditions, $bind] = $this->filterConditions($filters);

        $findParams = ['order' => 'created_at DESC', 'limit' => 100];
        if ($conditions !== []) {
            $findParams['conditions'] = implode(' AND ', $conditions);
            $findParams['bind'] = $bind;
        }

        $rows = IngressRequests::find($findParams);

        $editableIds = [];
        foreach ($rows as $row) {
            if ($this->ingressRequestService->isEditable($row)) {
                $editableIds[$row->id] = true;
            }
        }

        $botEnabled = $this->settingsService->isBotEnabled();
        $stuckCommandCount = 0;

        if ($botEnabled) {
            $stuckCommandCount = (int) K8sCommands::count([
                'conditions' => "status = 'pending' AND created_at <= :threshold:",
                'bind' => ['threshold' => date('Y-m-d H:i:s', time() - self::STUCK_THRESHOLD_MINUTES * 60)],
            ]);
        }

        $this->view->setVar('rows', $rows);
        $this->view->setVar('editableIds', $editableIds);
        $this->view->setVar('botEnabled', $botEnabled);
        $this->view->setVar('botKillSwitchActive', $this->settingsService->isEnvKillSwitchActive());
        $this->view->setVar('stuckCommandCount', $stuckCommandCount);
        $this->view->setVar('filterNamespace', $filters['namespace']);
        $this->view->setVar('filterDeveloperName', $filters['developer_name']);
        $this->view->setVar('filterStatus', $filters['status']);
    }

    public function exportAction()
    {
        $filters = $this->readFilters();
        [$conditions, $bind] = $this->filterConditions($filters);

        $findParams = ['order' => 'created_at DESC', 'limit' => self::EXPORT_ROW_LIMIT];
        if ($conditions !== []) {
            $findParams['conditions'] = implode(' AND ', $conditions);
            $findParams['bind'] = $bind;
        }

        $rows = IngressRequests::find($findParams);

        $csvRows = [];
        foreach ($rows as $row) {
            $csvRows[] = [
                $row->id,
                $row->developer_name,
                $row->deployment_name,
                $row->namespace,
                $row->request_type,
                $row->address(),
                $row->note,
                $row->created_at,
                $row->expires_at,
                $row->status,
            ];
        }

        return $this->csvResponse(
            'ingress-export-' . date('Ymd-His') . '.csv',
            ['ID', 'Developer', 'Deployment', 'Namespace', 'Type', 'Address', 'Note', 'Created At', 'Expires At', 'Status'],
            $csvRows
        );
    }

    private function readFilters(): array
    {
        return [
            'namespace' => trim((string) $this->request->getQuery('namespace', 'string', '')),
            'developer_name' => trim((string) $this->request->getQuery('developer_name', 'string', '')),
            'status' => trim((string) $this->request->getQuery('status', 'string', '')),
        ];
    }

    /**
     * @return array{0: string[], 1: array<string, string>}
     */
    private function filterConditions(array $filters): array
    {
        $conditions = [];
        $bind = [];

        if ($filters['namespace'] !== '') {
            $conditions[] = 'namespace LIKE :namespace:';
            $bind['namespace'] = '%' . $filters['namespace'] . '%';
        }
        if ($filters['developer_name'] !== '') {
            $conditions[] = 'developer_name LIKE :developer_name:';
            $bind['developer_name'] = '%' . $filters['developer_name'] . '%';
        }
        if ($filters['status'] !== '') {
            $conditions[] = 'status = :status:';
            $bind['status'] = $filters['status'];
        }

        return [$conditions, $bind];
    }

    public function toggleBotAction()
    {
        if (!$this->request->isPost() || !$this->security->checkToken()) {
            $this->flash->error('คำขอไม่ถูกต้อง (CSRF)');
            return $this->response->redirect('/ingress');
        }

        if ($this->settingsService->isEnvKillSwitchActive()) {
            $this->flash->error('บอทถูกบังคับปิดโดยตัวแปรระบบ (BOT_ENABLED) ไม่สามารถเปิดผ่านหน้านี้ได้');
            return $this->response->redirect('/ingress');
        }

        $newState = !$this->settingsService->isBotEnabled();
        $this->settingsService->setBotEnabled(
            $newState,
            AuditLogService::actorLabelFor($this->currentUser()),
            $this->currentUser()->id
        );

        $this->flash->success($newState ? 'เปิดบอทแล้ว' : 'ปิดบอทแล้ว คำขอใหม่จะค้างจนกว่าจะเปิดอีกครั้ง');
        return $this->response->redirect('/ingress');
    }

    public function createAction(): void
    {
        $this->view->setVar('namespaces', $this->kubernetesService->listNamespaces());
        $this->view->setVar('developerNameDefault', $this->currentUser()->name);
    }

    public function deploymentsApiAction()
    {
        $namespace = (string) $this->request->getQuery('namespace', 'string', '');

        $this->response->setContentType('application/json');

        try {
            $deployments = $this->kubernetesService->listDeployments($namespace);
            return $this->response->setJsonContent(['deployments' => $deployments]);
        } catch (\Throwable $e) {
            $this->response->setStatusCode(400);
            return $this->response->setJsonContent(['error' => $e->getMessage()]);
        }
    }

    public function secretsApiAction()
    {
        $namespace = (string) $this->request->getQuery('namespace', 'string', '');

        $this->response->setContentType('application/json');

        try {
            $secrets = $this->kubernetesService->listSecrets($namespace);
            return $this->response->setJsonContent(['secrets' => $secrets]);
        } catch (\Throwable $e) {
            $this->response->setStatusCode(400);
            return $this->response->setJsonContent(['error' => $e->getMessage()]);
        }
    }

    public function storeAction()
    {
        if (!$this->request->isPost() || !$this->security->checkToken()) {
            $this->flash->error('คำขอไม่ถูกต้อง (CSRF)');
            return $this->response->redirect('/ingress/create');
        }

        $data = [
            'developer_name' => $this->request->getPost('developer_name', 'string'),
            'namespace' => $this->request->getPost('namespace', 'string'),
            'deployment_name' => $this->request->getPost('deployment_name', 'string'),
            'request_type' => $this->request->getPost('request_type', 'string', 'nodeport'),
            'target_port' => $this->request->getPost('target_port', 'int', 80),
            'host' => $this->request->getPost('host', 'string', ''),
            'secret_name' => $this->request->getPost('secret_name', 'string', ''),
            'schedule_end_minutes' => $this->request->getPost('schedule_end_minutes', 'int'),
            'note' => $this->request->getPost('note', 'string', ''),
        ];

        try {
            $this->ingressRequestService->create($data, $this->currentUser());
            $this->flash->success('ส่งคำขอแล้ว กำลังดำเนินการสร้าง Ingress (ดูสถานะได้ที่รายการด้านล่าง)');
        } catch (\Throwable $e) {
            $this->flash->error('สร้างไม่สำเร็จ: ' . $e->getMessage());
        }

        return $this->response->redirect('/ingress');
    }

    public function editAction($id): void
    {
        $row = IngressRequests::findFirst((int) $id);

        if ($row === null || !$this->ingressRequestService->isEditable($row)) {
            $this->flash->error('ไม่พบรายการ หรือรายการนี้ไม่สามารถแก้ไขได้แล้ว');
            $this->response->redirect('/ingress');
            return;
        }

        $this->view->setVar('row', $row);
        $this->view->setVar('namespaces', $this->kubernetesService->listNamespaces());
    }

    public function updateAction($id)
    {
        if (!$this->request->isPost() || !$this->security->checkToken()) {
            $this->flash->error('คำขอไม่ถูกต้อง (CSRF)');
            return $this->response->redirect('/ingress');
        }

        $row = IngressRequests::findFirst((int) $id);

        if ($row === null || !$this->ingressRequestService->isEditable($row)) {
            $this->flash->error('ไม่พบรายการ หรือรายการนี้ไม่สามารถแก้ไขได้แล้ว');
            return $this->response->redirect('/ingress');
        }

        $data = [
            'developer_name' => $this->request->getPost('developer_name', 'string'),
            'namespace' => $this->request->getPost('namespace', 'string'),
            'deployment_name' => $this->request->getPost('deployment_name', 'string'),
            'request_type' => $this->request->getPost('request_type', 'string', 'nodeport'),
            'target_port' => $this->request->getPost('target_port', 'int', 80),
            'host' => $this->request->getPost('host', 'string', ''),
            'secret_name' => $this->request->getPost('secret_name', 'string', ''),
            'schedule_end_minutes' => $this->request->getPost('schedule_end_minutes', 'int'),
            'note' => $this->request->getPost('note', 'string', ''),
        ];

        try {
            $this->ingressRequestService->update($row, $data, $this->currentUser());
            $this->flash->success('บันทึกการแก้ไขแล้ว');
        } catch (\Throwable $e) {
            $this->flash->error('แก้ไขไม่สำเร็จ: ' . $e->getMessage());
        }

        return $this->response->redirect('/ingress');
    }

    public function deleteAction($id)
    {
        if (!$this->request->isPost() || !$this->security->checkToken()) {
            $this->flash->error('คำขอไม่ถูกต้อง (CSRF)');
            return $this->response->redirect('/ingress');
        }

        $row = IngressRequests::findFirst((int) $id);

        if ($row === null || $row->status !== 'active') {
            $this->flash->error('ไม่พบรายการ หรือรายการนี้ถูกลบไปแล้ว');
            return $this->response->redirect('/ingress');
        }

        try {
            $this->ingressRequestService->deleteManually($row, $this->currentUser());
            $this->flash->success('ส่งคำขอลบแล้ว กำลังดำเนินการ');
        } catch (\Throwable $e) {
            $this->flash->error('ลบไม่สำเร็จ: ' . $e->getMessage());
        }

        return $this->response->redirect('/ingress');
    }

    public function retryAction($id)
    {
        if (!$this->request->isPost() || !$this->security->checkToken()) {
            $this->flash->error('คำขอไม่ถูกต้อง (CSRF)');
            return $this->response->redirect('/ingress');
        }

        $row = IngressRequests::findFirst((int) $id);

        if ($row === null || $row->status !== 'failed') {
            $this->flash->error('ไม่พบรายการ หรือรายการนี้ไม่ได้อยู่ในสถานะล้มเหลว');
            return $this->response->redirect('/ingress');
        }

        try {
            $this->ingressRequestService->retry($row, $this->currentUser());
            $this->flash->success('ส่งคำขอลองใหม่แล้ว กำลังดำเนินการ');
        } catch (\Throwable $e) {
            $this->flash->error('ลองใหม่ไม่สำเร็จ: ' . $e->getMessage());
        }

        return $this->response->redirect('/ingress');
    }

    public function bulkDeleteAction()
    {
        if (!$this->request->isPost() || !$this->security->checkToken()) {
            $this->flash->error('คำขอไม่ถูกต้อง (CSRF)');
            return $this->redirectBack();
        }

        $ids = array_map('intval', (array) $this->request->getPost('ids', null, []));

        if ($ids === []) {
            $this->flash->error('กรุณาเลือกอย่างน้อย 1 รายการ');
            return $this->redirectBack();
        }

        // A mixed selection (active + failed) just silently skips whichever
        // rows aren't 'active' here — same as the single-row deleteAction()
        // guard, applied per-row instead of per-request.
        $rows = IngressRequests::find([
            'conditions' => 'id IN ({ids:array}) AND status = :status:',
            'bind' => ['ids' => $ids, 'status' => 'active'],
        ]);

        $success = 0;
        $failed = 0;

        foreach ($rows as $row) {
            try {
                $this->ingressRequestService->deleteManually($row, $this->currentUser());
                $success++;
            } catch (\Throwable $e) {
                $failed++;
            }
        }

        $skipped = count($ids) - $success - $failed;
        $this->flash->success($this->bulkResultMessage('ส่งคำขอลบแล้ว', $success, $failed, $skipped));

        return $this->redirectBack();
    }

    public function bulkRetryAction()
    {
        if (!$this->request->isPost() || !$this->security->checkToken()) {
            $this->flash->error('คำขอไม่ถูกต้อง (CSRF)');
            return $this->redirectBack();
        }

        $ids = array_map('intval', (array) $this->request->getPost('ids', null, []));

        if ($ids === []) {
            $this->flash->error('กรุณาเลือกอย่างน้อย 1 รายการ');
            return $this->redirectBack();
        }

        $rows = IngressRequests::find([
            'conditions' => 'id IN ({ids:array}) AND status = :status:',
            'bind' => ['ids' => $ids, 'status' => 'failed'],
        ]);

        $success = 0;
        $failed = 0;

        foreach ($rows as $row) {
            try {
                $this->ingressRequestService->retry($row, $this->currentUser());
                $success++;
            } catch (\Throwable $e) {
                $failed++;
            }
        }

        $skipped = count($ids) - $success - $failed;
        $this->flash->success($this->bulkResultMessage('ส่งคำขอลองใหม่แล้ว', $success, $failed, $skipped));

        return $this->redirectBack();
    }

    private function bulkResultMessage(string $verb, int $success, int $failed, int $skipped): string
    {
        $message = "{$verb} {$success} รายการ";
        if ($failed > 0) {
            $message .= ", ล้มเหลว {$failed} รายการ";
        }
        if ($skipped > 0) {
            $message .= ", ข้าม {$skipped} รายการ (สถานะไม่ตรงเงื่อนไข)";
        }

        return $message;
    }

    private function redirectBack()
    {
        $referer = $this->request->getHTTPReferer();
        return $this->response->redirect($referer !== '' ? $referer : '/ingress');
    }
}
