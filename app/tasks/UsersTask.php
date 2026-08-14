<?php

namespace App\Tasks;

use App\Models\Users;
use Phalcon\Cli\Task;

/**
 * Invoked as: php app/console.php users setPassword <email> <password> [role]
 * Bootstraps or resets username/password credentials for a user. There is no
 * self-service signup — accounts otherwise only get created via Google SSO
 * first-login (see AuthService::findOrCreateByGoogle()) — so this is also
 * how the very first password-login account gets created.
 */
class UsersTask extends Task
{
    private const ROLES = ['devops', 'viewer'];

    public function setPasswordAction(?string $email = null, ?string $password = null, ?string $role = null): void
    {
        if (!$email || !$password) {
            fwrite(STDERR, "usage: php app/console.php users setPassword <email> <password> [role]\n");
            exit(1);
        }

        if (strlen($password) < 8) {
            fwrite(STDERR, "error: password must be at least 8 characters\n");
            exit(1);
        }

        if ($role !== null && !in_array($role, self::ROLES, true)) {
            fwrite(STDERR, "error: role must be 'devops' or 'viewer'\n");
            exit(1);
        }

        $user = Users::findFirst([
            'conditions' => 'email = :email:',
            'bind' => ['email' => $email],
        ]);

        if ($user === null) {
            $user = new Users();
            $user->email = $email;
            $user->name = $email;
            $user->role = $role ?? 'viewer';
            $user->is_active = 1;
        } elseif ($role !== null) {
            $user->role = $role;
        }

        $user->password_hash = password_hash($password, PASSWORD_BCRYPT);
        $user->save();

        echo "password set for {$email} (role={$user->role})\n";
    }
}
