<?php

declare(strict_types=1);

namespace App\Messenger;

use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;

final class MessengerWorkerHeartbeatListener
{
    private const HEARTBEAT_INTERVAL_SECONDS = 30;
    private const STALE_RETENTION_DAYS = 7;

    private int $lastHeartbeatAt = 0;
    private int $nextCleanupAt = 0;

    public function __construct(
        private readonly Connection $connection,
    ) {}

    #[AsEventListener(event: WorkerStartedEvent::class)]
    public function onWorkerStarted(WorkerStartedEvent $event): void
    {
        foreach ($event->getWorker()->getMetadata()->getTransportNames() as $transport) {
            $this->upsertWorker($transport, true);
        }
    }

    #[AsEventListener(event: WorkerRunningEvent::class)]
    public function onWorkerRunning(WorkerRunningEvent $event): void
    {
        $now = time();
        if ($now - $this->lastHeartbeatAt < self::HEARTBEAT_INTERVAL_SECONDS) {
            return;
        }

        foreach ($event->getWorker()->getMetadata()->getTransportNames() as $transport) {
            $this->upsertWorker($transport);
        }
        $this->lastHeartbeatAt = $now;

        if ($now >= $this->nextCleanupAt) {
            $this->connection->executeStatement(
                'DELETE FROM messenger_worker_heartbeats WHERE last_seen_at < :cutoff',
                ['cutoff' => (new \DateTimeImmutable('-'.self::STALE_RETENTION_DAYS.' days'))->format('Y-m-d H:i:s')],
            );
            $this->nextCleanupAt = $now + 3600;
        }
    }

    #[AsEventListener(event: WorkerMessageHandledEvent::class)]
    public function onMessageHandled(WorkerMessageHandledEvent $event): void
    {
        $this->updateMessageState(
            $event->getReceiverName(),
            $event->getEnvelope()->getMessage()::class,
            null,
        );
    }

    #[AsEventListener(event: WorkerMessageFailedEvent::class)]
    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        $this->updateMessageState(
            $event->getReceiverName(),
            $event->getEnvelope()->getMessage()::class,
            $event->getThrowable()::class,
        );
    }

    private function upsertWorker(string $transport, bool $started = false): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $workerId = $this->workerId($transport);

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO messenger_worker_heartbeats
                    (worker_id, transport_name, host_name, process_id, started_at, last_seen_at)
                VALUES (:worker_id, :transport, :host, :pid, :started_at, :last_seen_at)
                ON DUPLICATE KEY UPDATE
                    started_at = IF(:started, VALUES(started_at), started_at),
                    last_seen_at = VALUES(last_seen_at)
            SQL,
            [
                'worker_id' => $workerId,
                'transport' => $transport,
                'host' => gethostname() ?: 'unknown',
                'pid' => getmypid(),
                'started_at' => $now,
                'last_seen_at' => $now,
                'started' => $started,
            ],
        );
    }

    private function updateMessageState(string $transport, string $messageClass, ?string $errorClass): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $workerId = $this->workerId($transport);

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO messenger_worker_heartbeats
                    (worker_id, transport_name, host_name, process_id, started_at, last_seen_at,
                     last_processed_at, message_count, last_message_class, last_error_at, last_error_class)
                VALUES (:worker_id, :transport, :host, :pid, :now, :now, :processed_at, :handled_count, :message_class, :error_at, :error_class)
                ON DUPLICATE KEY UPDATE
                    last_seen_at = VALUES(last_seen_at),
                    last_processed_at = IF(VALUES(last_error_at) IS NULL, VALUES(last_processed_at), last_processed_at),
                    message_count = message_count + VALUES(message_count),
                    last_message_class = VALUES(last_message_class),
                    last_error_at = COALESCE(VALUES(last_error_at), last_error_at),
                    last_error_class = COALESCE(VALUES(last_error_class), last_error_class)
            SQL,
            [
                'worker_id' => $workerId,
                'transport' => $transport,
                'host' => gethostname() ?: 'unknown',
                'pid' => getmypid(),
                'now' => $now,
                'processed_at' => $errorClass === null ? $now : null,
                'handled_count' => $errorClass === null ? 1 : 0,
                'message_class' => $messageClass,
                'error_at' => $errorClass !== null ? $now : null,
                'error_class' => $errorClass,
            ],
        );
    }

    private function workerId(string $transport): string
    {
        return hash('sha256', sprintf('%s:%d:%s', gethostname() ?: 'unknown', getmypid(), $transport));
    }
}
