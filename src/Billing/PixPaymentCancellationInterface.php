<?php

declare(strict_types=1);

namespace App\Billing;

use App\Entity\Payment;

interface PixPaymentCancellationInterface
{
    public function cancelPix(Payment $payment): void;
}
