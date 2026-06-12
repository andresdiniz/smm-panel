<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Entity\Order;
use App\Message\Order\DispatchOrderToProviderMessage;
use App\Message\SendOrderCompletedEmailMessage;
use App\Smm\DynamicSmmProviderLoader;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

/**
 * Sincroniza status dos pedidos ativos com os providers SMM.
 * Executa a cada 2 minutos.
 */
#[AsCronTask('*/2 * * * *')]
final class SyncOrdersSchedule
{
    public function __construct(
        private readonly EntityManagerInterface  $em,
        private readonly DynamicSmmProviderLoader $loader,
        private readonly MessageBusInterface     $bus,
        private readonly LoggerInterface         $logger,
    ) {}

    public function __invoke(): void
    {
        $this->dispatchPendingOrders();
        $this->syncActiveOrders();
    }

    /**
     * Pedidos pending que ainda nao foram enviados ao provedor (sem external_order_id).
     * Recoloca na fila de despacho.
     */
    private function dispatchPendingOrders(): void
    {
        $orders = $this->em->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->join('o.service', 's')
            ->where('o.status = :status')
            ->andWhere('o.externalOrderId IS NULL')
            ->setParameter('status', Order::STATUS_PENDING)
            ->setMaxResults(50)
            ->orderBy('o.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        foreach ($orders as $order) {
            /** @var Order $order */
            $slug = $order->getService()->getProviderSlug();
            if (!$slug) {
                $this->logger->warning('SyncOrders: pedido sem providerSlug, ignorando.', [
                    'order_id' => $order->getId(),
                ]);
                continue;
            }

            $this->bus->dispatch(new DispatchOrderToProviderMessage(
                orderId: $order->getId(),
                providerSlug: $slug,
            ));

            $this->logger->info('SyncOrders: pedido pending reenviado para fila.', [
                'order_id' => $order->getId(),
                'provider' => $slug,
            ]);
        }
    }

    /**
     * Pedidos em andamento com external_order_id — sincroniza status com o provedor.
     */
    private function syncActiveOrders(): void
    {
        $orders = $this->em->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->join('o.service', 's')
            ->where('o.status IN (:statuses)')
            ->andWhere('o.externalOrderId IS NOT NULL')
            ->setParameter('statuses', [Order::STATUS_PROCESSING, Order::STATUS_IN_PROGRESS])
            ->setMaxResults(100)
            ->orderBy('o.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        foreach ($orders as $order) {
            /** @var Order $order */
            $slug = $order->getService()->getProviderSlug();
            if (!$slug) {
                continue;
            }

            try {
                $provider = $this->loader->loadBySlug($slug);
                if (!$provider) {
                    continue;
                }

                $data = $provider->getOrderStatus($order->getExternalOrderId());
                $order->setStartCount($data['start_count']);
                $order->setRemains($data['remains']);

                $prev      = $order->getStatus();
                $newStatus = $this->map($data['status']);
                $order->setStatus($newStatus);

                if ($prev !== Order::STATUS_COMPLETED && $newStatus === Order::STATUS_COMPLETED) {
                    $this->bus->dispatch(new SendOrderCompletedEmailMessage($order->getId()));
                }
            } catch (\Throwable $e) {
                $this->logger->error('SyncOrders: erro.', ['order_id' => $order->getId(), 'error' => $e->getMessage()]);
            }
        }

        $this->em->flush();
    }

    private function map(string $raw): string
    {
        return match (strtolower($raw)) {
            'pending'               => Order::STATUS_PENDING,
            'processing'            => Order::STATUS_PROCESSING,
            'in progress','inprogress' => Order::STATUS_IN_PROGRESS,
            'completed'             => Order::STATUS_COMPLETED,
            'partial'               => Order::STATUS_PARTIAL,
            'cancelled','canceled'  => Order::STATUS_CANCELLED,
            default                 => Order::STATUS_PROCESSING,
        };
    }
}
