<?php

namespace App\Services;

use App\Models\IngressRequests;
use App\Models\K8sCommands;
use App\Models\Users;

/**
 * Only enqueues intent — never calls the Kubernetes API itself (the
 * preview*Payload() calls made here are pure local computation, no HTTP).
 * Every mutating action (create/delete) is recorded as a `k8s_commands` row
 * with status='pending'; KubernetesTask::processCommandsAction() (run on a
 * schedule, see k8s/cronjob.yaml) is the only thing that actually talks to
 * Kubernetes and writes the audit log, so a crashed/timed-out web request
 * can never leave a real cluster change with no trace of it.
 */
class IngressRequestService
{
    private const MAX_SCHEDULE_MINUTES = 10080; // 7 days

    // Same DNS-1123 subdomain format Kubernetes validates Ingress hosts
    // against (see KubernetesService::DNS_1123_SUBDOMAIN) — duplicated here
    // so create() can reject a malformed host with a clear Thai message
    // synchronously, instead of it only surfacing later as a silently
    // logged preview-build failure or a bot-processing error.
    private const DNS_1123_SUBDOMAIN = '/^[a-z0-9]([-a-z0-9]*[a-z0-9])?(\.[a-z0-9]([-a-z0-9]*[a-z0-9])?)*$/';

    // Matches the ingress_requests.note column width (see migrations/0015_add_note_to_ingress_requests.sql)
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
     * @param array{developer_name: string, namespace: string, deployment_name: string, target_port: int, schedule_end_minutes: int, request_type?: string, host?: string, secret_name?: string} $data
     */
    public function create(array $data, Users $user): IngressRequests
    {
        $normalized = $this->validateAndNormalize($data);
        $this->assertNoDuplicateNodePortEndpoint($normalized);

        $row = new IngressRequests();
        $row->developer_name = $normalized['developer_name'];
        $row->namespace = $normalized['namespace'];
        $row->deployment_name = $normalized['deployment_name'];
        $row->request_type = $normalized['request_type'];
        $row->target_port = $normalized['target_port'];
        $row->node_ip = $this->nodeIp;
        $row->host = $normalized['host'];
        $row->secret_name = $normalized['secret_name'];
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

        $this->enqueue($row, 'create', $user, 'ingress_requested');

        return $row;
    }

    /**
     * Only reachable when isEditable($row) — i.e. no live Service/Ingress
     * exists yet for this request (still `pending`, or `failed` on its
     * `create` attempt). Just corrects the DB row: for `pending` rows the
     * already-queued `k8s_commands` row will read the corrected fields
     * straight off $row on its next tick (see KubernetesTask::processCommandsAction()),
     * so nothing else needs to change here; for `failed` rows the user
     * retries separately via the existing retry() flow.
     */
    public function update(IngressRequests $row, array $data, Users $user): void
    {
        if (!$this->isEditable($row)) {
            throw new \RuntimeException('รายการนี้ไม่สามารถแก้ไขได้แล้ว');
        }

        $normalized = $this->validateAndNormalize($data);
        $this->assertNoDuplicateNodePortEndpoint($normalized, $row->id);

        $row->developer_name = $normalized['developer_name'];
        $row->namespace = $normalized['namespace'];
        $row->deployment_name = $normalized['deployment_name'];
        $row->request_type = $normalized['request_type'];
        $row->target_port = $normalized['target_port'];
        $row->host = $normalized['host'];
        $row->secret_name = $normalized['secret_name'];
        $row->schedule_end_minutes = $normalized['schedule_end_minutes'];
        $row->note = $normalized['note'];

        if (!$row->save()) {
            throw new \RuntimeException(implode(', ', array_map(
                fn ($m) => $m->getMessage(),
                $row->getMessages()
            )));
        }

        $this->auditLogService->log('ingress_updated', AuditLogService::actorLabelFor($user), [
            'ingress_request_id' => $row->id,
            'actor_user_id' => $user->id,
            'namespace' => $row->namespace,
            'deployment_name' => $row->deployment_name,
            'node_port' => $row->node_port,
            'node_ip' => $row->node_ip,
            'detail' => [
                'request_type' => $row->request_type,
                'host' => $row->host,
                'secret_name' => $row->secret_name,
                'target_port' => $row->target_port,
                'note' => $row->note,
            ],
        ]);
    }

