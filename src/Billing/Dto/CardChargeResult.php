<?php

declare(strict_types=1);

namespace App\Billing\Dto;

final readonly class CardChargeResult
{
    public function __construct(
        public string $gatewayId,
        public string $status,
    ) {}
}
