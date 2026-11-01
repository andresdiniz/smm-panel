<?php

declare(strict_types=1);

namespace App\Scheduler;

/**
 * Mensagem disparada pelo Scheduler a cada 6 horas
 * para sincronizar minQty/maxQty dos serviços com os providers SMM.
 */
final class SyncServicesMessage
{
    public function __construct(
        public readonly ?string $providerSlug = null,
        public readonly int $attempt = 0,
    ) {}
}
