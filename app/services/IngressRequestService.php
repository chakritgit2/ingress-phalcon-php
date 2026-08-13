<?php

namespace App\Services;

use App\Models\IngressRequests;
use App\Models\K8sCommands;
use App\Models\Users;

/**
 * Only enqueues intent — never calls the Kubernetes API itself. Every
 * mutating action (create/delete) is recorded as a `k8s_commands` row with
 * status='pending'; KubernetesTask::processCommandsAction() (run on a
 * schedule, see k8s/cronjob.yaml) is the only thing that actually talks to
 * Kubernetes and writes the audit log, so a crashed/timed-out web request
 * can never leave a real cluster change with no trace of it.
 */
class IngressRequestService
{
    private const MAX_SCHEDULE_MINUTES = 10080; // 7 days

    private string $nodeIp;
    private AuditLogService $auditLogService;

    public function __construct(string $nodeIp, AuditLogService $auditLogService)
    {
        $this->nodeIp = $nodeIp;
        $this->auditLogService = $auditLogService;
    }

    /**
     * @param array{developer_name: string, namespace: string, deployment_name: string, target_port: int, schedule_end_minutes: int, request_type?: string, host?: string, secret_name?: string} $data
     */
    public function create(array $data, Users $user): IngressRequests
    {
        $developerName = trim((string) $data['developer_name']);
        $namespace = trim((string) $data['namespace']);
        $deploymentName = trim((string) $data['deployment_name']);
        $targetPort = (int) $data['target_port'];
        $scheduleMinutes = (int) $data['schedule_end_minutes'];
        $requestType = (string) ($data['request_type'] ?? 'nodeport');
        $host = trim((string) ($data['host'] ?? ''));
        $secretName = trim((string) ($data['secret_name'] ?? ''));

        if ($developerName === '') {
            throw new \InvalidArgumentException('กรุณาระบุชื่อ Developer');
        }
        if ($targetPort < 1 || $targetPort > 65535) {
            throw new \InvalidArgumentException('Port ต้องอยู่ระหว่าง 1-65535');
        }
        if ($scheduleMinutes < 1 || $scheduleMinutes > self::MAX_SCHEDULE_MINUTES) {
            throw new \InvalidArgumentException('Schedule End ต้องอยู่ระหว่าง 1-' . self::MAX_SCHEDULE_MINUTES . ' นาที');
        }
        if (!in_array($requestType, ['nodeport', 'ingress'], true)) {
            throw new \InvalidArgumentException('ประเภท Ingress ไม่ถูกต้อง');
        }
        if ($requestType === 'ingress') {
            if ($host === '') {
                throw new \InvalidArgumentException('กรุณาระบุ Host (โดเมน)');
            }
            if ($secretName === '') {
                throw new \InvalidArgumentException('กรุณาเลือก Secret Name (TLS)');
            }
        }

        $row = new IngressRequests();
        $row->developer_name = $developerName;
        $row->namespace = $namespace;
        $row->deployment_name = $deploymentName;
        $row->request_type = $requestType;
        $row->target_port = $targetPort;
        $row->node_ip = $this->nodeIp;
        $row->host = $requestType === 'ingress' ? $host : null;
        $row->secret_name = $requestType === 'ingress' ? $secretName : null;
        $row->schedule_end_minutes = $scheduleMinutes;
        $row->created_by_user_id = $user->id;
        $row->status = 'pending';

        if (!$row->save()) {
            throw new \RuntimeException(implode(', ', array_map(
                fn ($m) => $m->getMessage(),
                $row->getMessages()
            )));
        }

        $this->enqueue($row, 'create', $user, 'ingress_requested');

        return $row;
    }

    public function deleteManually(IngressRequests $row, Users $user): void
    {
        $row->status = 'deleting';

        if (!$row->save()) {
            throw new \RuntimeException(implode(', ', array_map(
                fn ($m) => $m->getMessage(),
                $row->getMessages()
            )));
        }

        $this->enqueue($row, 'delete', $user, 'ingress_delete_requested');
    }

    /**
     * Re-queues a failed row for another attempt, repeating whichever
     * action (create/delete) last failed. Leaves the failed k8s_commands
     * row as its own historical record — retrying enqueues a new one.
     */
    public function retry(IngressRequests $row, Users $user): void
    {
        $lastCommand = K8sCommands::findFirst([
            'conditions' => 'ingress_request_id = :id:',
            'bind' => ['id' => $row->id],
            'order' => 'id DESC',
        ]);

        if ($lastCommand === null) {
            throw new \RuntimeException('ไม่พบประวัติคำสั่งของรายการนี้');
        }

        $row->status = $lastCommand->action === 'create' ? 'pending' : 'deleting';
        $row->last_error = null;

        if (!$row->save()) {
            throw new \RuntimeException(implode(', ', array_map(
                fn ($m) => $m->getMessage(),
                $row->getMessages()
            )));
        }

        $this->enqueue($row, $lastCommand->action, $user, 'ingress_retry_requested');
    }

    private function enqueue(IngressRequests $row, string $action, Users $user, string $auditEvent): void
    {
        $command = new K8sCommands();
        $command->ingress_request_id = $row->id;
        $command->action = $action;
        $command->status = 'pending';
        $command->requested_by_user_id = $user->id;

        if (!$command->save()) {
            throw new \RuntimeException(implode(', ', array_map(
                fn ($m) => $m->getMessage(),
                $command->getMessages()
            )));
        }

        // Logged at request time (not just once KubernetesTask actually
        // processes it) so there's a trail of who asked for what even
        // before — or if — the background worker ever picks it up.
        $this->auditLogService->log($auditEvent, AuditLogService::actorLabelFor($user), [
            'ingress_request_id' => $row->id,
            'actor_user_id' => $user->id,
            'namespace' => $row->namespace,
            'deployment_name' => $row->deployment_name,
            'node_port' => $row->node_port,
            'node_ip' => $row->node_ip,
            'detail' => [
                'action' => $action,
                'request_type' => $row->request_type,
                'host' => $row->host,
                'secret_name' => $row->secret_name,
                'target_port' => $row->target_port,
            ],
        ]);
    }
}
