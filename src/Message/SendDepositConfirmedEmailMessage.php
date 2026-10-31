<?php

declare(strict_types=1);

namespace App\Message;

final readonly class SendDepositConfirmedEmailMessage
{
    public function __construct(
        public int $paymentId,
    ) {}
}
