<?php

declare(strict_types=1);

namespace App\Message\Order;

final readonly class OrderNotificationMessage
{
    public function __construct(
        public int    $orderId,
        public string $event, // 'created', 'paid', 'completed', 'failed'
    ) {}
}
