<?php

namespace App\Controllers;

class ErrorController extends ControllerBase
{
    public function notFoundAction(): void
    {
        $this->view->disable();
        $this->response->setStatusCode(404);
        $this->response->setContent('404 Not Found');
    }
}
