<?php

namespace App\Tasks;

use App\Models\IngressRequests;
use App\Models\K8sCommands;
use App\Models\Users;
use App\Services\KubernetesApiException;
use Phalcon\Cli\Task;

/**
 * Invoked as: php app/console.php kubernetes pruneExpired
 * Scheduled via the ingress-selfservice-sweeper CronJob (see k8s/cronjob.yaml),
 * running every minute. Deliberately a stateless CLI task rather than an
 * in-process scheduler: the pending-expiration truth lives in MySQL
 * (ingress_requests.expires_at), so it survives Pod restarts/rolling deploys
 * without needing a long-running daemon.
 */
class KubernetesTask extends Task
{
    private const BUSINESS_OPEN_TIME = '08:30:00';
    private const BUSINESS_CLOSE_TIME = '19:00:00';

    /**
     * Invoked as: php app/console.php kubernetes processCommands
     * The only thing that actually calls the Kubernetes API for
     * user-triggered create/delete requests — IngressRequestService only
     * ever enqueues a `k8s_commands` row with status='pending'. This keeps
     * the web request from ever being the single point of failure between
     * "Kubernetes mutated" and "we wrote it down".
     */
    public function processCommandsAction(): void
    {
        if (!$this->settingsService->isBotEnabled()) {
            echo "skip     bot is disabled\n";
            return;
        }

        $commands = K8sCommands::find([
            'conditions' => 'status = :status:',
            'bind' => ['status' => 'pending'],
            'order' => 'created_at ASC',
        ]);

        $count = 0;

        foreach ($commands as $command) {
            $count++;
            $row = $command->ingressRequest;
            $actorLabel = $command->requestedBy->email ?? 'system:unknown';

            try {
                $this->kubernetesService->resetRequestLog();

                if ($command->action === 'create') {
                    if ($row->request_type === 'ingress') {
                        $created = $this->kubernetesService->createIngress(
                            $row->namespace,
                            $row->deployment_name,
                            $row->target_port,
                            $row->host,
                            $row->secret_name,
                            $row->id,
                            true,
                            (bool) $row->login_bypass
                        );
                        $row->service_name = $created['service_name'];
                        $row->ingress_name = $created['ingress_name'];
                    } else {
                        $created = $this->kubernetesService->createNodePortService(
                            $row->namespace,
                            $row->deployment_name,
                            $row->target_port,
                            $row->id,
                            null,
                            true,
                            (bool) $row->login_bypass
                        );
                        $row->service_name = $created['service_name'];
                        $row->node_port = $created['node_port'];
                    }

                    $row->k8s_uid = $created['k8s_uid'];
                    $row->status = 'active';
                    $row->expires_at = date('Y-m-d H:i:s', time() + $row->schedule_end_minutes * 60);
                    $row->save();

                    $this->syncLineLogin('activate', $row, $actorLabel, $command->requested_by_user_id);

                    $command->result = json_encode($created);

                    $this->auditLogService->log('ingress_create', $actorLabel, [
                        'ingress_request_id' => $row->id,
                        'actor_user_id' => $command->requested_by_user_id,
                        'namespace' => $row->namespace,
                        'deployment_name' => $row->deployment_name,
                        'node_port' => $row->node_port,
                        'node_ip' => $row->node_ip,
                        'detail' => ['host' => $row->host, 'secret_name' => $row->secret_name],
                    ]);

                    // Notifies (via the audit trail — this app has no
                    // email/Slack channel) whenever the target Deployment
                    // has no NODE_ADMIN_PATH env var to patch, or a patch
                    // attempt failed (e.g. missing RBAC) — the latter never
                    // fails the ingress_create itself, see
                    // KubernetesService::syncNodeAdminPathEnv(). Nothing
                    // extra is logged when it was already correct — no
                    // change happened.
                    if ($created['node_admin_path']['found'] === false) {
                        $this->auditLogService->log('node_admin_path_not_found', $actorLabel, [
                            'ingress_request_id' => $row->id,
                            'actor_user_id' => $command->requested_by_user_id,
                            'namespace' => $row->namespace,
                            'deployment_name' => $row->deployment_name,
                            'detail' => ['namespace' => $row->namespace, 'deployment_name' => $row->deployment_name],
                        ]);
                    } elseif (isset($created['node_admin_path']['error'])) {
                        $this->auditLogService->log('node_admin_path_patch_failed', $actorLabel, [
                            'ingress_request_id' => $row->id,
                            'actor_user_id' => $command->requested_by_user_id,
                            'namespace' => $row->namespace,
                            'deployment_name' => $row->deployment_name,
                            'detail' => [
                                'namespace' => $row->namespace,
                                'deployment_name' => $row->deployment_name,
                                'error' => $created['node_admin_path']['error'],
                            ],
                        ]);
                    } elseif ($created['node_admin_path']['patched'] === true) {
                        $this->auditLogService->log('node_admin_path_patched', $actorLabel, [
                            'ingress_request_id' => $row->id,
                            'actor_user_id' => $command->requested_by_user_id,
                            'namespace' => $row->namespace,
                            'deployment_name' => $row->deployment_name,
                            'detail' => ['namespace' => $row->namespace, 'deployment_name' => $row->deployment_name],
                        ]);
                    }

                    // Same non-fatal not-found/failed/patched logging as
                    // node_admin_path above, but only attempted (see
                    // createIngress()/createNodePortService()'s
                    // $manageLoginBypass arg) when this row's "Login Bypass"
                    // checkbox was actually checked — an ordinary request
                    // never touches NO_LINELOGIN, so there's nothing to log.
                    if ($row->login_bypass) {
                        if ($created['login_bypass']['found'] === false) {
                            $this->auditLogService->log('login_bypass_not_found', $actorLabel, [
                                'ingress_request_id' => $row->id,
                                'actor_user_id' => $command->requested_by_user_id,
                                'namespace' => $row->namespace,
                                'deployment_name' => $row->deployment_name,
                                'detail' => ['namespace' => $row->namespace, 'deployment_name' => $row->deployment_name],
                            ]);
                        } elseif (isset($created['login_bypass']['error'])) {
                            $this->auditLogService->log('login_bypass_patch_failed', $actorLabel, [
                                'ingress_request_id' => $row->id,
                                'actor_user_id' => $command->requested_by_user_id,
                                'namespace' => $row->namespace,
                                'deployment_name' => $row->deployment_name,
                                'detail' => [
                                    'namespace' => $row->namespace,
                                    'deployment_name' => $row->deployment_name,
                                    'error' => $created['login_bypass']['error'],
                                ],
                            ]);
                        } elseif ($created['login_bypass']['patched'] === true) {
                            $this->auditLogService->log('login_bypass_patched', $actorLabel, [
                                'ingress_request_id' => $row->id,
                                'actor_user_id' => $command->requested_by_user_id,
                                'namespace' => $row->namespace,
                                'deployment_name' => $row->deployment_name,
                                'detail' => ['namespace' => $row->namespace, 'deployment_name' => $row->deployment_name],
                            ]);
                        }
                    }
                } else {
                    if ($row->request_type === 'ingress') {
                        $this->kubernetesService->deleteIngress($row->namespace, $row->ingress_name, $row->service_name);
                    } else {
                        $this->kubernetesService->deleteService($row->namespace, $row->service_name);
                    }

                    $row->status = 'deleted';
                    $row->deleted_at = date('Y-m-d H:i:s');
                    $row->deleted_by = 'manual';
                    $row->save();

                    $this->syncLineLogin('deactivate', $row, $actorLabel, $command->requested_by_user_id);

                    $this->auditLogService->log('ingress_delete', $actorLabel, [
                        'ingress_request_id' => $row->id,
                        'actor_user_id' => $command->requested_by_user_id,
                        'namespace' => $row->namespace,
                        'deployment_name' => $row->deployment_name,
                        'node_port' => $row->node_port,
                        'node_ip' => $row->node_ip,
                        'detail' => ['host' => $row->host, 'secret_name' => $row->secret_name],
                    ]);

                    $this->revertNodeAdminPathEnvIfUnused($row, $actorLabel, $command->requested_by_user_id);

                    if ($row->login_bypass) {
                        $this->revertLoginBypassEnvIfUnused($row, $actorLabel, $command->requested_by_user_id);
                    }
                }

                $command->status = 'success';

                echo "done     {$command->action} {$row->namespace}/{$row->deployment_name} (ingress_request_id={$row->id})\n";
            } catch (\Throwable $e) {
                $command->status = 'failed';
                $command->error_message = $e->getMessage();

                $row->status = 'failed';
                $row->last_error = $e->getMessage();
                $row->save();

                $this->auditLogService->log(
                    $command->action === 'create' ? 'ingress_create_failed' : 'ingress_delete_failed',
                    $actorLabel,
                    [
                        'ingress_request_id' => $row->id,
                        'actor_user_id' => $command->requested_by_user_id,
                        'namespace' => $row->namespace,
                        'deployment_name' => $row->deployment_name,
                        'detail' => ['error' => $e->getMessage(), 'host' => $row->host, 'secret_name' => $row->secret_name],
                    ]
                );

                fwrite(STDERR, "error    {$command->action} {$row->namespace}/{$row->deployment_name} (ingress_request_id={$row->id}): {$e->getMessage()}\n");
            } finally {
                // Captured regardless of outcome — null if the call never
                // got far enough to actually send anything (e.g. the
                // pre-create Deployment lookup failed). Guarded because if
                // $this->kubernetesService itself failed to construct (e.g.
                // an unreadable/invalid SERVER_CONFIG), re-accessing it here
                // retries that same failing construction — without this
                // guard that crashes the whole batch instead of just
                // marking this one command failed.
                try {
                    $requestLog = $this->kubernetesService->getRequestLog();
                } catch (\Throwable $e) {
                    $requestLog = [];
                }
                if (!empty($requestLog)) {
                    // Overwrites whatever preview IngressRequestService::enqueue()
                    // stored at request time (see its docblock) with the
                    // literal request(s) actually sent — an ingress-type
                    // create/delete sends two (Service + Ingress), a
                    // nodeport-type one sends one.
                    $command->request_payload = json_encode($requestLog, JSON_PRETTY_PRINT);
                    $command->payload_source = 'sent';
                }
                // else: nothing was actually sent (e.g. Deployment lookup
                // failed before a body was ever built) — leave
                // request_payload/payload_source as whatever enqueue()
                // already set (a preview, or null if that failed too).
            }

            $command->processed_at = date('Y-m-d H:i:s');
            $command->save();
        }

        echo "processed {$count} command(s)\n";
    }

