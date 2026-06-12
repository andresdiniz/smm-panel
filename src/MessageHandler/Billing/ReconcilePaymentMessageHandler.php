<?php

declare(strict_types=1);

namespace App\MessageHandler\Billing;

use App\Message\Billing\ReconcilePaymentMessage;
use App\Repository\PaymentRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ReconcilePaymentMessageHandler
{
    public function __construct(
        private readonly PaymentRepository $payments,
    ) {}

    public function __invoke(ReconcilePaymentMessage $message): void
    {
        if ($message->paymentId === 0) {
            // paymentId 0 = disparo inválido, ignorar silenciosamente
            return;
        }

        $payment = $this->payments->find($message->paymentId);

        if ($payment === null) {
            return;
        }

        // TODO: consultar gateway Pix e atualizar status do payment
    }
}
