<?php

declare(strict_types=1);

namespace App\Billing;

use App\Entity\Payment;

interface PixQrCodeRefreshInterface
{
    public function refreshPixData(Payment $payment): void;
}
