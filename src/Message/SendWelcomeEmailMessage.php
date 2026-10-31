<?php

declare(strict_types=1);

namespace App\Message;

final readonly class SendWelcomeEmailMessage
{
    public function __construct(
        public int $userId,
    ) {}
}
