<?php

namespace App\Models;

use Phalcon\Mvc\Model;

class Users extends Model
{
    public ?int $id = null;
    public string $google_sub;
    public string $email;
    public string $name;
    public ?string $avatar_url = null;
    public string $hosted_domain;
    public string $role;
    public int $is_active;
    public ?string $last_login_at = null;
    public ?string $created_at = null;

    public function initialize(): void
    {
        $this->setSource('users');
        $this->hasMany('id', IngressRequests::class, 'created_by_user_id', ['alias' => 'ingressRequests']);
    }

    public function isDevops(): bool
    {
        return $this->role === 'devops';
    }
}
