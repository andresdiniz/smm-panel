<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Entity\Order;
use App\Entity\OrderLog;
use App\Entity\CronLog;
use App\Message\Order\SyncOrderStatusMessage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

/**
 * Recupera pedidos ativos sem consulta recente e os envia à fila Messenger.
 * O handler de status processa a resposta e agenda o próximo polling.
 */
#[AsCronTask('*/5 * * * *')]
final class SyncOrdersSchedule
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(): void
    {
        $startedAt = hrtime(true);
        $cutoff = new \DateTimeImmutable('-10 minutes');
        $qb = $this->em->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->join('o.service', 's')
            ->where('o.status IN (:statuses)')
            ->andWhere('o.externalOrderId IS NOT NULL')
            ->andWhere('s.providerSlug IS NOT NULL')
            ->andWhere(sprintf(
                'NOT EXISTS (SELECT l.id FROM %s l WHERE l.order = o AND l.action = :syncAction AND l.createdAt > :cutoff)',
                OrderLog::class,
            ))
            ->setParameter('statuses', [
                Order::STATUS_PROCESSING,
                Order::STATUS_IN_PROGRESS,
                Order::STATUS_PARTIAL,
            ])
            ->setParameter('syncAction', OrderLog::ACTION_SYNC)
            ->setParameter('cutoff', $cutoff)
            ->orderBy('o.createdAt', 'ASC')
            ->setMaxResults(100);

        $orders = $qb->getQuery()->getResult();
        $dispatched = 0;
        $failedOrderIds = [];

        foreach ($orders as $order) {
            try {
                $this->bus->dispatch(new SyncOrderStatusMessage($order->getId()));
                ++$dispatched;
            } catch (\Throwable $e) {
                if (count($failedOrderIds) < 100) {
                    $failedOrderIds[] = $order->getId();
                }
                $this->logger->error('SyncOrdersSchedule: falha ao enfileirar sincronização.', [
                    'order_id' => $order->getId(),
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $cronLog = (new CronLog('orders:sync-dispatch'))
            ->setItemsProcessed($dispatched)
            ->setContext([
                'found' => count($orders),
                'dispatched' => $dispatched,
                'failed_order_ids' => $failedOrderIds,
                'cutoff_minutes' => 10,
            ])
            ->finish($failedOrderIds === [], $failedOrderIds !== [] ? 'Falha ao enfileirar pedidos; consulte os logs do aplicativo.' : null)
            ->setDurationMs((int) ((hrtime(true) - $startedAt) / 1_000_000));
        $this->em->persist($cronLog);
        $this->em->flush();

        $this->logger->info('SyncOrdersSchedule: ciclo de recuperação concluído.', [
            'found' => count($orders),
            'dispatched' => $dispatched,
            'cutoff_minutes' => 10,
        ]);
    }
}
