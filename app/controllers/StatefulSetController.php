<?php

namespace App\Controllers;

use App\Models\StatefulSetCommands;
use App\Models\StatefulSetRequests;
use App\Services\AuditLogService;

class StatefulSetController extends ControllerBase
{
    // Same reasoning as IngressController::STUCK_THRESHOLD_MINUTES.
    private const STUCK_THRESHOLD_MINUTES = 5;

    private const EXPORT_ROW_LIMIT = 5000;

    private const PAGE_SIZE = 20;

    public function indexAction(): void
    {
        $filters = $this->readFilters();
        [$conditions, $bind] = $this->filterConditions($filters);

        $page = max(1, (int) $this->request->getQuery('page', 'int', 1));

        $findParams = [
            'order' => 'created_at DESC',
            'limit' => self::PAGE_SIZE,
            'offset' => self::PAGE_SIZE * ($page - 1),
            'with' => ['creator'],
        ];
        if ($conditions !== []) {
            $findParams['conditions'] = implode(' AND ', $conditions);
            $findParams['bind'] = $bind;
        }

        $rows = StatefulSetRequests::find($findParams);

        $countParams = $conditions !== [] ? ['conditions' => implode(' AND ', $conditions), 'bind' => $bind] : [];
        $totalItems = (int) StatefulSetRequests::count($countParams);
        $totalPages = max(1, (int) ceil($totalItems / self::PAGE_SIZE));

        $editableIds = [];
        foreach ($rows as $row) {
            if ($this->statefulSetRequestService->isEditable($row)) {
                $editableIds[$row->id] = true;
            }
        }

        $botEnabled = $this->settingsService->isBotEnabled();
        $stuckCommandCount = 0;

        if ($botEnabled) {
            $stuckCommandCount = (int) StatefulSetCommands::count([
                'conditions' => "status = 'pending' AND created_at <= :threshold:",
                'bind' => ['threshold' => date('Y-m-d H:i:s', time() - self::STUCK_THRESHOLD_MINUTES * 60)],
            ]);
        }

        $this->view->setVar('rows', $rows);
        $this->view->setVar('page', $page);
        $this->view->setVar('totalItems', $totalItems);
        $this->view->setVar('totalPages', $totalPages);
        $this->view->setVar('pageNumbers', $this->paginationPages($page, $totalPages));
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

        $findParams = ['order' => 'created_at DESC', 'limit' => self::EXPORT_ROW_LIMIT, 'with' => ['creator']];
        if ($conditions !== []) {
            $findParams['conditions'] = implode(' AND ', $conditions);
            $findParams['bind'] = $bind;
        }

        $rows = StatefulSetRequests::find($findParams);

        $csvRows = [];
        foreach ($rows as $row) {
            $csvRows[] = [
                $row->id,
                $row->developer_name,
                $row->creator ? $row->creator->email : '',
                $row->statefulset_name,
                $row->namespace,
                $row->address(),
                $row->note,
                $row->created_at,
                $row->expires_at,
                $row->status,
            ];
        }

        return $this->csvResponse(
            'statefulset-export-' . date('Ymd-His') . '.csv',
            ['ID', 'Developer', 'Created By', 'StatefulSet', 'Namespace', 'Address', 'Note', 'Created At', 'Expires At', 'Status'],
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
            return $this->response->redirect('/statefulsets');
        }

        if ($this->settingsService->isEnvKillSwitchActive()) {
            $this->flash->error('บอทถูกบังคับปิดโดยตัวแปรระบบ (BOT_ENABLED) ไม่สามารถเปิดผ่านหน้านี้ได้');
            return $this->response->redirect('/statefulsets');
        }

        $newState = !$this->settingsService->isBotEnabled();
        $this->settingsService->setBotEnabled(
            $newState,
            AuditLogService::actorLabelFor($this->currentUser()),
            $this->currentUser()->id
        );

        $this->flash->success($newState ? 'เปิดบอทแล้ว' : 'ปิดบอทแล้ว คำขอใหม่จะค้างจนกว่าจะเปิดอีกครั้ง');
        return $this->response->redirect('/statefulsets');
    }

