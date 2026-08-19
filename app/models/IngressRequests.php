<?php

namespace App\Models;

use Phalcon\Mvc\Model;

class IngressRequests extends Model
{
    public ?int $id = null;
    public string $public_id;
    public string $developer_name;
    public string $namespace;
    public string $deployment_name;
    public string $request_type = 'nodeport';
    public int $target_port;
    public ?string $service_name = null;
    public ?string $ingress_name = null;
    public ?int $node_port = null;
    public string $node_ip;
    public ?string $host = null;
    public ?string $secret_name = null;
    public int $schedule_end_minutes;
    public ?string $note = null;
    public int $created_by_user_id;
    public ?string $created_at = null;
    public ?string $expires_at = null;
    public string $status;
    public ?string $deleted_at = null;
    public ?string $deleted_by = null;
    public ?string $k8s_uid = null;
    public ?string $last_error = null;

    public function initialize(): void
    {
        $this->setSource('ingress_requests');
        $this->belongsTo('created_by_user_id', Users::class, 'id', ['alias' => 'creator']);
        $this->hasMany('id', AuditLog::class, 'ingress_request_id', ['alias' => 'auditEvents']);
        $this->hasMany('id', K8sCommands::class, 'ingress_request_id', ['alias' => 'commands']);
    }

    public function beforeValidationOnCreate(): void
    {
        if (empty($this->public_id)) {
            $this->public_id = self::uuidV4();
        }
    }

    public function address(): string
    {
        if ($this->request_type === 'ingress') {
            return $this->host !== null ? "https://{$this->host}" : 'รอดำเนินการ';
        }

        return $this->node_port !== null ? "{$this->node_ip}:{$this->node_port}" : 'รอดำเนินการ';
    }

    private static function uuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
