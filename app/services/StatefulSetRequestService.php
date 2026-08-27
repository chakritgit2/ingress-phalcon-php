<?php

namespace App\Services;

use App\Models\StatefulSetCommands;
use App\Models\StatefulSetRequests;
use App\Models\Users;

/**
 * StatefulSet counterpart of IngressRequestService — same "only enqueue
 * intent, never call Kubernetes here" design (see that class's docblock),
 * simplified: no Ingress+TLS/domain mode, so no host/secret_name/request_type
 * to validate, and no LINE-Login/NODE_ADMIN_PATH side effects to account for.
 */
class StatefulSetRequestService
{
    private const MAX_SCHEDULE_MINUTES = 10080; // 7 days

    // Matches the statefulset_requests.note column width.
    private const MAX_NOTE_LENGTH = 255;

    private string $nodeIp;
    private AuditLogService $auditLogService;
    private KubernetesServiceInterface $kubernetesService;

    public function __construct(string $nodeIp, AuditLogService $auditLogService, KubernetesServiceInterface $kubernetesService)
    {
        $this->nodeIp = $nodeIp;
        $this->auditLogService = $auditLogService;
        $this->kubernetesService = $kubernetesService;
    }

    /**
     * @param array{developer_name: string, namespace: string, statefulset_name: string, target_port: int, schedule_end_minutes: int, note?: string} $data
     */
    public function create(array $data, Users $user): StatefulSetRequests
    {
        $normalized = $this->validateAndNormalize($data);

        $row = new StatefulSetRequests();
        $row->developer_name = $normalized['developer_name'];
        $row->namespace = $normalized['namespace'];
        $row->statefulset_name = $normalized['statefulset_name'];
        $row->target_port = $normalized['target_port'];
        $row->node_ip = $this->nodeIp;
        $row->schedule_end_minutes = $normalized['schedule_end_minutes'];
        $row->note = $normalized['note'];
        $row->created_by_user_id = $user->id;
        $row->status = 'pending';

        if (!$row->save()) {
            throw new \RuntimeException(implode(', ', array_map(
                fn ($m) => $m->getMessage(),
                $row->getMessages()
            )));
        }

        $this->enqueue($row, 'create', $user, 'statefulset_requested');

        return $row;
    }

    /**
     * Only reachable when isEditable($row) — same reasoning as
     * IngressRequestService::update().
     */
    public function update(StatefulSetRequests $row, array $data, Users $user): void
    {
        if (!$this->isEditable($row)) {
            throw new \RuntimeException('รายการนี้ไม่สามารถแก้ไขได้แล้ว');
        }

        $normalized = $this->validateAndNormalize($data);

        $row->developer_name = $normalized['developer_name'];
        $row->namespace = $normalized['namespace'];
        $row->statefulset_name = $normalized['statefulset_name'];
        $row->target_port = $normalized['target_port'];
        $row->schedule_end_minutes = $normalized['schedule_end_minutes'];
        $row->note = $normalized['note'];

        if (!$row->save()) {
            throw new \RuntimeException(implode(', ', array_map(
                fn ($m) => $m->getMessage(),
                $row->getMessages()
            )));
        }

        $this->auditLogService->log('statefulset_updated', AuditLogService::actorLabelFor($user), [
            'statefulset_request_id' => $row->id,
            'actor_user_id' => $user->id,
            'namespace' => $row->namespace,
            'statefulset_name' => $row->statefulset_name,
            'node_port' => $row->node_port,
            'node_ip' => $row->node_ip,
            'detail' => [
                'target_port' => $row->target_port,
                'note' => $row->note,
            ],
        ]);
    }

    /**
     * Same reasoning as IngressRequestService::isEditable() — true only when
     * no live Service can exist for $row yet.
     */
    public function isEditable(StatefulSetRequests $row): bool
    {
        if ($row->status === 'pending') {
            return true;
        }

        if ($row->status !== 'failed') {
            return false;
        }

        $lastCommand = StatefulSetCommands::findFirst([
            'conditions' => 'statefulset_request_id = :id:',
            'bind' => ['id' => $row->id],
            'order' => 'id DESC',
        ]);

        return $lastCommand !== null && $lastCommand->action === 'create';
    }

    /**
     * @return array{developer_name: string, namespace: string, statefulset_name: string, target_port: int, schedule_end_minutes: int, note: ?string}
     */
    private function validateAndNormalize(array $data): array
    {
        $developerName = trim((string) $data['developer_name']);
        $namespace = trim((string) $data['namespace']);
        $statefulSetName = trim((string) $data['statefulset_name']);
        $targetPort = (int) $data['target_port'];
        $scheduleMinutes = (int) $data['schedule_end_minutes'];
        $note = trim((string) ($data['note'] ?? ''));

        if ($developerName === '') {
            throw new \InvalidArgumentException('กรุณาระบุชื่อ Developer');
        }
        if ($namespace === '') {
            throw new \InvalidArgumentException('กรุณาเลือก Namespace');
        }
        if ($statefulSetName === '') {
            throw new \InvalidArgumentException('กรุณาเลือก StatefulSet');
        }
        if ($targetPort < 1 || $targetPort > 65535) {
            throw new \InvalidArgumentException('Port ต้องอยู่ระหว่าง 1-65535');
        }
        if ($scheduleMinutes < 1 || $scheduleMinutes > self::MAX_SCHEDULE_MINUTES) {
            throw new \InvalidArgumentException('Schedule End ต้องอยู่ระหว่าง 1-' . self::MAX_SCHEDULE_MINUTES . ' นาที');
        }
        if (strlen($note) > self::MAX_NOTE_LENGTH) {
            throw new \InvalidArgumentException('หมายเหตุยาวเกินไป (ไม่เกิน ' . self::MAX_NOTE_LENGTH . ' ตัวอักษร)');
        }

        return [
            'developer_name' => $developerName,
            'namespace' => $namespace,
            'statefulset_name' => $statefulSetName,
            'target_port' => $targetPort,
            'schedule_end_minutes' => $scheduleMinutes,
            'note' => $note !== '' ? $note : null,
        ];
    }

