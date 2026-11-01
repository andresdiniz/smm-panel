<?php

declare(strict_types=1);

namespace App\MessageHandler\Billing;

use App\Message\Billing\ReconcilePaymentMessage;
use App\Repository\PaymentRepository;
use App\Service\PaymentReconciliationService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ReconcilePaymentMessageHandler
{
    public function __construct(
        private readonly PaymentRepository $payments,
        private readonly PaymentReconciliationService $reconciliation,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(ReconcilePaymentMessage $message): void
    {
        if ($message->paymentId < 1) {
            throw new \InvalidArgumentException('Payment ID must be positive.');
        }

        $payment = $this->payments->find($message->paymentId);

        if ($payment === null) {
            $this->logger->warning('Pagamento não encontrado para reconciliação.', [
                'payment_id' => $message->paymentId,
            ]);
            return;
        }

        $this->reconciliation->reconcile($payment);
    }
}
