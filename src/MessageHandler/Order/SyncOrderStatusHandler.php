<?php

declare(strict_types=1);

namespace App\MessageHandler\Order;

use App\Message\Order\OrderNotificationMessage;
use App\Message\Order\SyncOrderStatusMessage;
use App\Repository\OrderRepository;
use App\Smm\Provider\SmmProviderRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

#[AsMessageHandler]
final class SyncOrderStatusHandler
{
    public function __construct(
        private readonly OrderRepository        $orders,
        private readonly SmmProviderRegistry    $providers,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface    $bus,
        private readonly LoggerInterface        $logger,
    ) {}

    public function __invoke(SyncOrderStatusMessage $message): void
    {
        $order    = $this->orders->find($message->orderId);
        if (!$order) {
            return;
        }

        $provider = $this->providers->get($order->getProviderSlug());
        $status   = $provider->getOrderStatus($order->getExternalId());

        $order->incrementSyncAttempts();

        match ($status->state) {
            'completed' => $this->handleCompleted($order, $status->delivered),
            'partial'   => $this->handlePartial($order, $status->delivered),
            'canceled'  => $this->handleFailed($order, $status->reason ?? 'Canceled by provider'),
            default     => $this->scheduleNextSync($order),
        };

        $this->em->flush();
    }

    private function handleCompleted(\App\Entity\Order $order, int $delivered): void
    {
        $order->markAsCompleted($delivered);
        $this->bus->dispatch(new OrderNotificationMessage($order->getId(), 'completed'));
        $this->logger->info('Order completed', ['id' => $order->getId(), 'delivered' => $delivered]);
    }

    private function handlePartial(\App\Entity\Order $order, int $delivered): void
    {
        $order->markAsPartial($delivered);
        $this->scheduleNextSync($order);
    }

    private function handleFailed(\App\Entity\Order $order, string $reason): void
    {
        $order->markAsFailed($reason);
        $this->bus->dispatch(new OrderNotificationMessage($order->getId(), 'failed'));
        $this->logger->warning('Order failed', ['id' => $order->getId(), 'reason' => $reason]);
    }

    private function scheduleNextSync(\App\Entity\Order $order): void
    {
        // Polling exponencial: 2min base, dobra a cada tentativa, teto 30min
        $delayMs = min(120_000 * (2 ** $order->getSyncAttempts()), 1_800_000);
        $this->bus->dispatch(
            new SyncOrderStatusMessage($order->getId()),
            [new DelayStamp($delayMs)]
        );
    }
}
