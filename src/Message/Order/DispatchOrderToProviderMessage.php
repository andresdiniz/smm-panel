<?php

declare(strict_types=1);

namespace App\Message\Order;

final readonly class DispatchOrderToProviderMessage
{
    public function __construct(
        public int    $orderId,
        public string $providerSlug,
        public int    $attempt = 1,
    ) {}
}
