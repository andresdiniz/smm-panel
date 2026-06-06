<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Mensagem assíncrona para enviar um pedido ao provider SMM externo.
 * Despachada pelo OrderController após debitar a Wallet.
 */
final readonly class ProcessOrderMessage
{
    public function __construct(
        public int $orderId,
    ) {}
}
