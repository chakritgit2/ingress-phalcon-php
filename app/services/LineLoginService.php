<?php

namespace App\Services;

use MongoDB\Database;
use MongoDB\Driver\Exception\BulkWriteException;

/**
 * Keeps the external LINE-login gateway's `line_login` collection (a
 * legacy hand-rolled schema with sequential integer `_id`s, not
 * MongoDB's default ObjectId) in sync with this app's provisioned
 * ingress hosts. `uniquekey` is the ingress's `host` — every other field
 * is a fixed default matching the format this collection already uses.
 */
class LineLoginService
{
    private const ORG = 1;
    private const ALLOWGROUP = [41];
    private const ALLOWROBOT = [1];
    private const SESSIONTIME = 720;
    private const MAX_INSERT_ATTEMPTS = 5;

    private \MongoDB\Collection $collection;

    public function __construct(Database $mongo)
    {
        $this->collection = $mongo->selectCollection('line_login');
    }

    public function activate(string $host): void
    {
        $now = time();

        $result = $this->collection->updateOne(
            ['uniquekey' => $host],
            ['$set' => [
                'ORG' => self::ORG,
                'active' => true,
                'status' => 1,
                'allowgroup' => self::ALLOWGROUP,
                'allowrobot' => self::ALLOWROBOT,
                'sessiontime' => self::SESSIONTIME,
                'lastupdate' => $now,
            ]]
        );

        if ($result->getMatchedCount() > 0) {
            return;
        }

        $this->insertWithRetry($host, $now);
    }

    public function deactivate(string $host): void
    {
        $this->collection->updateOne(
            ['uniquekey' => $host],
            ['$set' => ['active' => false, 'status' => 0, 'lastupdate' => time()]]
        );
    }

    /**
     * Retries on a duplicate-`_id` write (code 11000) by recomputing the
     * next id — guards the narrow race window, even though in practice
     * this only ever runs single-threaded from KubernetesTask.
     */
    private function insertWithRetry(string $host, int $now): void
    {
        for ($attempt = 1; $attempt <= self::MAX_INSERT_ATTEMPTS; $attempt++) {
            try {
                $this->collection->insertOne([
                    '_id' => $this->nextId(),
                    'uniquekey' => $host,
                    'ORG' => self::ORG,
                    'active' => true,
                    'status' => 1,
                    'allowgroup' => self::ALLOWGROUP,
                    'allowrobot' => self::ALLOWROBOT,
                    'createdate' => $now,
                    'lastupdate' => $now,
                    'sessiontime' => self::SESSIONTIME,
                ]);
                return;
            } catch (BulkWriteException $e) {
                if ($e->getCode() !== 11000 || $attempt === self::MAX_INSERT_ATTEMPTS) {
                    throw $e;
                }
            }
        }
    }

    private function nextId(): int
    {
        $last = $this->collection->find([], [
            'sort' => ['_id' => -1],
            'limit' => 1,
            'projection' => ['_id' => 1],
        ])->toArray();

        return empty($last) ? 1 : $last[0]['_id'] + 1;
    }
}
