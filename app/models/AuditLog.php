<?php

namespace App\Models;

use Phalcon\Mvc\Model;

class AuditLog extends Model
{
    public ?int $id = null;
    public ?int $ingress_request_id = null;
    public string $event_type;
    public ?int $actor_user_id = null;
    public string $actor_label;
    public ?string $namespace = null;
    public ?string $deployment_name = null;
    public ?int $node_port = null;
    public ?string $node_ip = null;
    public ?string $detail = null;
    public ?string $created_at = null;

    public function initialize(): void
    {
        $this->setSource('audit_log');
        $this->belongsTo('ingress_request_id', IngressRequests::class, 'id', ['alias' => 'ingressRequest']);
        $this->belongsTo('actor_user_id', Users::class, 'id', ['alias' => 'actor']);
    }

    public function getDetailArray(): array
    {
        return $this->detail !== null ? (json_decode($this->detail, true) ?: []) : [];
    }
}
