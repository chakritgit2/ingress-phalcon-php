<?php

namespace App\Controllers;

use App\Models\Users;
use Phalcon\Mvc\Controller;

class ControllerBase extends Controller
{
    protected function currentUser(): ?Users
    {
        return $this->authService->currentUser();
    }

    protected function initialize(): void
    {
        $this->view->setVar('currentUser', $this->currentUser());
    }
}
