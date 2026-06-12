<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Billing\DynamicGatewayLoader;
use App\Entity\Payment;
use App\Message\SendDepositConfirmedEmailMessage;
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
        // Pagamentos pendentes há mais de 2 minutos e menos de 48h
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
            ->setMaxResults(50)
            ->getQuery()
            ->getResult();

        foreach ($payments as $payment) {
            try {
                $gateway    = $this->gatewayLoader->load($this->resolveGatewaySlug($payment));
                $newStatus  = $gateway->fetchStatus($payment);

                if ($newStatus === Payment::STATUS_APPROVED && $payment->getStatus() !== Payment::STATUS_APPROVED) {
                    $payment->approve();
                    $this->creditWallet($payment);
                    $this->bus->dispatch(new SendDepositConfirmedEmailMessage($payment->getId()));
                } elseif (in_array($newStatus, [Payment::STATUS_FAILED, Payment::STATUS_CANCELLED], true)) {
                    $payment->setStatus($newStatus);
                }

                $this->em->flush();
            } catch (\Throwable $e) {
                $this->logger->error('ReconcilePayments: erro.', [
                    'payment_id' => $payment->getId(),
                    'error'      => $e->getMessage(),
                ]);
            }
        }
    }

    private function resolveGatewaySlug(Payment $payment): string
    {
        return match ($payment->getMethod()) {
            'pix', 'credit_card', 'debit_card' => 'asaas',
            default => 'asaas',
        };
    }

    private function creditWallet(Payment $payment): void
    {
        $wallet = $this->em->getRepository('App\Entity\Wallet')->findOneBy(['user' => $payment->getUser()]);
        if ($wallet) {
            $net = $payment->getAmountCents() - $payment->getFeeCents();
            $wallet->credit($net);
            $this->em->persist($wallet);
        }
    }
}
