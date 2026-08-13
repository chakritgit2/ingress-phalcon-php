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
                    $lastRequest = $this->kubernetesService->getLastRequest();
                } catch (\Throwable $e) {
                    $lastRequest = null;
                }
                $command->request_payload = $lastRequest !== null ? json_encode($lastRequest) : null;
            }

            $command->processed_at = date('Y-m-d H:i:s');
            $command->save();
        }

        echo "processed {$count} command(s)\n";
    }

    public function pruneExpiredAction(): void
    {
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
}
