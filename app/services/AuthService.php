<?php

namespace App\Services;

use App\Models\Users;
use Phalcon\Session\Manager as SessionManager;

class AuthService
{
    private SessionManager $session;

    public function __construct(SessionManager $session)
    {
        $this->session = $session;
    }

    /**
     * Find-or-create pattern mirrored from hr-advws's AuthService::loginWithLine(),
     * swapping the external identity key from `line_id` to Google's `sub` claim.
     */
    public function findOrCreateByGoogle(string $sub, string $email, string $name, ?string $picture, string $hostedDomain): Users
    {
        $user = Users::findFirst([
            'conditions' => 'google_sub = :sub:',
            'bind' => ['sub' => $sub],
        ]);

        if ($user === null) {
            // Fall back to matching an existing row by email (e.g. a
            // pre-seeded account bootstrapped before its first real Google
            // login) so the first login links google_sub instead of
            // colliding with users.uq_users_email on insert.
            $user = Users::findFirst([
                'conditions' => 'email = :email:',
                'bind' => ['email' => $email],
            ]);
        }

        if ($user === null) {
            $user = new Users();
            $user->role = 'viewer';
        }

        $user->google_sub = $sub;
        $user->email = $email;
        $user->name = $name;
        $user->avatar_url = $picture;
        $user->hosted_domain = $hostedDomain;
        $user->is_active = 1;
        $user->last_login_at = date('Y-m-d H:i:s');
        $user->save();

        return $user;
    }

    public function login(Users $user): void
    {
        $this->session->set('user_id', $user->id);
    }

    public function logout(): void
    {
        $this->session->remove('user_id');
        $this->session->destroy();
    }

    public function currentUser(): ?Users
    {
        $userId = $this->session->get('user_id');
        return $userId !== null ? Users::findFirst((int) $userId) : null;
    }
}
