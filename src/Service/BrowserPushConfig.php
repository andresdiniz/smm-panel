<?php

declare(strict_types=1);

namespace App\Service;

final readonly class BrowserPushConfig
{
    public function __construct(
        public string $publicKey,
        public string $privateKey,
        public string $subject,
    ) {}

    public function isEnabled(): bool
    {
        return $this->publicKey !== '' && $this->privateKey !== '';
    }
}