    /**
     * Same reasoning as IngressRequestService::renew() — pushes expires_at
     * back, no k8s_commands row needed since the live Service is already up.
     */
    public function renew(StatefulSetRequests $row, int $additionalMinutes, Users $user): void
    {
        if ($row->status !== 'active') {
            throw new \RuntimeException('ต่ออายุได้เฉพาะรายการที่สถานะเป็น active');
        }
        if ($additionalMinutes < 1 || $additionalMinutes > self::MAX_SCHEDULE_MINUTES) {
            throw new \InvalidArgumentException('จำนวนนาทีที่ต่ออายุต้องอยู่ระหว่าง 1-' . self::MAX_SCHEDULE_MINUTES);
        }

        $oldExpiresAt = $row->expires_at;

        $base = max(strtotime($row->expires_at), time());
        $row->expires_at = date('Y-m-d H:i:s', $base + $additionalMinutes * 60);

        if (!$row->save()) {
            throw new \RuntimeException(implode(', ', array_map(
                fn ($m) => $m->getMessage(),
                $row->getMessages()
            )));
        }

        $this->auditLogService->log('statefulset_renewed', AuditLogService::actorLabelFor($user), [
            'statefulset_request_id' => $row->id,
            'actor_user_id' => $user->id,
            'namespace' => $row->namespace,
            'statefulset_name' => $row->statefulset_name,
            'node_port' => $row->node_port,
            'node_ip' => $row->node_ip,
            'detail' => [
                'added_minutes' => $additionalMinutes,
                'old_expires_at' => $oldExpiresAt,
                'new_expires_at' => $row->expires_at,
            ],
        ]);
    }

    public function deleteManually(StatefulSetRequests $row, Users $user): void
    {
        $row->status = 'deleting';

        if (!$row->save()) {
            throw new \RuntimeException(implode(', ', array_map(
                fn ($m) => $m->getMessage(),
                $row->getMessages()
            )));
        }

        $this->enqueue($row, 'delete', $user, 'statefulset_delete_requested');
    }

    /**
     * Re-queues a failed row for another attempt — same reasoning as
     * IngressRequestService::retry().
     */
    public function retry(StatefulSetRequests $row, Users $user): void
    {
        $lastCommand = StatefulSetCommands::findFirst([
            'conditions' => 'statefulset_request_id = :id:',
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

        $this->enqueue($row, $lastCommand->action, $user, 'statefulset_retry_requested');
    }

    private function enqueue(StatefulSetRequests $row, string $action, Users $user, string $auditEvent): void
    {
        $command = new StatefulSetCommands();
        $command->statefulset_request_id = $row->id;
        $command->action = $action;
        $command->status = 'pending';
        $command->requested_by_user_id = $user->id;

        if (!$command->save()) {
            throw new \RuntimeException(implode(', ', array_map(
                fn ($m) => $m->getMessage(),
                $command->getMessages()
            )));
        }

        // Best-effort preview — see IngressRequestService::enqueue() for why
        // this must never block enqueue() itself on failure.
        try {
            $preview = $this->buildPreviewPayload($row, $action);
            $command->request_payload = json_encode($preview);
            $command->payload_source = 'preview';
            $command->save();
        } catch (\Throwable $e) {
            $this->auditLogService->log('statefulset_preview_payload_failed', AuditLogService::actorLabelFor($user), [
                'statefulset_request_id' => $row->id,
                'actor_user_id' => $user->id,
                'namespace' => $row->namespace,
                'statefulset_name' => $row->statefulset_name,
                'detail' => ['action' => $action, 'error' => $e->getMessage()],
            ]);
        }

        $this->auditLogService->log($auditEvent, AuditLogService::actorLabelFor($user), [
            'statefulset_request_id' => $row->id,
            'actor_user_id' => $user->id,
            'namespace' => $row->namespace,
            'statefulset_name' => $row->statefulset_name,
            'node_port' => $row->node_port,
            'node_ip' => $row->node_ip,
            'detail' => [
                'action' => $action,
                'target_port' => $row->target_port,
                'note' => $row->note,
            ],
        ]);
    }

    private function buildPreviewPayload(StatefulSetRequests $row, string $action): array
    {
        if ($action === 'create') {
            return $this->kubernetesService->previewCreateNodePortServiceForStatefulSetPayload(
                $row->namespace,
                $row->statefulset_name,
                $row->target_port,
                $row->id
            );
        }

        return $this->kubernetesService->previewDeleteServicePayload($row->namespace, $row->service_name);
    }
}
