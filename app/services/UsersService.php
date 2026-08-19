<?php

namespace App\Services;

use App\Models\Users;

/**
 * A devops actor can never change their own role or activation state — this
 * is the only lockout guard the app needs: since an actor can't touch their
 * own row, at least one devops account always survives every action.
 */
class UsersService
{
    private AuditLogService $auditLogService;

    public function __construct(AuditLogService $auditLogService)
    {
        $this->auditLogService = $auditLogService;
    }

    public function setRole(Users $target, string $role, Users $actor): void
    {
        if (!in_array($role, Users::ROLES, true)) {
            throw new \InvalidArgumentException('Role ไม่ถูกต้อง');
        }

        if ($target->id === $actor->id) {
            throw new \RuntimeException('ไม่สามารถเปลี่ยน role ของบัญชีตัวเองได้');
        }

        $oldRole = $target->role;
        $target->role = $role;

        if (!$target->save()) {
            throw new \RuntimeException(implode(', ', array_map(
                fn ($m) => $m->getMessage(),
                $target->getMessages()
            )));
        }

        $this->auditLogService->log('user_role_changed', AuditLogService::actorLabelFor($actor), [
            'actor_user_id' => $actor->id,
            'detail' => [
                'target_user_id' => $target->id,
                'email' => $target->email,
                'old_role' => $oldRole,
                'new_role' => $role,
            ],
        ]);
    }

    public function setActive(Users $target, bool $active, Users $actor): void
    {
        if ($target->id === $actor->id) {
            throw new \RuntimeException('ไม่สามารถปิด/เปิดการใช้งานบัญชีตัวเองได้');
        }

        $target->is_active = $active ? 1 : 0;

        if (!$target->save()) {
            throw new \RuntimeException(implode(', ', array_map(
                fn ($m) => $m->getMessage(),
                $target->getMessages()
            )));
        }

        $this->auditLogService->log($active ? 'user_activated' : 'user_deactivated', AuditLogService::actorLabelFor($actor), [
            'actor_user_id' => $actor->id,
            'detail' => [
                'target_user_id' => $target->id,
                'email' => $target->email,
            ],
        ]);
    }

    private const MIN_PASSWORD_LENGTH = 8;

    /**
     * Google SSO is the primary login path for this app — password login is
     * just a break-glass fallback (see UsersTask's docblock). There's no
     * email/SMTP configured to self-serve a reset link, so a devops either
     * types the new password directly ($newPassword) or leaves it blank to
     * get one generated server-side — either way it's returned in plaintext
     * exactly once for the calling devops to relay out-of-band (Slack, in
     * person, etc.); it is never stored or logged anywhere in plaintext,
     * only its bcrypt hash.
     */
    public function resetPassword(Users $target, Users $actor, ?string $newPassword = null): string
    {
        if ($newPassword !== null && $newPassword !== '') {
            if (strlen($newPassword) < self::MIN_PASSWORD_LENGTH) {
                throw new \InvalidArgumentException('รหัสผ่านต้องมีอย่างน้อย ' . self::MIN_PASSWORD_LENGTH . ' ตัวอักษร');
            }
            $password = $newPassword;
        } else {
            $password = bin2hex(random_bytes(9));
        }

        $target->password_hash = password_hash($password, PASSWORD_BCRYPT);

        if (!$target->save()) {
            throw new \RuntimeException(implode(', ', array_map(
                fn ($m) => $m->getMessage(),
                $target->getMessages()
            )));
        }

        $this->auditLogService->log('user_password_reset', AuditLogService::actorLabelFor($actor), [
            'actor_user_id' => $actor->id,
            'detail' => [
                'target_user_id' => $target->id,
                'email' => $target->email,
            ],
        ]);

        return $password;
    }

    /**
     * Safe to edit even for the current user — unlike role/active this
     * can't lock anyone out of devops access. Only actually "sticks" for
     * password-login accounts though: AuthService::findOrCreateByGoogle()
     * overwrites `email` from the real Google account on every SSO login,
     * so an edit here gets resynced away for anyone still using Google SSO.
     */
    public function updateEmail(Users $target, string $email, Users $actor): void
    {
        $email = trim($email);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('รูปแบบอีเมลไม่ถูกต้อง');
        }

        $existing = Users::findFirst([
            'conditions' => 'email = :email: AND id != :id:',
            'bind' => ['email' => $email, 'id' => $target->id],
        ]);

        if ($existing !== null) {
            throw new \RuntimeException('มีผู้ใช้ที่ใช้อีเมลนี้อยู่แล้ว');
        }

        $oldEmail = $target->email;
        $target->email = $email;

        if (!$target->save()) {
            throw new \RuntimeException(implode(', ', array_map(
                fn ($m) => $m->getMessage(),
                $target->getMessages()
            )));
        }

        $this->auditLogService->log('user_email_changed', AuditLogService::actorLabelFor($actor), [
            'actor_user_id' => $actor->id,
            'detail' => [
                'target_user_id' => $target->id,
                'old_email' => $oldEmail,
                'new_email' => $email,
            ],
        ]);
    }
}