    /**
     * Matches 'active' AND 'closed' rows so the TTL always wins over the
     * nightly schedule (see scheduledCloseAction()/scheduledReopenAction()):
     * a row nightly-closed for the evening still has to hard-expire once its
     * own expires_at passes, even though there's no live k8s object left to
     * delete for it.
     */
    public function pruneExpiredAction(): void
    {
        if (!$this->settingsService->isBotEnabled()) {
            echo "skip     bot is disabled\n";
            return;
        }

        $rows = IngressRequests::find([
            'conditions' => "status IN ('active', 'closed') AND expires_at <= :now:",
            'bind' => ['now' => date('Y-m-d H:i:s')],
        ]);

        $count = 0;

        foreach ($rows as $row) {
            $count++;
            $wasClosed = $row->status === 'closed';

            try {
                // A 'closed' row's Service/Ingress was already deleted by the
                // last nightly close — nothing left on the cluster to delete.
                if (!$wasClosed) {
                    if ($row->request_type === 'ingress') {
                        $this->kubernetesService->deleteIngress($row->namespace, $row->ingress_name, $row->service_name);
                    } else {
                        $this->kubernetesService->deleteService($row->namespace, $row->service_name);
                    }
                }

                $row->status = 'expired';
                $row->deleted_at = date('Y-m-d H:i:s');
                $row->deleted_by = 'sweeper';
                $row->schedule_hold_open_until = null;
                $row->schedule_reopen_requested_at = null;
                $row->schedule_reopen_requested_by_user_id = null;
                $row->save();

                $this->syncLineLogin('deactivate', $row, 'system:sweeper');

                $this->auditLogService->log('ingress_delete', 'system:sweeper', [
                    'ingress_request_id' => $row->id,
                    'namespace' => $row->namespace,
                    'deployment_name' => $row->deployment_name,
                    'node_port' => $row->node_port,
                    'node_ip' => $row->node_ip,
                    'detail' => ['was_closed' => $wasClosed],
                ]);

                // This IS the true final deletion, even for a row the
                // nightly close left in 'closed' (which deliberately skips
                // this revert) — revert NODE_ADMIN_PATH here unconditionally.
                $this->revertNodeAdminPathEnvIfUnused($row, 'system:sweeper');

                if ($row->login_bypass) {
                    $this->revertLoginBypassEnvIfUnused($row, 'system:sweeper');
                }

                echo "expired  {$row->namespace}/{$row->service_name} (id={$row->id}, was_closed=" . ($wasClosed ? '1' : '0') . ")\n";
            } catch (KubernetesApiException $e) {
                $row->last_error = $e->getMessage();
                $row->save();

                $this->auditLogService->log('ingress_delete_failed', 'system:sweeper', [
                    'ingress_request_id' => $row->id,
                    'namespace' => $row->namespace,
                    'deployment_name' => $row->deployment_name,
                    'detail' => ['error' => $e->getMessage()],
                ]);

                fwrite(STDERR, "error    {$row->namespace}/{$row->service_name} (id={$row->id}): {$e->getMessage()}\n");
            }
        }

        echo "processed {$count} expired row(s)\n";
    }

