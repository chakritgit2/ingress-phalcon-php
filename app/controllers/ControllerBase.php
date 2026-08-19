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

    /**
     * Built in memory rather than streamed — row counts stay small for an
     * internal tool, and returning a Response object (like
     * IngressController::deploymentsApiAction() already does for JSON) lets
     * Phalcon skip view rendering automatically, no $this->view->disable()
     * needed.
     */
    protected function csvResponse(string $filename, array $header, iterable $rows)
    {
        $out = fopen('php://memory', 'r+');
        fputcsv($out, $header);
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        $this->response->setContentType('text/csv', 'UTF-8');
        $this->response->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $this->response->setContent($csv);

        return $this->response;
    }
}
