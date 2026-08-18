<?php

namespace App\Models;

use Phalcon\Mvc\Model;

class K8sCommands extends Model
{
    public ?int $id = null;
    public int $ingress_request_id;
    public string $action;
    public ?string $request_payload = null;
    public ?string $payload_source = null;
    public string $status;
    public int $requested_by_user_id;
    public ?string $result = null;
    public ?string $error_message = null;
    public ?string $created_at = null;
    public ?string $processed_at = null;

    public function initialize(): void
    {
        $this->setSource('k8s_commands');
        $this->belongsTo('ingress_request_id', IngressRequests::class, 'id', ['alias' => 'ingressRequest']);
        $this->belongsTo('requested_by_user_id', Users::class, 'id', ['alias' => 'requestedBy']);
    }
}