    /**
     * true only when no live Service/Ingress can exist for $row yet:
     * `pending` always follows a `create` action in this state machine (see
     * retry()'s status assignment below), and a `failed` row is only safe
     * to edit if its last command was the `create` attempt itself failing
     * — a failed `delete` may still correspond to a real cluster resource.
     */
    public function isEditable(IngressRequests $row): bool
    {
        if ($row->status === 'pending') {
            return true;
        }

        if ($row->status !== 'failed') {
            return false;
        }

        $lastCommand = K8sCommands::findFirst([
            'conditions' => 'ingress_request_id = :id:',
            'bind' => ['id' => $row->id],
            'order' => 'id DESC',
        ]);

        return $lastCommand !== null && $lastCommand->action === 'create';
    }

    /**
     * @return array{developer_name: string, namespace: string, deployment_name: string, request_type: string, target_port: int, host: ?string, secret_name: ?string, schedule_end_minutes: int, note: ?string}
     */
    private function validateAndNormalize(array $data): array
    {
        $developerName = trim((string) $data['developer_name']);
        $namespace = trim((string) $data['namespace']);
        $deploymentName = trim((string) $data['deployment_name']);
        $targetPort = (int) $data['target_port'];
        $scheduleMinutes = (int) $data['schedule_end_minutes'];
        $requestType = (string) ($data['request_type'] ?? 'nodeport');
        $host = trim((string) ($data['host'] ?? ''));
        $secretName = trim((string) ($data['secret_name'] ?? ''));
        $note = trim((string) ($data['note'] ?? ''));

        if ($developerName === '') {
            throw new \InvalidArgumentException('กรุณาระบุชื่อ Developer');
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
        if (!in_array($requestType, ['nodeport', 'ingress'], true)) {
            throw new \InvalidArgumentException('ประเภท Ingress ไม่ถูกต้อง');
        }
        // Host is required for every type, not just 'ingress' — it doubles
        // as the uniquekey registered in the line_login collection (see
        // LineLoginService), which every provisioned domain needs
        // regardless of whether it's fronted by a real k8s Ingress or a
        // NodePort Service.
        if ($host === '') {
            throw new \InvalidArgumentException('กรุณาระบุ Host (โดเมน)');
        }
        // NodePort has no real domain — Host there is the external-facing
        // IP that fronts node_ip:node_port (not node_ip itself, which stays
        // fixed per $this->nodeIp); 'ingress' keeps the DNS hostname format
        // Kubernetes' own Ingress resource requires.
        if ($requestType === 'nodeport') {
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                throw new \InvalidArgumentException('รูปแบบ Host ไม่ถูกต้อง — สำหรับ NodePort ต้องเป็น IP เช่น 192.168.33.31');
            }
        } elseif (!preg_match(self::DNS_1123_SUBDOMAIN, $host) || strlen($host) > 253) {
            throw new \InvalidArgumentException('รูปแบบ Host ไม่ถูกต้อง — ใส่แค่ชื่อโดเมน เช่น myapp.advws.com (ห้ามมี http:// หรือ / ต่อท้าย)');
        }
        if ($requestType === 'ingress' && $secretName === '') {
            throw new \InvalidArgumentException('กรุณาเลือก Secret Name (TLS)');
        }

        return [
            'developer_name' => $developerName,
            'namespace' => $namespace,
            'deployment_name' => $deploymentName,
            'request_type' => $requestType,
            'target_port' => $targetPort,
            'host' => $host,
            'secret_name' => $requestType === 'ingress' ? $secretName : null,
            'schedule_end_minutes' => $scheduleMinutes,
            'note' => $note !== '' ? $note : null,
        ];
    }

    /**
     * NodePort's Host is a developer-chosen external IP, not something
     * Kubernetes enforces uniqueness on (unlike node_port, which k8s itself
     * guarantees is never double-allocated) — two active/pending requests
     * claiming the same IP+target_port would collide at whatever fronts
     * that endpoint. Only checked for 'nodeport': an 'ingress' Host is a
     * DNS hostname, which is naturally expected to be unique on its own.
     */
    private function assertNoDuplicateNodePortEndpoint(array $normalized, ?int $excludeId = null): void
    {
        if ($normalized['request_type'] !== 'nodeport') {
            return;
        }

        $conditions = "request_type = 'nodeport' AND host = :host: AND target_port = :target_port: AND status IN ('pending', 'active')";
        $bind = ['host' => $normalized['host'], 'target_port' => $normalized['target_port']];

        if ($excludeId !== null) {
            $conditions .= ' AND id != :exclude_id:';
            $bind['exclude_id'] = $excludeId;
        }

        $duplicate = IngressRequests::findFirst(['conditions' => $conditions, 'bind' => $bind]);

        if ($duplicate !== null) {
            throw new \InvalidArgumentException("Host {$normalized['host']} พอร์ต {$normalized['target_port']} ถูกใช้งานอยู่แล้วโดยรายการอื่น");
        }
    }

