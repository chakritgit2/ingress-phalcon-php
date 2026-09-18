<?php

namespace App\Controllers;

use App\Models\IngressRequests;
use App\Models\K8sCommands;
use App\Models\Users;
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

        $rows = IngressRequests::find($findParams);

        $countParams = $conditions !== [] ? ['conditions' => implode(' AND ', $conditions), 'bind' => $bind] : [];
        $totalItems = (int) IngressRequests::count($countParams);
        $totalPages = max(1, (int) ceil($totalItems / self::PAGE_SIZE));

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
        $this->view->setVar('filterQ', $filters['q']);
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

        $rows = IngressRequests::find($findParams);

        $csvRows = [];
        foreach ($rows as $row) {
            $csvRows[] = [
                $row->id,
                $row->developer_name,
                $row->creator ? $row->creator->email : '',
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
            ['ID', 'Developer', 'Created By', 'Deployment', 'Namespace', 'Type', 'Address', 'Note', 'Created At', 'Expires At', 'Status'],
            $csvRows
        );
    }

    /**
     * One row per namespace + deployment that actually exists on the
     * cluster (not just ones with a past ingress request — a namespace/
     * deployment that never had one would otherwise be missing entirely),
     * annotated with how many ingress requests exist for it, if any.
     */
    public function groupsExportAction()
    {
        try {
            $namespaces = $this->kubernetesService->listNamespaces();
            $deployments = $this->kubernetesService->listAllDeployments();
        } catch (\Throwable $e) {
            $this->flash->error('ดึงข้อมูล namespace/deployment จาก Kubernetes ไม่สำเร็จ: ' . $e->getMessage());
            return $this->redirectBack();
        }

        $namespaceFilter = trim((string) $this->request->getQuery('namespace', 'string', ''));

        $requestCounts = [];
        foreach (IngressRequests::find(['columns' => 'namespace, deployment_name, status']) as $row) {
            $key = $row->namespace . '|' . $row->deployment_name;
            if (!isset($requestCounts[$key])) {
                $requestCounts[$key] = ['total' => 0, 'active' => 0];
            }
            $requestCounts[$key]['total']++;
            if ($row->status === 'active') {
                $requestCounts[$key]['active']++;
            }
        }

        $deploymentsByNamespace = [];
        foreach ($deployments as $deployment) {
            $deploymentsByNamespace[$deployment['namespace']][] = $deployment['name'];
        }

        $groups = [];
        foreach ($namespaces as $namespace) {
            if ($namespaceFilter !== '' && stripos($namespace, $namespaceFilter) === false) {
                continue;
            }

            $deploymentNames = $deploymentsByNamespace[$namespace] ?? [''];
            foreach ($deploymentNames as $deploymentName) {
                $key = $namespace . '|' . $deploymentName;
                $counts = $requestCounts[$key] ?? ['total' => 0, 'active' => 0];
                $groups[] = [$namespace, $deploymentName, $counts['total'], $counts['active']];
            }
        }

        return $this->csvResponse(
            'ingress-groups-' . date('Ymd-His') . '.csv',
            ['Namespace', 'Deployment', 'Total Requests', 'Active'],
            $groups
        );
    }

    private function readFilters(): array
    {
        return [
            'namespace' => trim((string) $this->request->getQuery('namespace', 'string', '')),
            'developer_name' => trim((string) $this->request->getQuery('developer_name', 'string', '')),
            'status' => trim((string) $this->request->getQuery('status', 'string', '')),
            'q' => trim((string) $this->request->getQuery('q', 'string', '')),
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
        if ($filters['q'] !== '') {
            // Free-text search across every field shown/relevant on this
            // page, plus the creator's email/name -- so one box covers what
            // would otherwise require picking the right one of the
            // namespace/developer filters above, or grepping the export CSV
            // by hand. Model::find()'s 'conditions' is PHQL, not raw SQL, so
            // a literal "IN (SELECT ...)" subquery against `users` isn't
            // valid here (PHQL only knows model-mapped columns/joins) --
            // matching users are looked up in a separate query first and
            // folded in as a plain IN (:id:, :id:, ...) list instead.
            $q = $filters['q'];
            $searchConditions = [
                'developer_name LIKE :q:',
                'namespace LIKE :q:',
                'deployment_name LIKE :q:',
                'host LIKE :q:',
                // Bracketed: Phalcon's PHQL lexer mis-tokenizes a bare `note`
                // column reference as the reserved word `NOT` followed by a
                // stray `E`, throwing "Column 'e' doesn't belong to any of
                // the selected models" -- [note] forces it to be read as a
                // plain identifier instead. Confirmed empirically; every
                // other column here parses fine unbracketed.
                '[note] LIKE :q:',
                'secret_name LIKE :q:',
                'service_name LIKE :q:',
                'ingress_name LIKE :q:',
                'node_ip LIKE :q:',
                'public_id LIKE :q:',
                'k8s_uid LIKE :q:',
            ];
            $bind['q'] = '%' . $q . '%';

            $matchingUsers = Users::find([
                'conditions' => 'email LIKE :q: OR name LIKE :q:',
                'bind' => ['q' => '%' . $q . '%'],
            ]);
            if ($matchingUsers->count() > 0) {
                $userIdPlaceholders = [];
                foreach ($matchingUsers as $i => $matchingUser) {
                    $key = "q_uid{$i}";
                    $userIdPlaceholders[] = ":{$key}:";
                    $bind[$key] = $matchingUser->id;
                }
                $searchConditions[] = 'created_by_user_id IN (' . implode(', ', $userIdPlaceholders) . ')';
            }

            if (ctype_digit($q)) {
                $searchConditions[] = 'id = :q_id:';
                $bind['q_id'] = (int) $q;
            }
            $conditions[] = '(' . implode(' OR ', $searchConditions) . ')';
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

    public function createAction()
    {
        try {
            $namespaces = $this->kubernetesService->listNamespaces();
        } catch (\Throwable $e) {
            $this->flash->error('ดึงข้อมูล namespace จาก Kubernetes ไม่สำเร็จ: ' . $e->getMessage());
            return $this->response->redirect('/ingress');
        }

        $this->view->setVar('namespaces', $namespaces);
        $this->view->setVar('developerNameDefault', $this->currentUser()->name);
    }

    public function cloneAction($id)
    {
        $row = IngressRequests::findFirst((int) $id);

        // No status restriction: cloning only reads $row to prefill a brand
        // new /ingress/store submission (see clone.volt) -- it never touches
        // the source row itself, so there's nothing unsafe about cloning an
        // active/pending/closed/failed request, not just an expired one.
        if ($row === null) {
            $this->flash->error('ไม่พบรายการ');
            return $this->response->redirect('/ingress');
        }

        try {
            $namespaces = $this->kubernetesService->listNamespaces();
        } catch (\Throwable $e) {
            $this->flash->error('ดึงข้อมูล namespace จาก Kubernetes ไม่สำเร็จ: ' . $e->getMessage());
            return $this->response->redirect('/ingress');
        }

        $this->view->setVar('row', $row);
        $this->view->setVar('namespaces', $namespaces);
    }

    public function deploymentsApiAction()
    {
        $namespace = (string) $this->request->getQuery('namespace', 'string', '');

        $this->response->setContentType('application/json');

        try {
            $deployments = $namespace === ''
                ? $this->kubernetesService->listAllDeployments()
                : $this->kubernetesService->listDeployments($namespace);
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
            'login_bypass' => $this->request->getPost('login_bypass', 'int', 0),
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

        // An `active` row already has a live Service/Ingress — see
        // IngressRequestService::updateActiveRow() — so it gets a separate,
        // much narrower edit form instead of the full create-style one.
        if ($row->status === 'active') {
            $this->view->pick('ingress/edit_active');
            $this->view->setVar('row', $row);
            return;
        }

        try {
            $namespaces = $this->kubernetesService->listNamespaces();
        } catch (\Throwable $e) {
            $this->flash->error('ดึงข้อมูล namespace จาก Kubernetes ไม่สำเร็จ: ' . $e->getMessage());
            $this->response->redirect('/ingress');
            return;
        }

        $this->view->setVar('row', $row);
        $this->view->setVar('namespaces', $namespaces);
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
            'login_bypass' => $this->request->getPost('login_bypass', 'int', 0),
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

    public function renewAction($id)
    {
        if (!$this->request->isPost() || !$this->security->checkToken()) {
            $this->flash->error('คำขอไม่ถูกต้อง (CSRF)');
            return $this->response->redirect('/ingress');
        }

        $row = IngressRequests::findFirst((int) $id);

        if ($row === null || $row->status !== 'active') {
            $this->flash->error('ไม่พบรายการ หรือรายการนี้ไม่ได้อยู่ในสถานะ active');
            return $this->response->redirect('/ingress');
        }

        $additionalMinutes = $this->request->getPost('additional_minutes', 'int', 0);

        try {
            $this->ingressRequestService->renew($row, $additionalMinutes, $this->currentUser());
            $this->flash->success("ต่ออายุแล้ว หมดอายุใหม่: {$row->expires_at}");
        } catch (\Throwable $e) {
            $this->flash->error('ต่ออายุไม่สำเร็จ: ' . $e->getMessage());
        }

        return $this->response->redirect('/ingress');
    }

    public function holdOpenAction($id)
    {
        if (!$this->request->isPost() || !$this->security->checkToken()) {
            $this->flash->error('คำขอไม่ถูกต้อง (CSRF)');
            return $this->response->redirect('/ingress');
        }

        $row = IngressRequests::findFirst((int) $id);

        if ($row === null || $row->status !== 'active') {
            $this->flash->error('ไม่พบรายการ หรือรายการนี้ไม่ได้อยู่ในสถานะ active');
            return $this->response->redirect('/ingress');
        }

        $until = $this->request->getPost('until', 'string', '');

        try {
            $this->ingressRequestService->holdOpen($row, $until, $this->currentUser());
            $this->flash->success("เปิดค้างคืนแล้ว จนถึง {$row->schedule_hold_open_until}");
        } catch (\Throwable $e) {
            $this->flash->error('เปิดค้างคืนไม่สำเร็จ: ' . $e->getMessage());
        }

        return $this->response->redirect('/ingress');
    }

    public function reopenNowAction($id)
    {
        if (!$this->request->isPost() || !$this->security->checkToken()) {
            $this->flash->error('คำขอไม่ถูกต้อง (CSRF)');
            return $this->response->redirect('/ingress');
        }

        $row = IngressRequests::findFirst((int) $id);

        if ($row === null || $row->status !== 'closed') {
            $this->flash->error('ไม่พบรายการ หรือรายการนี้ไม่ได้อยู่ในสถานะปิดชั่วคราว');
            return $this->response->redirect('/ingress');
        }

        try {
            $this->ingressRequestService->requestManualReopen($row, $this->currentUser());
            $this->flash->success('ส่งคำขอเปิดใช้งานแล้ว กำลังดำเนินการ (ภายใน 1 นาที)');
        } catch (\Throwable $e) {
            $this->flash->error('เปิดใช้งานไม่สำเร็จ: ' . $e->getMessage());
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