    /**
     * Invoked as: php app/console.php kubernetes scheduledClose
     * Closes every 'active' row once local time falls outside business
     * hours ([08:30, 19:00)) — every day, weekends included (only the
     * *reopen* side is weekday-gated, see scheduledReopenAction()).
     * Idempotent via the status column alone: a row that already
     * transitioned to 'closed' no longer matches status='active', so
     * re-running this every minute needs no extra "already handled" flag.
     * Deliberately does NOT call revertNodeAdminPathEnvIfUnused() — this is
     * a nightly pause, not a final delete, and patching that env var would
     * restart the target Deployment's pods for no reason twice a day.
     */
    public function scheduledCloseAction(): void
    {
        if (!$this->settingsService->isBotEnabled()) {
            echo "skip     bot is disabled\n";
            return;
        }

        $now = date('Y-m-d H:i:s');
        $timeOfDay = date('H:i:s');
        $outsideBusinessHours = $timeOfDay >= self::BUSINESS_CLOSE_TIME || $timeOfDay < self::BUSINESS_OPEN_TIME;

        if (!$outsideBusinessHours) {
            echo "skip     within business hours ({$timeOfDay})\n";
            return;
        }

        $rows = IngressRequests::find([
            'conditions' => "status = 'active'
                AND (expires_at IS NULL OR expires_at > :now:)
                AND (schedule_hold_open_until IS NULL OR schedule_hold_open_until <= :now2:)",
            'bind' => ['now' => $now, 'now2' => $now],
        ]);

        $count = 0;

        foreach ($rows as $row) {
            $count++;

            try {
                if ($row->request_type === 'ingress') {
                    $this->kubernetesService->deleteIngress($row->namespace, $row->ingress_name, $row->service_name);
                } else {
                    $this->kubernetesService->deleteService($row->namespace, $row->service_name);
                }

                $row->status = 'closed';
                $row->schedule_closed_at = $now;
                $row->schedule_hold_open_until = null;
                $row->last_error = null;
                $row->save();

                $this->syncLineLogin('deactivate', $row, 'system:scheduler');

                $this->auditLogService->log('ingress_scheduled_close', 'system:scheduler', [
                    'ingress_request_id' => $row->id,
                    'namespace' => $row->namespace,
                    'deployment_name' => $row->deployment_name,
                    'node_port' => $row->node_port,
                    'node_ip' => $row->node_ip,
                    'detail' => ['request_type' => $row->request_type, 'host' => $row->host],
                ]);

                echo "closed   {$row->namespace}/{$row->service_name} (id={$row->id})\n";
            } catch (\Throwable $e) {
                // Left 'active' (not 'failed' — that means "needs manual
                // retry" elsewhere in this app) so the next minute's tick
                // retries automatically.
                $row->last_error = $e->getMessage();
                $row->save();

                $this->auditLogService->log('ingress_scheduled_close_failed', 'system:scheduler', [
                    'ingress_request_id' => $row->id,
                    'namespace' => $row->namespace,
                    'deployment_name' => $row->deployment_name,
                    'detail' => ['error' => $e->getMessage()],
                ]);

                fwrite(STDERR, "error    close {$row->namespace}/{$row->service_name} (id={$row->id}): {$e->getMessage()}\n");
            }
        }

        echo "processed {$count} row(s) for scheduled close\n";
    }

