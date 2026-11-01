<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\Connection;

final class MessengerHealthRepository
{
    public function __construct(
        private readonly Connection $connection,
    ) {}

    /**
     * @return array<string, int>
     */
    public function getQueueDepths(): array
    {
        if (!$this->connection->createSchemaManager()->tablesExist(['messenger_messages'])) {
            return [];
        }

        $depths = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT queue_name, COUNT(*) AS message_count FROM messenger_messages GROUP BY queue_name'
        ) as $row) {
            $depths[(string) $row['queue_name']] = (int) $row['message_count'];
        }

        return $depths;
    }

    /**
     * @return list<array{
     *     transport: string,
     *     host: string,
     *     pid: int,
     *     started_at: string,
     *     last_seen_at: string,
     *     last_processed_at: ?string,
     *     message_count: int,
     *     last_message_class: ?string,
     *     last_error_at: ?string,
     *     last_error_class: ?string
     * }>
     */
    public function getWorkerHeartbeats(): array
    {
        if (!$this->connection->createSchemaManager()->tablesExist(['messenger_worker_heartbeats'])) {
            return [];
        }

        return $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT transport_name AS transport, host_name AS host, process_id AS pid,
                       started_at, last_seen_at, last_processed_at, message_count,
                       last_message_class, last_error_at, last_error_class
                FROM messenger_worker_heartbeats
                ORDER BY transport_name ASC, last_seen_at DESC
            SQL,
        );
    }
}
