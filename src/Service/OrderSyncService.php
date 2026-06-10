<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Order;
use App\Smm\SmmProviderRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Lógica central de sincronização de status de pedidos com providers SMM.
 * Usada tanto pelo SyncOrderStatusCommand quanto pelo Scheduler automático.
 */
final class OrderSyncService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SmmProviderRegistry    $registry,
        private readonly LoggerInterface        $logger,
    ) {}

    /**
     * Sincroniza um lote de pedidos ativos.
     *
     * @return array{processed: int, updated: int, errors: int}
     */
    public function syncBatch(int $limit = 100): array
    {
        $orders = $this->em->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->join('o.service', 's')
            ->where('o.status IN (:statuses)')
            ->andWhere('o.externalOrderId IS NOT NULL')
            ->andWhere('s.providerSlug IS NOT NULL')
            ->setParameter('statuses', [
                Order::STATUS_PROCESSING,
                Order::STATUS_IN_PROGRESS,
                Order::STATUS_PARTIAL,
            ])
            ->setMaxResults($limit)
            ->orderBy('o.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $this->syncOrders($orders);
    }

    /**
     * Sincroniza um único pedido pelo ID.
     *
     * @return array{processed: int, updated: int, errors: int}
     */
    public function syncOne(int $orderId): array
    {
        $order = $this->em->find(Order::class, $orderId);
        if (!$order) {
            throw new \InvalidArgumentException("Pedido #{$orderId} não encontrado.");
        }

        return $this->syncOrders([$order]);
    }

    /**
     * @param Order[] $orders
     * @return array{processed: int, updated: int, errors: int}
     */
    public function syncOrders(array $orders): array
    {
        $updated = 0;
        $errors  = 0;

        foreach ($orders as $order) {
            $slug = $order->getService()->getProviderSlug();

            if (!$this->registry->has($slug)) {
                $this->logger->warning('OrderSyncService: provider não encontrado.', [
                    'order_id' => $order->getId(),
                    'slug'     => $slug,
                ]);
                ++$errors;
                continue;
            }

            try {
                $provider  = $this->registry->get($slug);
                $status    = $provider->getOrderStatus($order->getExternalOrderId());
                $newStatus = $this->mapProviderStatus($status['status']);

                if (isset($status['start_count'])) {
                    $order->setStartCount((int) $status['start_count']);
                }
                if (isset($status['remains'])) {
                    $order->setRemains((int) $status['remains']);
                }

                if ($newStatus !== $order->getStatus()) {
                    $this->logger->info('OrderSyncService: status atualizado.', [
                        'order_id'   => $order->getId(),
                        'old_status' => $order->getStatus(),
                        'new_status' => $newStatus,
                    ]);
                    $order->setStatus($newStatus);
                    ++$updated;
                }
            } catch (\Throwable $e) {
                ++$errors;
                $this->logger->error('OrderSyncService: erro ao sincronizar.', [
                    'order_id' => $order->getId(),
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        $this->em->flush();

        return [
            'processed' => count($orders),
            'updated'   => $updated,
            'errors'    => $errors,
        ];
    }

    private function mapProviderStatus(string $raw): string
    {
        return match (strtolower($raw)) {
            'pending'                => Order::STATUS_PENDING,
            'processing'             => Order::STATUS_PROCESSING,
            'in progress',
            'inprogress'             => Order::STATUS_IN_PROGRESS,
            'completed'              => Order::STATUS_COMPLETED,
            'partial'                => Order::STATUS_PARTIAL,
            'cancelled', 'canceled'  => Order::STATUS_CANCELLED,
            'refunded'               => Order::STATUS_REFUNDED,
            default                  => Order::STATUS_PROCESSING,
        };
    }
}
