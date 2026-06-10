<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Service\OrderSyncService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SyncOrdersHandler
{
    public function __construct(
        private readonly OrderSyncService $sync,
        private readonly LoggerInterface  $logger,
    ) {}

    public function __invoke(SyncOrdersMessage $message): void
    {
        $this->logger->info('SyncOrdersHandler: iniciando sincronização agendada.', [
            'limit' => $message->limit,
        ]);

        $result = $this->sync->syncBatch($message->limit);

        $this->logger->info('SyncOrdersHandler: sincronização concluída.', $result);
    }
}
