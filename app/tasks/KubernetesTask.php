<?php

namespace App\Tasks;

use App\Models\IngressRequests;
use App\Models\K8sCommands;
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
                            $row->id
                        );
                        $row->service_name = $created['service_name'];
                        $row->ingress_name = $created['ingress_name'];
                    } else {
                        $created = $this->kubernetesService->createNodePortService(
                            $row->namespace,
                            $row->deployment_name,
                            $row->target_port,
                            $row->id
                        );
                        $row->service_name = $created['service_name'];
                        $row->node_port = $created['node_port'];
                    }

                    $row->k8s_uid = $created['k8s_uid'];
                    $row->status = 'active';
                    $row->expires_at = date('Y-m-d H:i:s', time() + $row->schedule_end_minutes * 60);
                    $row->save();

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

    public function pruneExpiredAction(): void
    {
        if (!$this->settingsService->isBotEnabled()) {
            echo "skip     bot is disabled\n";
            return;
        }

        $rows = IngressRequests::find([
            'conditions' => 'status = :status: AND expires_at <= :now:',
            'bind' => ['status' => 'active', 'now' => date('Y-m-d H:i:s')],
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

                $row->status = 'expired';
                $row->deleted_at = date('Y-m-d H:i:s');
                $row->deleted_by = 'sweeper';
                $row->save();

                $this->auditLogService->log('ingress_delete', 'system:sweeper', [
                    'ingress_request_id' => $row->id,
                    'namespace' => $row->namespace,
                    'deployment_name' => $row->deployment_name,
                    'node_port' => $row->node_port,
                    'node_ip' => $row->node_ip,
                ]);

                $this->revertNodeAdminPathEnvIfUnused($row, 'system:sweeper');

                echo "expired  {$row->namespace}/{$row->service_name} (id={$row->id})\n";
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
}
