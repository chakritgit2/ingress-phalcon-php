<?php

namespace App\Controllers;

use App\Models\IngressRequests;

class IngressController extends ControllerBase
{
    public function indexAction(): void
    {
        $rows = IngressRequests::find(['order' => 'created_at DESC', 'limit' => 100]);
        $this->view->setVar('rows', $rows);
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
        ];

        try {
            $this->ingressRequestService->create($data, $this->currentUser());
            $this->flash->success('ส่งคำขอแล้ว กำลังดำเนินการสร้าง Ingress (ดูสถานะได้ที่รายการด้านล่าง)');
        } catch (\Throwable $e) {
            $this->flash->error('สร้างไม่สำเร็จ: ' . $e->getMessage());
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
}