    /**
     * Invoked as: php app/console.php kubernetes scheduledReopen
     * Auto-reopens 'closed' rows Mon-Fri within [08:30, 19:00) local time.
     * Outside that auto-eligible window (weekends, or before 08:30/after
     * 19:00 on a weekday), only rows with an explicit manual reopen request
     * (schedule_reopen_requested_at) qualify — both the automatic weekday
     * reopen and the manual "open now" action go through this exact same
     * code path, so the preferred-nodePort and no-env-patch logic exists in
     * exactly one place.
     */
    public function scheduledReopenAction(): void
    {
        if (!$this->settingsService->isBotEnabled()) {
            echo "skip     bot is disabled\n";
            return;
        }

        $now = date('Y-m-d H:i:s');
        $timeOfDay = date('H:i:s');
        $isWeekday = (int) date('N') <= 5; // ISO-8601: 1=Mon ... 7=Sun
        $withinBusinessHours = $timeOfDay >= self::BUSINESS_OPEN_TIME && $timeOfDay < self::BUSINESS_CLOSE_TIME;
        $autoEligible = $isWeekday && $withinBusinessHours;

        $conditions = "status = 'closed' AND (expires_at IS NULL OR expires_at > :now:)";
        if (!$autoEligible) {
            $conditions .= ' AND schedule_reopen_requested_at IS NOT NULL';
        }

        $rows = IngressRequests::find(['conditions' => $conditions, 'bind' => ['now' => $now]]);

        $count = 0;

        foreach ($rows as $row) {
            $count++;
            $isManual = $row->schedule_reopen_requested_at !== null;
            $requestedByUserId = $row->schedule_reopen_requested_by_user_id;
            $priorNodePort = $row->node_port;

            try {
                if ($row->request_type === 'ingress') {
                    $created = $this->kubernetesService->createIngress(
                        $row->namespace,
                        $row->deployment_name,
                        $row->target_port,
                        $row->host,
                        $row->secret_name,
                        $row->id,
                        false,
                        false
                    );
                    $row->service_name = $created['service_name'];
                    $row->ingress_name = $created['ingress_name'];
                } else {
                    $created = $this->kubernetesService->createNodePortService(
                        $row->namespace,
                        $row->deployment_name,
                        $row->target_port,
                        $row->id,
                        $priorNodePort,
                        false,
                        false
                    );
                    $row->service_name = $created['service_name'];
                    $row->node_port = $created['node_port'];
                }

                $row->k8s_uid = $created['k8s_uid'];
                $row->status = 'active';
                $row->schedule_closed_at = null;
                $row->schedule_reopen_requested_at = null;
                $row->schedule_reopen_requested_by_user_id = null;
                $row->last_error = null;
                // expires_at deliberately untouched — TTL keeps ticking.
                $row->save();

                // $requestedByUserId was captured before $row->save() cleared
                // schedule_reopen_requested_by_user_id above — looking the
                // user up fresh here (rather than via the $row->scheduleReopenRequestedBy
                // lazy relation, which would now resolve against the
                // already-nulled FK) is what actually finds them.
                $actorLabel = $isManual
                    ? (($requestedByUserId !== null ? Users::findFirst($requestedByUserId) : null)?->email ?? 'system:unknown')
                    : 'system:scheduler';

                $this->syncLineLogin('activate', $row, $actorLabel, $isManual ? $requestedByUserId : null);

                $this->auditLogService->log('ingress_scheduled_reopen', $actorLabel, [
                    'ingress_request_id' => $row->id,
                    'actor_user_id' => $isManual ? $requestedByUserId : null,
                    'namespace' => $row->namespace,
                    'deployment_name' => $row->deployment_name,
                    'node_port' => $row->node_port,
                    'node_ip' => $row->node_ip,
                    'detail' => [
                        'manual' => $isManual,
                        'node_port_preserved' => $row->request_type === 'nodeport'
                            ? ($priorNodePort !== null && $priorNodePort === $row->node_port)
                            : null,
                    ],
                ]);

                echo "reopened {$row->namespace}/{$row->service_name} (id={$row->id}, manual=" . ($isManual ? '1' : '0') . ")\n";
            } catch (\Throwable $e) {
                // Left 'closed' (not 'failed') — retried automatically on
                // the next eligible tick, same reasoning as
                // scheduledCloseAction().
                $row->last_error = $e->getMessage();
                $row->save();

                $this->auditLogService->log('ingress_scheduled_reopen_failed', 'system:scheduler', [
                    'ingress_request_id' => $row->id,
                    'namespace' => $row->namespace,
                    'deployment_name' => $row->deployment_name,
                    'detail' => ['error' => $e->getMessage(), 'manual' => $isManual],
                ]);

                fwrite(STDERR, "error    reopen {$row->namespace}/{$row->service_name} (id={$row->id}): {$e->getMessage()}\n");
            }
        }

        echo "processed {$count} row(s) for scheduled reopen\n";
    }

