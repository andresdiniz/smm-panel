<?php

declare(strict_types=1);

namespace App\Billing;

use App\Entity\Payment;

interface PaymentRefundInterface
{
    public function requestRefund(Payment $payment): void;
}
