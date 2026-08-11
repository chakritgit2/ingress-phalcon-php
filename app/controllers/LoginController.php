<?php

namespace App\Controllers;

use App\Models\Users;

class LoginController extends ControllerBase
{
    public function indexAction()
    {
        if ($this->currentUser() !== null) {
            return $this->response->redirect('/ingress');
        }
    }

    /**
     * DEV-ONLY bypass of the real Google OAuth flow, for local UI preview
     * without a configured Google OAuth client. Only active when
     * APP_ENV=local (default: disabled, returns 404) — must never be
     * reachable in a real deployment. Remove before shipping.
     */
    public function mockLoginAction()
    {
        if (getenv('APP_ENV') !== 'local') {
            return $this->response->setStatusCode(404);
        }

        $email = $this->request->getQuery('email', 'string', 'pannawat@advws.com');
        $user = Users::findFirst([
            'conditions' => 'email = :email:',
            'bind' => ['email' => $email],
        ]);

        if ($user === null) {
            $this->flash->error("mock user not found: {$email}");
            return $this->response->redirect('/login');
        }

        $this->authService->login($user);
        return $this->response->redirect('/ingress');
    }

    public function googleAction()
    {
        $state = bin2hex(random_bytes(16));
        $this->session->set('oauth_state', $state);

        return $this->response->redirect($this->googleAuthService->getAuthUrl($state));
    }

    public function googleCallbackAction()
    {
        $state = $this->request->getQuery('state');
        $code = $this->request->getQuery('code');
        $expectedState = $this->session->get('oauth_state');
        $this->session->remove('oauth_state');

        if (!$code || !$state || !$expectedState || !hash_equals($expectedState, $state)) {
            $this->flash->error('การเข้าสู่ระบบล้มเหลว (state ไม่ถูกต้อง) กรุณาลองใหม่');
            return $this->response->redirect('/login');
        }

        try {
            $token = $this->googleAuthService->exchangeCode($code);
            $claims = $this->googleAuthService->verifyIdTokenAndGetClaims($token['id_token']);
        } catch (\Throwable $e) {
            $this->flash->error('การเข้าสู่ระบบล้มเหลว: ไม่สามารถยืนยันตัวตนกับ Google ได้');
            return $this->response->redirect('/login');
        }

        if ($claims === null) {
            $this->flash->error('การเข้าสู่ระบบล้มเหลว: ID token ไม่ถูกต้อง');
            return $this->response->redirect('/login');
        }

        if (!$this->googleAuthService->isAllowedHostedDomain($claims)) {
            $this->auditLogService->log('login_rejected', $claims['email'] ?? 'unknown', [
                'detail' => ['reason' => 'hosted_domain_mismatch', 'hd' => $claims['hd'] ?? null],
            ]);
            $this->flash->error('อนุญาตเฉพาะบัญชี Google ของ advws.com เท่านั้น');
            return $this->response->redirect('/login');
        }

        $user = $this->authService->findOrCreateByGoogle(
            $claims['sub'],
            $claims['email'],
            $claims['name'] ?? $claims['email'],
            $claims['picture'] ?? null,
            $claims['hd']
        );

        $this->authService->login($user);

        $this->auditLogService->log('login', $user->email, [
            'actor_user_id' => $user->id,
        ]);

        return $this->response->redirect('/ingress');
    }

    public function logoutAction()
    {
        $user = $this->currentUser();
        if ($user !== null) {
            $this->auditLogService->log('login', $user->email, [
                'actor_user_id' => $user->id,
                'detail' => ['event' => 'logout'],
            ]);
        }

        $this->authService->logout();
        return $this->response->redirect('/login');
    }
}
