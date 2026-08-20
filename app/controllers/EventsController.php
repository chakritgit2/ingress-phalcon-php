<?php

namespace App\Controllers;

use App\Models\AuditLog;

/**
 * Server-Sent Events endpoint the browser keeps an EventSource open against
 * from /ingress and /audit/security, so their tables pick up status changes
 * (ingress_requests moving pending -> active -> deleted/expired/failed, new
 * audit_log rows) without a manual refresh.
 *
 * No message broker in this stack (cache is a filesystem Stream adapter, not
 * Redis) so this is DB-polling dressed as a push: each open connection loops
 * server-side, checking audit_log for rows newer than the last one it saw,
 * and only sends the client a "something changed, go re-fetch" ping — every
 * meaningful ingress_requests state change already gets audit-logged (see
 * AuditLogService::log() call sites), so watching audit_log's id covers
 * both channels without needing an updated_at column on ingress_requests.
 */
class EventsController extends ControllerBase
{
    private const SECURITY_EVENT_TYPES = [
        'login', 'login_rejected', 'bot_enabled', 'bot_disabled',
        'user_role_changed', 'user_activated', 'user_deactivated',
        'user_password_reset', 'user_email_changed',
    ];

    // Kept short and reconnected by the browser's own EventSource retry (via
    // Last-Event-ID) rather than held open indefinitely, so this never runs
    // into a php-fpm/proxy idle-timeout kill mid-stream.
    private const MAX_STREAM_SECONDS = 25;
    private const POLL_INTERVAL_MICROSECONDS = 2_000_000;

    public function streamAction(): void
    {
        $this->view->disable();
        set_time_limit(0);

        // This connection stays open for up to MAX_STREAM_SECONDS. Native
        // PHP sessions lock their file for the request's lifetime, which
        // would otherwise freeze every other tab/request for this browser
        // (clicking delete/retry while this stream is open) until it ends.
        session_write_close();

        $this->response->setHeader('Content-Type', 'text/event-stream');
        $this->response->setHeader('Cache-Control', 'no-cache');
        $this->response->setHeader('Connection', 'keep-alive');
        // Tells nginx (see docker/nginx.conf, k8s/nginx-configmap.yaml) not
        // to buffer this response into one chunk at the end.
        $this->response->setHeader('X-Accel-Buffering', 'no');
        $this->response->sendHeaders();

        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        $channel = (string) $this->request->getQuery('channel', 'string', 'ingress');
        $lastId = $this->resolveLastId();
        $deadline = time() + self::MAX_STREAM_SECONDS;

        while (time() < $deadline) {
            if (connection_aborted()) {
                return;
            }

            $conditions = 'id > :lastId:';
            $bind = ['lastId' => $lastId];

            if ($channel === 'security') {
                $conditions .= ' AND event_type IN ({eventTypes:array})';
                $bind['eventTypes'] = self::SECURITY_EVENT_TYPES;
            }

            $events = AuditLog::find([
                'conditions' => $conditions,
                'bind' => $bind,
                'order' => 'id ASC',
            ]);

            foreach ($events as $event) {
                $lastId = (int) $event->id;
                echo "id: {$lastId}\n";
                echo "event: update\n";
                echo "data: {}\n\n";
            }

            echo ": ping\n\n"; // comment line — keeps intermediate proxies from treating the connection as idle
            flush();

            usleep(self::POLL_INTERVAL_MICROSECONDS);
        }
    }

    private function resolveLastId(): int
    {
        $lastEventId = $_SERVER['HTTP_LAST_EVENT_ID'] ?? null;
        if ($lastEventId !== null && $lastEventId !== '') {
            return (int) $lastEventId;
        }

        $fromQuery = (int) $this->request->getQuery('after', 'int', 0);
        if ($fromQuery > 0) {
            return $fromQuery;
        }

        return (int) (AuditLog::maximum(['column' => 'id']) ?? 0);
    }
}
