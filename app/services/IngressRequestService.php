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

    public function __construct(string $nodeIp)
    {
        $this->nodeIp = $nodeIp;
    }

    /**
     * @param array{developer_name: string, namespace: string, deployment_name: string, target_port: int, schedule_end_minutes: int} $data
     */
    public function create(array $data, Users $user): IngressRequests
    {
        $developerName = trim((string) $data['developer_name']);
        $namespace = trim((string) $data['namespace']);
        $deploymentName = trim((string) $data['deployment_name']);
        $targetPort = (int) $data['target_port'];
        $scheduleMinutes = (int) $data['schedule_end_minutes'];

        if ($developerName === '') {
            throw new \InvalidArgumentException('กรุณาระบุชื่อ Developer');
        }
        if ($targetPort < 1 || $targetPort > 65535) {
            throw new \InvalidArgumentException('Port ต้องอยู่ระหว่าง 1-65535');
        }
        if ($scheduleMinutes < 1 || $scheduleMinutes > self::MAX_SCHEDULE_MINUTES) {
            throw new \InvalidArgumentException('Schedule End ต้องอยู่ระหว่าง 1-' . self::MAX_SCHEDULE_MINUTES . ' นาที');
        }

        $row = new IngressRequests();
        $row->developer_name = $developerName;
        $row->namespace = $namespace;
        $row->deployment_name = $deploymentName;
        $row->target_port = $targetPort;
        $row->node_ip = $this->nodeIp;
        $row->schedule_end_minutes = $scheduleMinutes;
        $row->created_by_user_id = $user->id;
        $row->status = 'pending';

        if (!$row->save()) {
            throw new \RuntimeException(implode(', ', array_map(
                fn ($m) => $m->getMessage(),
                $row->getMessages()
            )));
        }

        $this->enqueue($row, 'create', $user);

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

        $this->enqueue($row, 'delete', $user);
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

        $this->enqueue($row, $lastCommand->action, $user);
    }

    private function enqueue(IngressRequests $row, string $action, Users $user): void
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
    }
}
