<?php
declare(strict_types=1);

namespace SearchAssistantAI\Service\Indexing;

use Doctrine\DBAL\Connection;
use RuntimeException;

/**
 * Persistence layer for the `search_assistant_sync_queue` table.
 *
 * Centralises every SQL statement that touches the queue so callers
 * (event handlers, the queue processor, dashboard stats, reindex cleanup)
 * don't repeat the same prepare/bind/execute boilerplate and can't drift
 * apart on column names, status tokens, or truncation rules.
 *
 * The table itself is not a Doctrine entity in this module — it's managed
 * via schema.sql and direct DBAL, which is fine for a narrow, single-writer
 * workload. This class exists so that stays contained.
 *
 * Extracted from SyncService during the v1.0 → v1.1 refactor.
 */
class SyncQueueRepository
{
    /** Max length of the error_message column (truncate to fit). */
    private const MAX_ERROR_LENGTH = 500;

    private const STATUS_PENDING = 'pending';
    private const STATUS_COMPLETED = 'completed';
    private const STATUS_FAILED = 'failed';

    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Insert a new queue row for (resource, action). Returns the queue row
     * id on success, null if the insert happened but no row id could be
     * read back.
     */
    public function enqueue(int $resourceId, string $resourceType, string $action): ?int
    {
        $sql = 'INSERT INTO search_assistant_sync_queue (resource_id, resource_type, action, status, created_at)
                VALUES (:resource_id, :resource_type, :action, :status, :created_at)';

        $stmt = $this->connection->prepare($sql);
        $stmt->bindValue('resource_id', $resourceId, \PDO::PARAM_INT);
        $stmt->bindValue('resource_type', $resourceType);
        $stmt->bindValue('action', $action);
        $stmt->bindValue('status', self::STATUS_PENDING);
        $stmt->bindValue('created_at', date('Y-m-d H:i:s'));

        $affected = $stmt->executeStatement();
        if ($affected <= 0) {
            return null;
        }

        $id = $this->connection->lastInsertId();
        return $id !== false ? (int) $id : null;
    }

    /**
     * Fetch the oldest N pending queue rows, FIFO. Returns each row as
     * ['id', 'resource_id', 'resource_type', 'action'].
     *
     * @return list<array{id:int, resource_id:int, resource_type:string, action:string}>
     */
    public function fetchPending(int $limit): array
    {
        $sql = 'SELECT id, resource_id, resource_type, action
                FROM search_assistant_sync_queue
                WHERE status = :status
                ORDER BY created_at ASC
                LIMIT :limit';

        $stmt = $this->connection->prepare($sql);
        $stmt->bindValue('status', self::STATUS_PENDING);
        $stmt->bindValue('limit', $limit, \PDO::PARAM_INT);

        $rows = $stmt->executeQuery()->fetchAllAssociative();
        $normalized = [];
        foreach ($rows as $row) {
            $normalized[] = [
                'id' => (int) $row['id'],
                'resource_id' => (int) $row['resource_id'],
                'resource_type' => (string) $row['resource_type'],
                'action' => (string) $row['action'],
            ];
        }
        return $normalized;
    }

    public function markCompletedById(int $queueId): void
    {
        $this->updateStatusById($queueId, self::STATUS_COMPLETED, null);
    }

    public function markFailedById(int $queueId, string $errorMessage): void
    {
        $this->updateStatusById($queueId, self::STATUS_FAILED, $errorMessage);
    }

    /**
     * Used by the auto-process path: we only have (resourceId, resourceType)
     * + the fact that we just enqueued a pending row. Narrow to that pending
     * row and flip its status.
     */
    public function markPendingForResourceCompleted(int $resourceId, string $resourceType): void
    {
        $this->updatePendingForResource($resourceId, $resourceType, self::STATUS_COMPLETED, null);
    }

    public function markPendingForResourceFailed(int $resourceId, string $resourceType, string $errorMessage): void
    {
        $this->updatePendingForResource($resourceId, $resourceType, self::STATUS_FAILED, $errorMessage);
    }

    /**
     * Flip pending rows to completed. Used by the dashboard's "reindex done"
     * cleanup so the queue doesn't show stale pending counts after a manual
     * full reindex (which bypasses the queue). Filters are additive:
     *
     *   - $resourceType: only clear rows of this type (null = any type)
     *   - $beforeTimestamp: only clear rows created strictly before this
     *     moment (null = any time). Useful to avoid clearing rows added
     *     while the reindex was running.
     *
     * Returns the number of rows affected. `failed` rows are not touched.
     */
    public function clearPendingBefore(?string $resourceType = null, ?string $beforeTimestamp = null): int
    {
        $sql = 'UPDATE search_assistant_sync_queue
                SET status = :status, processed_at = :processed_at
                WHERE status = :pending_status';

        $params = [
            'status' => self::STATUS_COMPLETED,
            'processed_at' => date('Y-m-d H:i:s'),
            'pending_status' => self::STATUS_PENDING,
        ];

        if ($resourceType !== null) {
            $sql .= ' AND resource_type = :resource_type';
            $params['resource_type'] = $resourceType;
        }
        if ($beforeTimestamp !== null) {
            $sql .= ' AND created_at < :before_time';
            $params['before_time'] = $beforeTimestamp;
        }

        $stmt = $this->connection->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        return (int) $stmt->executeStatement();
    }