    /**
     * Registers/deactivates $row->host in the external line_login
     * collection (see LineLoginService). Never fails the create/delete
     * that already succeeded on the cluster — a Mongo hiccup is logged as
     * its own audit event instead, same as node_admin_path's non-fatal
     * pattern below. Skipped entirely for the null host historical
     * NodePort rows created before host became mandatory.
     */
    private function syncLineLogin(string $action, IngressRequests $row, string $actorLabel, ?int $actorUserId = null): void
    {
        if ($row->host === null) {
            return;
        }

        try {
            if ($action === 'activate') {
                $this->lineLoginService->activate($row->host);
            } else {
                $this->lineLoginService->deactivate($row->host);
            }
        } catch (\Throwable $e) {
            $this->auditLogService->log('line_login_sync_failed', $actorLabel, [
                'ingress_request_id' => $row->id,
                'actor_user_id' => $actorUserId,
                'namespace' => $row->namespace,
                'deployment_name' => $row->deployment_name,
                'detail' => ['action' => $action, 'host' => $row->host, 'error' => $e->getMessage()],
            ]);
        }
    }

    /**
     * Called right after a `delete` (manual or expiry-sweeper) succeeds, to
     * put NODE_ADMIN_PATH back to its original value on $row's Deployment —
     * counterpart to the patch createIngress()/createNodePortService() apply
     * on create. Skips entirely (no revert, no log) if another `active`
     * request still targets the same namespace+deployment: that Deployment's
     * NODE_ADMIN_PATH is still genuinely in use, reverting it here would
     * break that other request out from under it.
     */
    private function revertNodeAdminPathEnvIfUnused(IngressRequests $row, string $actorLabel, ?int $actorUserId = null): void
    {
        $stillInUse = IngressRequests::count([
            'conditions' => 'namespace = :namespace: AND deployment_name = :deployment_name: AND status = :status: AND id != :id:',
            'bind' => [
                'namespace' => $row->namespace,
                'deployment_name' => $row->deployment_name,
                'status' => 'active',
                'id' => $row->id,
            ],
        ]) > 0;

        if ($stillInUse) {
            return;
        }

        $reverted = $this->kubernetesService->revertNodeAdminPathEnv($row->namespace, $row->deployment_name);

        $context = [
            'ingress_request_id' => $row->id,
            'actor_user_id' => $actorUserId,
            'namespace' => $row->namespace,
            'deployment_name' => $row->deployment_name,
        ];

        if ($reverted['found'] === false) {
            $this->auditLogService->log('node_admin_path_revert_not_found', $actorLabel, $context + [
                'detail' => ['namespace' => $row->namespace, 'deployment_name' => $row->deployment_name],
            ]);
        } elseif (isset($reverted['error'])) {
            $this->auditLogService->log('node_admin_path_revert_failed', $actorLabel, $context + [
                'detail' => [
                    'namespace' => $row->namespace,
                    'deployment_name' => $row->deployment_name,
                    'error' => $reverted['error'],
                ],
            ]);
        } elseif ($reverted['reverted'] === true) {
            $this->auditLogService->log('node_admin_path_reverted', $actorLabel, $context + [
                'detail' => ['namespace' => $row->namespace, 'deployment_name' => $row->deployment_name],
            ]);
        }
    }

