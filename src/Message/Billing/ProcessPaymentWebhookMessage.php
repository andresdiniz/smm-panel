<?php

declare(strict_types=1);

namespace App\Message\Billing;

final readonly class ProcessPaymentWebhookMessage
{
    public function __construct(
        public string $gateway,
        public array  $payload,
    ) {}
}
