<?php

declare(strict_types=1);

namespace App\Message\Order;

final readonly class SyncOrderStatusMessage
{
    public function __construct(
        public int $orderId,
    ) {}
}
