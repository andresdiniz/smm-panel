<?php

declare(strict_types=1);

namespace App\Service;

use App\Billing\DynamicGatewayLoader;
use App\Entity\Payment;
use App\Message\SendDepositConfirmedEmailMessage;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final class PaymentReconciliationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DynamicGatewayLoader $gatewayLoader,
        private readonly MessageBusInterface $bus,
    ) {}

    public function reconcile(Payment $payment): string
    {
        $claimResult = $this->claimForReconciliation($payment);
        if ($claimResult !== 'claimed') {
            return $claimResult;
        }

        $gateway = $this->gatewayLoader->load($this->resolveGatewaySlug($payment));
        $newStatus = $gateway->fetchStatus($payment);

        if ($newStatus === Payment::STATUS_APPROVED) {
            if (!$gateway->reconcileApprovedPayment($payment)) {
                return 'unchanged';
            }

            $this->bus->dispatch(new SendDepositConfirmedEmailMessage($payment->getId()));

            return 'approved';
        }

        if (!in_array($newStatus, [
            Payment::STATUS_FAILED,
            Payment::STATUS_CANCELLED,
            Payment::STATUS_REFUNDED,
        ], true)) {
            return 'pending';
        }

        return $this->updatePendingPaymentStatus($payment, $newStatus) ? 'status_updated' : 'unchanged';
    }

    private function claimForReconciliation(Payment $payment): string
    {
        return $this->em->wrapInTransaction(function () use ($payment): string {
            $this->em->refresh($payment, LockMode::PESSIMISTIC_WRITE);
            if ($payment->getStatus() !== Payment::STATUS_PENDING) {
                return 'unchanged';
            }
            if ($payment->getType() !== Payment::TYPE_DEPOSIT) {
                return 'unsupported_payment_type';
            }
            if ($payment->getExternalId() === null || $payment->getExternalId() === '') {
                return 'missing_external_id';
            }
            if ($payment->getLastReconciledAt() !== null
                && $payment->getLastReconciledAt() > new \DateTimeImmutable('-10 minutes')
            ) {
                return 'recently_reconciled';
            }

            $payment->recordReconciliationAttempt();

            return 'claimed';
        });
    }

    private function resolveGatewaySlug(Payment $payment): string
    {
        $metadata = json_decode($payment->getGatewayResponse() ?? '{}', true);
        $gateway = is_array($metadata) ? ($metadata['gateway'] ?? null) : null;

        if (is_string($gateway) && preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/i', $gateway) === 1) {
            return strtolower($gateway);
        }

        if (!is_array($metadata)) {
            throw new \RuntimeException('Não foi possível identificar o gateway do pagamento.');
        }

        if (isset($metadata['point_of_interaction']) || isset($metadata['payment_method_id'])) {
            return 'mercadopago';
        }
        if (isset($metadata['charges']) || isset($metadata['qr_codes']) || isset($metadata['reference_id'])) {
            return 'pagbank';
        }
        if (isset($metadata['billingType']) || isset($metadata['invoiceUrl']) || isset($metadata['customer'])) {
            return 'asaas';
        }

        throw new \RuntimeException('Não foi possível identificar o gateway do pagamento.');
    }

    private function updatePendingPaymentStatus(Payment $payment, string $status): bool
    {
        return $this->em->wrapInTransaction(function () use ($payment, $status): bool {
            $this->em->refresh($payment, LockMode::PESSIMISTIC_WRITE);
            if ($payment->getStatus() !== Payment::STATUS_PENDING) {
                return false;
            }

            $payment->setStatus($status);

            return true;
        });
    }
}
