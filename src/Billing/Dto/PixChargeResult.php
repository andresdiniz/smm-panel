<?php

declare(strict_types=1);

namespace App\Billing\Dto;

final readonly class PixChargeResult
{
    public function __construct(
        public string              $gatewayId,
        public string              $pixPayload,
        public string              $qrCodeBase64,
        public \DateTimeImmutable  $expiresAt,
    ) {}
}