    public function createAction()
    {
        try {
            $namespaces = $this->kubernetesService->listNamespaces();
        } catch (\Throwable $e) {
            $this->flash->error('ดึงข้อมูล namespace จาก Kubernetes ไม่สำเร็จ: ' . $e->getMessage());
            return $this->response->redirect('/statefulsets');
        }

        $this->view->setVar('namespaces', $namespaces);
        $this->view->setVar('developerNameDefault', $this->currentUser()->name);
    }

    public function statefulsetsApiAction()
    {
        $namespace = (string) $this->request->getQuery('namespace', 'string', '');

        $this->response->setContentType('application/json');

        try {
            $statefulSets = $namespace === ''
                ? $this->kubernetesService->listAllStatefulSets()
                : $this->kubernetesService->listStatefulSets($namespace);
            return $this->response->setJsonContent(['statefulsets' => $statefulSets]);
        } catch (\Throwable $e) {
            $this->response->setStatusCode(400);
            return $this->response->setJsonContent(['error' => $e->getMessage()]);
        }
    }

    public function storeAction()
    {
        if (!$this->request->isPost() || !$this->security->checkToken()) {
            $this->flash->error('คำขอไม่ถูกต้อง (CSRF)');
            return $this->response->redirect('/statefulsets/create');
        }

        $data = [
            'developer_name' => $this->request->getPost('developer_name', 'string'),
            'namespace' => $this->request->getPost('namespace', 'string'),
            'statefulset_name' => $this->request->getPost('statefulset_name', 'string'),
            'target_port' => $this->request->getPost('target_port', 'int', 80),
            'schedule_end_minutes' => $this->request->getPost('schedule_end_minutes', 'int'),
            'note' => $this->request->getPost('note', 'string', ''),
        ];

        try {
            $this->statefulSetRequestService->create($data, $this->currentUser());
            $this->flash->success('ส่งคำขอแล้ว กำลังดำเนินการสร้าง Service (ดูสถานะได้ที่รายการด้านล่าง)');
        } catch (\Throwable $e) {
            $this->flash->error('สร้างไม่สำเร็จ: ' . $e->getMessage());
        }

        return $this->response->redirect('/statefulsets');
    }

    public function editAction($id): void
    {
        $row = StatefulSetRequests::findFirst((int) $id);

        if ($row === null || !$this->statefulSetRequestService->isEditable($row)) {
            $this->flash->error('ไม่พบรายการ หรือรายการนี้ไม่สามารถแก้ไขได้แล้ว');
            $this->response->redirect('/statefulsets');
            return;
        }

        try {
            $namespaces = $this->kubernetesService->listNamespaces();
        } catch (\Throwable $e) {
            $this->flash->error('ดึงข้อมูล namespace จาก Kubernetes ไม่สำเร็จ: ' . $e->getMessage());
            $this->response->redirect('/statefulsets');
            return;
        }

        $this->view->setVar('row', $row);
        $this->view->setVar('namespaces', $namespaces);
    }

    public function updateAction($id)
    {
        if (!$this->request->isPost() || !$this->security->checkToken()) {
            $this->flash->error('คำขอไม่ถูกต้อง (CSRF)');
            return $this->response->redirect('/statefulsets');
        }

        $row = StatefulSetRequests::findFirst((int) $id);

        if ($row === null || !$this->statefulSetRequestService->isEditable($row)) {
            $this->flash->error('ไม่พบรายการ หรือรายการนี้ไม่สามารถแก้ไขได้แล้ว');
            return $this->response->redirect('/statefulsets');
        }

        $data = [
            'developer_name' => $this->request->getPost('developer_name', 'string'),
            'namespace' => $this->request->getPost('namespace', 'string'),
            'statefulset_name' => $this->request->getPost('statefulset_name', 'string'),
            'target_port' => $this->request->getPost('target_port', 'int', 80),
            'schedule_end_minutes' => $this->request->getPost('schedule_end_minutes', 'int'),
            'note' => $this->request->getPost('note', 'string', ''),
        ];

        try {
            $this->statefulSetRequestService->update($row, $data, $this->currentUser());
            $this->flash->success('บันทึกการแก้ไขแล้ว');
        } catch (\Throwable $e) {
            $this->flash->error('แก้ไขไม่สำเร็จ: ' . $e->getMessage());
        }

        return $this->response->redirect('/statefulsets');
    }