    /**
     * Dashboard statistics: counts per status, plus average completed-item
     * processing time and oldest-pending age. Returns a fully-populated
     * array (missing statuses default to 0) so consumers don't need to
     * defensively merge defaults.
     *
     * @return array{total:int, pending:int, completed:int, failed:int,
     *               average_processing_time: float|null,
     *               oldest_pending_age: int|null}
     */
    public function getStats(): array
    {
        $stats = [
            'total' => 0,
            'pending' => 0,
            'completed' => 0,
            'failed' => 0,
            'average_processing_time' => null,
            'oldest_pending_age' => null,
        ];

        try {
            // Counts per status
            $rows = $this->connection
                ->prepare('SELECT status, COUNT(*) AS count FROM search_assistant_sync_queue GROUP BY status')
                ->executeQuery()
                ->fetchAllAssociative();

            foreach ($rows as $row) {
                $count = (int) $row['count'];
                $stats['total'] += $count;
                if (isset($stats[$row['status']])) {
                    $stats[$row['status']] = $count;
                }
            }

            // Average processing time of completed items (seconds)
            $avgStmt = $this->connection->prepare(
                'SELECT AVG(TIMESTAMPDIFF(SECOND, created_at, processed_at)) AS avg_time
                 FROM search_assistant_sync_queue
                 WHERE status = :status AND processed_at IS NOT NULL'
            );
            $avgStmt->bindValue('status', self::STATUS_COMPLETED);
            $avgRow = $avgStmt->executeQuery()->fetchAssociative();
            if ($avgRow && $avgRow['avg_time'] !== null) {
                $stats['average_processing_time'] = (float) $avgRow['avg_time'];
            }

            // Oldest pending item age (seconds)
            $oldestStmt = $this->connection->prepare(
                'SELECT TIMESTAMPDIFF(SECOND, MIN(created_at), NOW()) AS oldest_age
                 FROM search_assistant_sync_queue
                 WHERE status = :status'
            );
            $oldestStmt->bindValue('status', self::STATUS_PENDING);
            $oldestRow = $oldestStmt->executeQuery()->fetchAssociative();
            if ($oldestRow && $oldestRow['oldest_age'] !== null) {
                $stats['oldest_pending_age'] = (int) $oldestRow['oldest_age'];
            }
        } catch (\Exception $e) {
            throw new RuntimeException('Failed to fetch sync statistics: ' . $e->getMessage(), 0, $e);
        }

        return $stats;
    }

    private function updateStatusById(int $queueId, string $status, ?string $errorMessage): void
    {
        if ($errorMessage !== null) {
            $sql = 'UPDATE search_assistant_sync_queue
                    SET status = :status, processed_at = :processed_at, error_message = :error
                    WHERE id = :id';
            $stmt = $this->connection->prepare($sql);
            $stmt->bindValue('error', substr($errorMessage, 0, self::MAX_ERROR_LENGTH));
        } else {
            $sql = 'UPDATE search_assistant_sync_queue
                    SET status = :status, processed_at = :processed_at
                    WHERE id = :id';
            $stmt = $this->connection->prepare($sql);
        }
        $stmt->bindValue('status', $status);
        $stmt->bindValue('processed_at', date('Y-m-d H:i:s'));
        $stmt->bindValue('id', $queueId, \PDO::PARAM_INT);
        $stmt->executeStatement();
    }

    private function updatePendingForResource(
        int $resourceId,
        string $resourceType,
        string $status,
        ?string $errorMessage
    ): void {
        if ($errorMessage !== null) {
            $sql = 'UPDATE search_assistant_sync_queue
                    SET status = :status, processed_at = :processed_at, error_message = :error
                    WHERE resource_id = :resource_id
                      AND resource_type = :resource_type
                      AND status = :pending_status';
            $stmt = $this->connection->prepare($sql);
            $stmt->bindValue('error', substr($errorMessage, 0, self::MAX_ERROR_LENGTH));
        } else {
            $sql = 'UPDATE search_assistant_sync_queue
                    SET status = :status, processed_at = :processed_at
                    WHERE resource_id = :resource_id
                      AND resource_type = :resource_type
                      AND status = :pending_status';
            $stmt = $this->connection->prepare($sql);
        }
        $stmt->bindValue('status', $status);
        $stmt->bindValue('processed_at', date('Y-m-d H:i:s'));
        $stmt->bindValue('resource_id', $resourceId, \PDO::PARAM_INT);
        $stmt->bindValue('resource_type', $resourceType);
        $stmt->bindValue('pending_status', self::STATUS_PENDING);
        $stmt->executeStatement();
    }
}
