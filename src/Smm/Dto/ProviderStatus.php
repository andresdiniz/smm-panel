<?php

declare(strict_types=1);

namespace App\Smm\Dto;

final readonly class ProviderStatus
{
    public function __construct(
        public string  $state,     // 'pending', 'processing', 'partial', 'completed', 'canceled'
        public int     $delivered = 0,
        public ?string $reason    = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            state:     $data['status'] ?? 'pending',
            delivered: (int) ($data['remains'] ?? 0),
            reason:    $data['error'] ?? null,
        );
    }
}