    public function deleteAction($id)
    {
        if (!$this->request->isPost() || !$this->security->checkToken()) {
            $this->flash->error('คำขอไม่ถูกต้อง (CSRF)');
            return $this->response->redirect('/statefulsets');
        }

        $row = StatefulSetRequests::findFirst((int) $id);

        if ($row === null || $row->status !== 'active') {
            $this->flash->error('ไม่พบรายการ หรือรายการนี้ถูกลบไปแล้ว');
            return $this->response->redirect('/statefulsets');
        }

        try {
            $this->statefulSetRequestService->deleteManually($row, $this->currentUser());
            $this->flash->success('ส่งคำขอลบแล้ว กำลังดำเนินการ');
        } catch (\Throwable $e) {
            $this->flash->error('ลบไม่สำเร็จ: ' . $e->getMessage());
        }

        return $this->response->redirect('/statefulsets');
    }

    public function renewAction($id)
    {
        if (!$this->request->isPost() || !$this->security->checkToken()) {
            $this->flash->error('คำขอไม่ถูกต้อง (CSRF)');
            return $this->response->redirect('/statefulsets');
        }

        $row = StatefulSetRequests::findFirst((int) $id);

        if ($row === null || $row->status !== 'active') {
            $this->flash->error('ไม่พบรายการ หรือรายการนี้ไม่ได้อยู่ในสถานะ active');
            return $this->response->redirect('/statefulsets');
        }

        $additionalMinutes = $this->request->getPost('additional_minutes', 'int', 0);

        try {
            $this->statefulSetRequestService->renew($row, $additionalMinutes, $this->currentUser());
            $this->flash->success("ต่ออายุแล้ว หมดอายุใหม่: {$row->expires_at}");
        } catch (\Throwable $e) {
            $this->flash->error('ต่ออายุไม่สำเร็จ: ' . $e->getMessage());
        }

        return $this->response->redirect('/statefulsets');
    }

    public function retryAction($id)
    {
        if (!$this->request->isPost() || !$this->security->checkToken()) {
            $this->flash->error('คำขอไม่ถูกต้อง (CSRF)');
            return $this->response->redirect('/statefulsets');
        }

        $row = StatefulSetRequests::findFirst((int) $id);

        if ($row === null || $row->status !== 'failed') {
            $this->flash->error('ไม่พบรายการ หรือรายการนี้ไม่ได้อยู่ในสถานะล้มเหลว');
            return $this->response->redirect('/statefulsets');
        }

        try {
            $this->statefulSetRequestService->retry($row, $this->currentUser());
            $this->flash->success('ส่งคำขอลองใหม่แล้ว กำลังดำเนินการ');
        } catch (\Throwable $e) {
            $this->flash->error('ลองใหม่ไม่สำเร็จ: ' . $e->getMessage());
        }

        return $this->response->redirect('/statefulsets');
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

        $rows = StatefulSetRequests::find([
            'conditions' => 'id IN ({ids:array}) AND status = :status:',
            'bind' => ['ids' => $ids, 'status' => 'active'],
        ]);

        $success = 0;
        $failed = 0;

        foreach ($rows as $row) {
            try {
                $this->statefulSetRequestService->deleteManually($row, $this->currentUser());
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

        $rows = StatefulSetRequests::find([
            'conditions' => 'id IN ({ids:array}) AND status = :status:',
            'bind' => ['ids' => $ids, 'status' => 'failed'],
        ]);

        $success = 0;
        $failed = 0;

        foreach ($rows as $row) {
            try {
                $this->statefulSetRequestService->retry($row, $this->currentUser());
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
        return $this->response->redirect($referer !== '' ? $referer : '/statefulsets');
    }
}
