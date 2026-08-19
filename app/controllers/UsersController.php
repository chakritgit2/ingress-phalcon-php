<?php

namespace App\Controllers;

use App\Models\Users;

class UsersController extends ControllerBase
{
    public function indexAction(): void
    {
        $rows = Users::find(['order' => 'role DESC, email ASC']);
        $this->view->setVar('rows', $rows);
    }

    public function editAction($id): void
    {
        $target = Users::findFirst((int) $id);

        if ($target === null) {
            $this->flash->error('ไม่พบผู้ใช้');
            $this->response->redirect('/users');
            return;
        }

        $this->view->setVar('target', $target);
    }

    public function updateEmailAction($id)
    {
        if (!$this->request->isPost() || !$this->security->checkToken()) {
            $this->flash->error('คำขอไม่ถูกต้อง (CSRF)');
            return $this->response->redirect('/users');
        }

        $target = Users::findFirst((int) $id);

        if ($target === null) {
            $this->flash->error('ไม่พบผู้ใช้');
            return $this->response->redirect('/users');
        }

        try {
            $this->usersService->updateEmail($target, $this->request->getPost('email', 'string'), $this->currentUser());
            $this->flash->success('เปลี่ยนอีเมลแล้ว');
        } catch (\Throwable $e) {
            $this->flash->error($e->getMessage());
        }

        return $this->response->redirect('/users/' . $target->id . '/edit');
    }

    public function updateRoleAction($id)
    {
        if (!$this->request->isPost() || !$this->security->checkToken()) {
            $this->flash->error('คำขอไม่ถูกต้อง (CSRF)');
            return $this->response->redirect('/users');
        }

        $target = Users::findFirst((int) $id);

        if ($target === null) {
            $this->flash->error('ไม่พบผู้ใช้');
            return $this->response->redirect('/users');
        }

        try {
            $this->usersService->setRole($target, $this->request->getPost('role', 'string'), $this->currentUser());
            $this->flash->success('เปลี่ยน role แล้ว');
        } catch (\Throwable $e) {
            $this->flash->error($e->getMessage());
        }

        return $this->response->redirect('/users/' . $target->id . '/edit');
    }

    public function toggleActiveAction($id)
    {
        if (!$this->request->isPost() || !$this->security->checkToken()) {
            $this->flash->error('คำขอไม่ถูกต้อง (CSRF)');
            return $this->response->redirect('/users');
        }

        $target = Users::findFirst((int) $id);

        if ($target === null) {
            $this->flash->error('ไม่พบผู้ใช้');
            return $this->response->redirect('/users');
        }

        $newActive = !$target->is_active;

        try {
            $this->usersService->setActive($target, $newActive, $this->currentUser());
            $this->flash->success($newActive ? 'เปิดการใช้งานแล้ว' : 'ปิดการใช้งานแล้ว');
        } catch (\Throwable $e) {
            $this->flash->error($e->getMessage());
        }

        return $this->response->redirect('/users/' . $target->id . '/edit');
    }

    public function resetPasswordAction($id)
    {
        if (!$this->request->isPost() || !$this->security->checkToken()) {
            $this->flash->error('คำขอไม่ถูกต้อง (CSRF)');
            return $this->response->redirect('/users');
        }

        $target = Users::findFirst((int) $id);

        if ($target === null) {
            $this->flash->error('ไม่พบผู้ใช้');
            return $this->response->redirect('/users');
        }

        $password = $this->request->getPost('password', 'string', '');
        $passwordConfirm = $this->request->getPost('password_confirm', 'string', '');

        if ($password !== '' && $password !== $passwordConfirm) {
            $this->flash->error('รหัสผ่านที่ยืนยันไม่ตรงกัน');
            return $this->response->redirect('/users/' . $target->id . '/edit');
        }

        try {
            $newPassword = $this->usersService->resetPassword($target, $this->currentUser(), $password !== '' ? $password : null);
            $this->flash->success("ตั้งรหัสผ่านของ {$target->email} แล้ว — รหัสผ่าน: {$newPassword} (คัดลอกและส่งให้ผู้ใช้ทันที จะไม่แสดงซ้ำอีก)");
        } catch (\Throwable $e) {
            $this->flash->error($e->getMessage());
        }

        return $this->response->redirect('/users/' . $target->id . '/edit');
    }
}
