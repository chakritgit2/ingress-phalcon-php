<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Users;

class AuditLogService
{
    /**
     * Append an immutable audit event.
     *
     * @param string $eventType one of AuditLog's event_type enum values
     * @param string $actorLabel human-readable actor, e.g. "pannawat@advws.com" or "system:sweeper"
     * @param array $context optional: ingress_request_id, actor_user_id, namespace,
     *                        deployment_name, node_port, node_ip, detail (array)
     */
    public function log(string $eventType, string $actorLabel, array $context = []): AuditLog
    {
        $entry = new AuditLog();
        $entry->event_type = $eventType;
        $entry->actor_label = $actorLabel;
        $entry->ingress_request_id = $context['ingress_request_id'] ?? null;
        $entry->actor_user_id = $context['actor_user_id'] ?? null;
        $entry->namespace = $context['namespace'] ?? null;
        $entry->deployment_name = $context['deployment_name'] ?? null;
        $entry->node_port = $context['node_port'] ?? null;
        $entry->node_ip = $context['node_ip'] ?? null;
        $entry->detail = isset($context['detail']) ? json_encode($context['detail']) : null;

        $entry->save();

        return $entry;
    }

    public static function actorLabelFor(?Users $user): string
    {
        return $user !== null ? $user->email : 'system:unknown';
    }
}
