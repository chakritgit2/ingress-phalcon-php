<?php

namespace App\Tasks;

use App\Models\StatefulSetCommands;
use App\Models\StatefulSetRequests;
use App\Services\KubernetesApiException;
use Phalcon\Cli\Task;

/**
 * StatefulSet counterpart of KubernetesTask — same stateless-CLI-task design
 * (see that class's docblock), simplified: no NODE_ADMIN_PATH sync, no
 * LINE-Login sync, and no nightly business-hours close/reopen schedule (this
 * flow only ever has a hard TTL). Invoked via (the hyphen matters — Phalcon's
 * CLI dispatcher camelizes the task-name argument on '-'/'_' delimiters only,
 * so a plain "statefulset" would resolve to StatefulsetTask, not this class;
 * same reasoning behind StatefulSetController's 'stateful-set' controller key
 * in router.php):
 *   php app/console.php stateful-set processCommands
 *   php app/console.php stateful-set pruneExpired
 * Scheduled on the same CronJob as KubernetesTask (see k8s/cronjob.yaml).
 */
class StatefulSetTask extends Task
{
    /**
     * The only thing that actually calls the Kubernetes API for
     * user-triggered create/delete requests — StatefulSetRequestService only
     * ever enqueues a `statefulset_commands` row with status='pending'.
     */
    public function processCommandsAction(): void
    {
        if (!$this->settingsService->isBotEnabled()) {
            echo "skip     bot is disabled\n";
            return;
        }

        $commands = StatefulSetCommands::find([
            'conditions' => 'status = :status:',
            'bind' => ['status' => 'pending'],
            'order' => 'created_at ASC',
        ]);

        $count = 0;

        foreach ($commands as $command) {
            $count++;
            $row = $command->statefulSetRequest;
            $actorLabel = $command->requestedBy->email ?? 'system:unknown';

            try {
                $this->kubernetesService->resetRequestLog();

                if ($command->action === 'create') {
                    $created = $this->kubernetesService->createNodePortServiceForStatefulSet(
                        $row->namespace,
                        $row->statefulset_name,
                        $row->target_port,
                        $row->id
                    );
                    $row->service_name = $created['service_name'];
                    $row->node_port = $created['node_port'];
                    $row->k8s_uid = $created['k8s_uid'];
                    $row->status = 'active';
                    $row->expires_at = date('Y-m-d H:i:s', time() + $row->schedule_end_minutes * 60);
                    $row->save();

                    $command->result = json_encode($created);

                    $this->auditLogService->log('statefulset_create', $actorLabel, [
                        'statefulset_request_id' => $row->id,
                        'actor_user_id' => $command->requested_by_user_id,
                        'namespace' => $row->namespace,
                        'statefulset_name' => $row->statefulset_name,
                        'node_port' => $row->node_port,
                        'node_ip' => $row->node_ip,
                    ]);
                } else {
                    $this->kubernetesService->deleteService($row->namespace, $row->service_name);

                    $row->status = 'deleted';
                    $row->deleted_at = date('Y-m-d H:i:s');
                    $row->deleted_by = 'manual';
                    $row->save();

                    $this->auditLogService->log('statefulset_delete', $actorLabel, [
                        'statefulset_request_id' => $row->id,
                        'actor_user_id' => $command->requested_by_user_id,
                        'namespace' => $row->namespace,
                        'statefulset_name' => $row->statefulset_name,
                        'node_port' => $row->node_port,
                        'node_ip' => $row->node_ip,
                    ]);
                }

                $command->status = 'success';

                echo "done     {$command->action} {$row->namespace}/{$row->statefulset_name} (statefulset_request_id={$row->id})\n";
            } catch (\Throwable $e) {
                $command->status = 'failed';
                $command->error_message = $e->getMessage();

                $row->status = 'failed';
                $row->last_error = $e->getMessage();
                $row->save();

                $this->auditLogService->log(
                    $command->action === 'create' ? 'statefulset_create_failed' : 'statefulset_delete_failed',
                    $actorLabel,
                    [
                        'statefulset_request_id' => $row->id,
                        'actor_user_id' => $command->requested_by_user_id,
                        'namespace' => $row->namespace,
                        'statefulset_name' => $row->statefulset_name,
                        'detail' => ['error' => $e->getMessage()],
                    ]
                );

                fwrite(STDERR, "error    {$command->action} {$row->namespace}/{$row->statefulset_name} (statefulset_request_id={$row->id}): {$e->getMessage()}\n");
            } finally {
                try {
                    $requestLog = $this->kubernetesService->getRequestLog();
                } catch (\Throwable $e) {
                    $requestLog = [];
                }
                if (!empty($requestLog)) {
                    $command->request_payload = json_encode($requestLog, JSON_PRETTY_PRINT);
                    $command->payload_source = 'sent';
                }
            }

            $command->processed_at = date('Y-m-d H:i:s');
            $command->save();
        }

        echo "processed {$count} command(s)\n";
    }

    /**
     * Deletes the live Service for every row whose expires_at has passed —
     * this flow has no nightly-close state to account for, so unlike
     * KubernetesTask::pruneExpiredAction() there's no `was_closed` branch.
     */
    public function pruneExpiredAction(): void
    {
        if (!$this->settingsService->isBotEnabled()) {
            echo "skip     bot is disabled\n";
            return;
        }

        $rows = StatefulSetRequests::find([
            'conditions' => "status = 'active' AND expires_at <= :now:",
            'bind' => ['now' => date('Y-m-d H:i:s')],
        ]);

        $count = 0;

        foreach ($rows as $row) {
            $count++;

            try {
                $this->kubernetesService->deleteService($row->namespace, $row->service_name);

                $row->status = 'expired';
                $row->deleted_at = date('Y-m-d H:i:s');
                $row->deleted_by = 'sweeper';
                $row->save();

                $this->auditLogService->log('statefulset_delete', 'system:sweeper', [
                    'statefulset_request_id' => $row->id,
                    'namespace' => $row->namespace,
                    'statefulset_name' => $row->statefulset_name,
                    'node_port' => $row->node_port,
                    'node_ip' => $row->node_ip,
                ]);

                echo "expired  {$row->namespace}/{$row->service_name} (id={$row->id})\n";
            } catch (KubernetesApiException $e) {
                $row->last_error = $e->getMessage();
                $row->save();

                $this->auditLogService->log('statefulset_delete_failed', 'system:sweeper', [
                    'statefulset_request_id' => $row->id,
                    'namespace' => $row->namespace,
                    'statefulset_name' => $row->statefulset_name,
                    'detail' => ['error' => $e->getMessage()],
                ]);

                fwrite(STDERR, "error    {$row->namespace}/{$row->service_name} (id={$row->id}): {$e->getMessage()}\n");
            }
        }

        echo "processed {$count} expired row(s)\n";
    }
}