    /**
     * Pushes $row->expires_at back by $additionalMinutes — nothing else. No
     * k8s_commands row, no bot involvement: the live Service/Ingress is
     * already up, and expires_at is purely a DB-tracked deadline enforced
     * by KubernetesTask::pruneExpiredAction() (see k8s/cronjob.yaml), so
     * extending it is a plain row update.
     */
    public function renew(IngressRequests $row, int $additionalMinutes, Users $user): void
    {
        if ($row->status !== 'active') {
            throw new \RuntimeException('ต่ออายุได้เฉพาะรายการที่สถานะเป็น active');
        }
        if ($additionalMinutes < 1 || $additionalMinutes > self::MAX_SCHEDULE_MINUTES) {
            throw new \InvalidArgumentException('จำนวนนาทีที่ต่ออายุต้องอยู่ระหว่าง 1-' . self::MAX_SCHEDULE_MINUTES);
        }

        $oldExpiresAt = $row->expires_at;

        // max() guards against extending off an expires_at that's already
        // in the past (e.g. the sweeper hasn't ticked yet this minute) —
        // otherwise a small enough $additionalMinutes could land the new
        // expires_at back in the past too.
        $base = max(strtotime($row->expires_at), time());
        $row->expires_at = date('Y-m-d H:i:s', $base + $additionalMinutes * 60);

        if (!$row->save()) {
            throw new \RuntimeException(implode(', ', array_map(
                fn ($m) => $m->getMessage(),
                $row->getMessages()
            )));
        }

        $this->auditLogService->log('ingress_renewed', AuditLogService::actorLabelFor($user), [
            'ingress_request_id' => $row->id,
            'actor_user_id' => $user->id,
            'namespace' => $row->namespace,
            'deployment_name' => $row->deployment_name,
            'node_port' => $row->node_port,
            'node_ip' => $row->node_ip,
            'detail' => [
                'added_minutes' => $additionalMinutes,
                'old_expires_at' => $oldExpiresAt,
                'new_expires_at' => $row->expires_at,
            ],
        ]);
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

        // Best-effort preview so request_payload isn't blank until the bot's
        // next tick — for `create` this can't include the live Deployment
        // selector (only resolvable via a real Kubernetes call), so it's
        // just a draft. KubernetesTask::processCommandsAction() overwrites
        // this with the real getRequestLog() once it actually processes
        // the command. Must never block enqueue itself on failure.
        try {
            $preview = $this->buildPreviewPayload($row, $action);
            $command->request_payload = json_encode($preview);
            $command->payload_source = 'preview';
            $command->save();
        } catch (\Throwable $e) {
            // leave request_payload/payload_source null — same as before this
            // existed. Logged so a bad value (e.g. a Host field that isn't a
            // bare hostname) is visible on the /audit/{id} Trail instead of
            // silently vanishing — this is the same validation
            // createNodePortService()/createIngress() will hit later when
            // the bot actually processes the command, so it would have
            // failed then anyway; this just surfaces it earlier too.
            $this->auditLogService->log('preview_payload_failed', AuditLogService::actorLabelFor($user), [
                'ingress_request_id' => $row->id,
                'actor_user_id' => $user->id,
                'namespace' => $row->namespace,
                'deployment_name' => $row->deployment_name,
                'detail' => ['action' => $action, 'error' => $e->getMessage()],
            ]);
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
                'note' => $row->note,
            ],
        ]);
    }

    /**
     * No HTTP calls — builds from data already sitting on $row. For
     * `create`, service_name/ingress_name aren't known yet (only assigned
     * once the bot's create call returns), so this is necessarily a partial
     * draft. For `delete`, $row already has everything (service_name/
     * ingress_name were filled in by the earlier create), so this matches
     * exactly what will actually be sent.
     */
    private function buildPreviewPayload(IngressRequests $row, string $action): array
    {
        if ($action === 'create') {
            if ($row->request_type === 'ingress') {
                return $this->kubernetesService->previewCreateIngressPayload(
                    $row->namespace,
                    $row->deployment_name,
                    $row->target_port,
                    $row->host,
                    $row->secret_name,
                    $row->id
                );
            }

            return $this->kubernetesService->previewCreateNodePortServicePayload(
                $row->namespace,
                $row->deployment_name,
                $row->target_port,
                $row->id
            );
        }

        if ($row->request_type === 'ingress') {
            return $this->kubernetesService->previewDeleteIngressPayload($row->namespace, $row->ingress_name, $row->service_name);
        }

        return $this->kubernetesService->previewDeleteServicePayload($row->namespace, $row->service_name);
    }
}
