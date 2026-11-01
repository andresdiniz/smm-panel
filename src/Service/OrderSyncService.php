<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Order;
use App\Entity\OrderLog;
use App\Message\Order\OrderNotificationMessage;
use App\Smm\SmmProviderRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Lógica central de sincronização de status de pedidos com providers SMM.
 * Grava um OrderLog a cada chamada ao provider, incluindo mudanças de status.
 */
final class OrderSyncService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SmmProviderRegistry    $registry,
        private readonly OrderCancellationService $cancellations,
        private readonly MessageBusInterface    $bus,
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

            $start = microtime(true);

            try {
                $provider  = $this->registry->get($slug);
                $status    = $provider->getOrderStatus($order->getExternalOrderId());
                $elapsedMs = (int) round((microtime(true) - $start) * 1000);
                $newStatus = $this->mapProviderStatus($status['status']);

                if (isset($status['start_count'])) {
                    $order->setStartCount((int) $status['start_count']);
                }
                if (isset($status['remains'])) {
                    $order->setRemains((int) $status['remains']);
                }

                $statusChanged = $newStatus !== $order->getStatus();

                if ($statusChanged) {
                    $this->logger->info('OrderSyncService: status atualizado.', [
                        'order_id'   => $order->getId(),
                        'old_status' => $order->getStatus(),
                        'new_status' => $newStatus,
                    ]);
                    if ($newStatus === Order::STATUS_CANCELLED) {
                        if ($this->cancellations->cancelAndRefund($order->getId(), 'Cancelado pelo provider')) {
                            $this->bus->dispatch(new OrderNotificationMessage($order->getId(), 'cancelled'));
                            ++$updated;
                        }
                    } else {
                        $order->setStatus($newStatus);
                        ++$updated;
                    }
                    if ($newStatus === Order::STATUS_COMPLETED) {
                        $this->bus->dispatch(new OrderNotificationMessage($order->getId(), 'completed'));
                    }
                }

                // Grava log de sincronização (toda chamada ao provider)
                $log = new OrderLog();
                $log->setOrder($order);
                $log->setAction(OrderLog::ACTION_SYNC);
                $log->setProvider($slug);
                $log->setHttpStatus(200);
                $log->setElapsedMs($elapsedMs);
                $log->setResponseBody($status);
                $log->setContext([
                    'external_status'  => $status['status'],
                    'mapped_status'    => $newStatus,
                    'status_changed'   => $statusChanged,
                    'start_count'      => $status['start_count'] ?? null,
                    'remains'          => $status['remains'] ?? null,
                ]);
                $this->em->persist($log);

            } catch (\Throwable $e) {
                $elapsedMs = (int) round((microtime(true) - $start) * 1000);
                ++$errors;
                $this->logger->error('OrderSyncService: erro ao sincronizar.', [
                    'order_id' => $order->getId(),
                    'exception' => $e::class,
                    'error'    => $e->getMessage(),
                ]);

                if (!$this->em->isOpen()) {
                    $this->logger->critical('OrderSyncService: EntityManager fechado; a execução precisa ser reiniciada.', [
                        'order_id' => $order->getId(),
                        'exception' => $e::class,
                    ]);
                    throw $e;
                }

                // Grava log de erro também
                $log = new OrderLog();
                $log->setOrder($order);
                $log->setAction(OrderLog::ACTION_SYNC);
                $log->setProvider($slug);
                $log->setElapsedMs($elapsedMs);
                $log->setErrorMessage($e->getMessage());
                $this->em->persist($log);
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
        return match (strtolower(trim($raw))) {
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
