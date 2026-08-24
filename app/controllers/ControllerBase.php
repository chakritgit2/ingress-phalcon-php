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

    /**
     * Page numbers for partials/pagination.volt to render: always the first
     * and last page, plus a window around the current page, with 0 marking
     * a collapsed gap (rendered as "…") — keeps the control usable when
     * there are hundreds of pages instead of dumping every number in the DOM.
     */
    protected function paginationPages(int $page, int $totalPages, int $window = 2): array
    {
        $pages = [];
        for ($p = 1; $p <= $totalPages; $p++) {
            if ($p === 1 || $p === $totalPages || ($p >= $page - $window && $p <= $page + $window)) {
                $pages[] = $p;
            } elseif (end($pages) !== 0) {
                $pages[] = 0;
            }
        }
        return $pages;
    }
}
