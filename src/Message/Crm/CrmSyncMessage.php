<?php

declare(strict_types=1);

namespace App\Message\Crm;

final readonly class CrmSyncMessage
{
    public function __construct(
        public int    $userId,
        public string $event,
        public array  $payload = [],
    ) {}
}