    /**
     * Counterpart to revertNodeAdminPathEnvIfUnused() for NO_LINELOGIN —
     * only ever called when $row->login_bypass is true (see call sites in
     * processCommandsAction()/pruneExpiredAction()). The "still in use"
     * guard additionally requires the other active row to itself have
     * login_bypass set: a plain (non-bypassed) active request on the same
     * Deployment doesn't need NO_LINELOGIN kept on.
     */
    private function revertLoginBypassEnvIfUnused(IngressRequests $row, string $actorLabel, ?int $actorUserId = null): void
    {
        $stillInUse = IngressRequests::count([
            'conditions' => 'namespace = :namespace: AND deployment_name = :deployment_name: AND status = :status: AND login_bypass = 1 AND id != :id:',
            'bind' => [
                'namespace' => $row->namespace,
                'deployment_name' => $row->deployment_name,
                'status' => 'active',
                'id' => $row->id,
            ],
        ]) > 0;

        if ($stillInUse) {
            return;
        }

        $reverted = $this->kubernetesService->revertLoginBypassEnv($row->namespace, $row->deployment_name);

        $context = [
            'ingress_request_id' => $row->id,
            'actor_user_id' => $actorUserId,
            'namespace' => $row->namespace,
            'deployment_name' => $row->deployment_name,
        ];

        if ($reverted['found'] === false) {
            $this->auditLogService->log('login_bypass_revert_not_found', $actorLabel, $context + [
                'detail' => ['namespace' => $row->namespace, 'deployment_name' => $row->deployment_name],
            ]);
        } elseif (isset($reverted['error'])) {
            $this->auditLogService->log('login_bypass_revert_failed', $actorLabel, $context + [
                'detail' => [
                    'namespace' => $row->namespace,
                    'deployment_name' => $row->deployment_name,
                    'error' => $reverted['error'],
                ],
            ]);
        } elseif ($reverted['reverted'] === true) {
            $this->auditLogService->log('login_bypass_reverted', $actorLabel, $context + [
                'detail' => ['namespace' => $row->namespace, 'deployment_name' => $row->deployment_name],
            ]);
        }
    }
}
