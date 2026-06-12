<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Entity\Order;
use App\Message\ProcessOrderMessage;
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
        $this->logger->info('SyncOrdersSchedule: iniciando ciclo.');

        $this->dispatchPendingOrders();
        $this->syncActiveOrders();

        $this->logger->info('SyncOrdersSchedule: ciclo concluído.');
    }

    /**
     * Pedidos pending sem external_order_id — reenvia para fila de despacho ao provider.
     */
    private function dispatchPendingOrders(): void
    {
        $this->logger->info('SyncOrdersSchedule: buscando pedidos pending sem external_order_id.');

        try {
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
        } catch (\Throwable $e) {
            $this->logger->error('SyncOrdersSchedule: erro ao buscar pedidos pending no banco.', [
                'error'      => $e->getMessage(),
                'error_type' => $e::class,
                'trace'      => $e->getTraceAsString(),
            ]);
            return;
        }

        $this->logger->info('SyncOrdersSchedule: pedidos pending encontrados.', [
            'total' => count($orders),
        ]);

        foreach ($orders as $order) {
            /** @var Order $order */
            $slug = $order->getService()->getProviderSlug();

            if (!$slug) {
                $this->logger->warning('SyncOrdersSchedule: pedido sem providerSlug, ignorando.', [
                    'order_id'   => $order->getId(),
                    'service_id' => $order->getService()->getId(),
                ]);
                continue;
            }

            try {
                $this->bus->dispatch(new ProcessOrderMessage($order->getId()));

                $this->logger->info('SyncOrdersSchedule: ProcessOrderMessage despachado.', [
                    'order_id' => $order->getId(),
                    'provider' => $slug,
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('SyncOrdersSchedule: falha ao despachar mensagem para fila.', [
                    'order_id'   => $order->getId(),
                    'provider'   => $slug,
                    'error'      => $e->getMessage(),
                    'error_type' => $e::class,
                ]);
            }
        }
    }

    /**
     * Pedidos em andamento com external_order_id — sincroniza status com o provider.
     */
    private function syncActiveOrders(): void
    {
        $this->logger->info('SyncOrdersSchedule: buscando pedidos ativos para sync de status.');

        try {
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
        } catch (\Throwable $e) {
            $this->logger->error('SyncOrdersSchedule: erro ao buscar pedidos ativos no banco.', [
                'error'      => $e->getMessage(),
                'error_type' => $e::class,
                'trace'      => $e->getTraceAsString(),
            ]);
            return;
        }

        $this->logger->info('SyncOrdersSchedule: pedidos ativos encontrados.', [
            'total' => count($orders),
        ]);

        foreach ($orders as $order) {
            /** @var Order $order */
            $slug = $order->getService()->getProviderSlug();
            if (!$slug) {
                continue;
            }

            try {
                $provider = $this->loader->loadBySlug($slug);
                if (!$provider) {
                    $this->logger->warning('SyncOrdersSchedule: provider nao encontrado.', [
                        'order_id' => $order->getId(), 'provider' => $slug,
                    ]);
                    continue;
                }

                $this->logger->debug('SyncOrdersSchedule: consultando status no provider.', [
                    'order_id'    => $order->getId(),
                    'provider'    => $slug,
                    'external_id' => $order->getExternalOrderId(),
                ]);

                $data = $provider->getOrderStatus($order->getExternalOrderId());

                $this->logger->info('SyncOrdersSchedule: retorno do provider.', [
                    'order_id'        => $order->getId(),
                    'provider'        => $slug,
                    'external_id'     => $order->getExternalOrderId(),
                    'provider_status' => $data['status'] ?? null,
                    'start_count'     => $data['start_count'] ?? null,
                    'remains'         => $data['remains'] ?? null,
                    'raw_response'    => $data,
                ]);

                $order->setStartCount($data['start_count']);
                $order->setRemains($data['remains']);

                $prev      = $order->getStatus();
                $newStatus = $this->map($data['status']);
                $order->setStatus($newStatus);

                $this->logger->info('SyncOrdersSchedule: status atualizado.', [
                    'order_id'   => $order->getId(),
                    'prev'       => $prev,
                    'new_status' => $newStatus,
                ]);

                if ($prev !== Order::STATUS_COMPLETED && $newStatus === Order::STATUS_COMPLETED) {
                    $this->bus->dispatch(new SendOrderCompletedEmailMessage($order->getId()));
                }
            } catch (\Throwable $e) {
                $this->logger->error('SyncOrdersSchedule: erro ao sincronizar pedido com provider.', [
                    'order_id'   => $order->getId(),
                    'provider'   => $slug,
                    'error'      => $e->getMessage(),
                    'error_type' => $e::class,
                    'trace'      => $e->getTraceAsString(),
                ]);
            }
        }

        try {
            $this->em->flush();
            $this->logger->info('SyncOrdersSchedule: flush do banco concluído.');
        } catch (\Throwable $e) {
            $this->logger->error('SyncOrdersSchedule: erro no flush do banco.', [
                'error'      => $e->getMessage(),
                'error_type' => $e::class,
                'trace'      => $e->getTraceAsString(),
            ]);
        }
    }

    private function map(string $raw): string
    {
        return match (strtolower($raw)) {
            'pending'                  => Order::STATUS_PENDING,
            'processing'               => Order::STATUS_PROCESSING,
            'in progress','inprogress' => Order::STATUS_IN_PROGRESS,
            'completed'                => Order::STATUS_COMPLETED,
            'partial'                  => Order::STATUS_PARTIAL,
            'cancelled','canceled'     => Order::STATUS_CANCELLED,
            default                    => Order::STATUS_PROCESSING,
        };
    }
}
