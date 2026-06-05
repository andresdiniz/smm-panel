<?php

declare(strict_types=1);

namespace App\Smm\Provider;

use App\Smm\Dto\ProviderStatus;

interface SmmProviderInterface
{
    public function getSlug(): string;
    public function placeOrder(string $serviceId, string $link, int $quantity): string;
    public function getOrderStatus(string $externalId): ProviderStatus;
    public function getBalance(): float;
    public function getServices(): array;
}
