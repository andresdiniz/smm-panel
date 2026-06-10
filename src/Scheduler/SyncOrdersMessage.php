<?php

declare(strict_types=1);

namespace App\Scheduler;

/**
 * Mensagem disparada pelo Scheduler a cada 2 minutos
 * para sincronizar pedidos ativos com os providers SMM.
 */
final class SyncOrdersMessage
{
    public function __construct(
        public readonly int $limit = 100,
    ) {}
}
