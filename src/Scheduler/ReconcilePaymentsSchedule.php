<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Entity\CronLog;
use App\Repository\PaymentRepository;
use App\Service\PaymentReconciliationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

/**
 * Reconcilia pagamentos pendentes consultando o status diretamente nos gateways.
 * Executa a cada 5 minutos para cobrir webhooks perdidos.
 */
#[AsCronTask('*/5 * * * *', schedule: 'main')]
final class ReconcilePaymentsSchedule
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PaymentRepository $payments,
        private readonly PaymentReconciliationService $reconciliation,
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
            $retryBefore = new \DateTimeImmutable('-10 minutes');
            $payments = $this->payments->findPendingForReconciliation($cutoffMin, $retryBefore);

            foreach ($payments as $payment) {
                ++$processed;
                try {
                    $result = $this->reconciliation->reconcile($payment);
                    if ($result === 'approved') {
                        ++$approved;
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

}
