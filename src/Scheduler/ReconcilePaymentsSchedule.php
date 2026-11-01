<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Billing\DynamicGatewayLoader;
use App\Entity\CronLog;
use App\Entity\Payment;
use App\Message\SendDepositConfirmedEmailMessage;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

/**
 * Reconcilia pagamentos pendentes consultando o status diretamente nos gateways.
 * Executa a cada 5 minutos para cobrir webhooks perdidos.
 */
#[AsCronTask('*/5 * * * *')]
final class ReconcilePaymentsSchedule
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DynamicGatewayLoader   $gatewayLoader,
        private readonly MessageBusInterface    $bus,
        private readonly LoggerInterface        $logger,
    ) {}

    public function __invoke(): void
    {
        $startedAt = hrtime(true);
        $processed = 0;
        $approved = 0;
        $errors = 0;
        $failedPaymentIds = [];

        try {
            $cutoffMin = new \DateTimeImmutable('-2 minutes');
            $cutoffMax = new \DateTimeImmutable('-48 hours');

            $payments = $this->em->createQueryBuilder()
                ->select('p')
                ->from(Payment::class, 'p')
                ->where('p.status = :status')
                ->andWhere('p.createdAt <= :cutoffMin')
                ->andWhere('p.createdAt >= :cutoffMax')
                ->andWhere('p.externalId IS NOT NULL')
                ->setParameter('status', Payment::STATUS_PENDING)
                ->setParameter('cutoffMin', $cutoffMin)
                ->setParameter('cutoffMax', $cutoffMax)
                ->orderBy('p.createdAt', 'ASC')
                ->setMaxResults(50)
                ->getQuery()
                ->getResult();

            foreach ($payments as $payment) {
                ++$processed;
                try {
                    $gateway = $this->gatewayLoader->load($this->resolveGatewaySlug($payment));
                    $newStatus = $gateway->fetchStatus($payment);

                    if ($newStatus === Payment::STATUS_APPROVED) {
                        if ($gateway->reconcileApprovedPayment($payment)) {
                            $this->bus->dispatch(new SendDepositConfirmedEmailMessage($payment->getId()));
                            ++$approved;
                        }
                    } elseif (in_array($newStatus, [
                        Payment::STATUS_FAILED,
                        Payment::STATUS_CANCELLED,
                        Payment::STATUS_REFUNDED,
                    ], true)) {
                        $this->updatePendingPaymentStatus($payment, $newStatus);
                    }
                } catch (\Throwable $e) {
                    ++$errors;
                    if (count($failedPaymentIds) < 50) {
                        $failedPaymentIds[] = $payment->getId();
                    }
                    $this->logger->error('ReconcilePayments: erro.', [
                        'payment_id' => $payment->getId(),
                        'exception'  => $e::class,
                        'error'      => $e->getMessage(),
                    ]);
                }
            }

            $cronLog = (new CronLog('payments:reconcile'))
                ->setItemsProcessed($processed)
                ->setContext([
                    'approved' => $approved,
                    'errors' => $errors,
                    'failed_payment_ids' => $failedPaymentIds,
                ])
                ->finish($errors === 0, $errors > 0 ? sprintf('%d pagamento(s) falharam; consulte os logs do aplicativo.', $errors) : null)
                ->setDurationMs((int) ((hrtime(true) - $startedAt) / 1_000_000));
            $this->em->persist($cronLog);
            $this->em->flush();

            $this->logger->info('Reconciliação de pagamentos concluída.', [
                'processed' => $processed,
                'approved' => $approved,
                'errors' => $errors,
                'duration_ms' => $cronLog->getDurationMs(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->critical('Falha geral na reconciliação de pagamentos.', [
                'processed' => $processed,
                'approved' => $approved,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);
            if ($this->em->isOpen()) {
                $cronLog = (new CronLog('payments:reconcile'))
                    ->setItemsProcessed($processed)
                    ->setContext(['approved' => $approved, 'errors' => $errors + 1])
                    ->finish(false, 'Falha geral na reconciliação; consulte os logs do aplicativo.')
                    ->setDurationMs((int) ((hrtime(true) - $startedAt) / 1_000_000));
                $this->em->persist($cronLog);
                $this->em->flush();
            }
            throw $e;
        }
    }

    private function resolveGatewaySlug(Payment $payment): string
    {
        $metadata = json_decode($payment->getGatewayResponse() ?? '{}', true);
        $gateway = is_array($metadata) ? ($metadata['gateway'] ?? null) : null;

        return is_string($gateway) && preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/i', $gateway) === 1
            ? strtolower($gateway)
            : 'asaas';
    }

    private function updatePendingPaymentStatus(Payment $payment, string $status): void
    {
        $this->em->wrapInTransaction(function () use ($payment, $status): void {
            $this->em->refresh($payment, LockMode::PESSIMISTIC_WRITE);
            if ($payment->getStatus() === Payment::STATUS_PENDING) {
                $payment->setStatus($status);
            }
        });
    }
}
