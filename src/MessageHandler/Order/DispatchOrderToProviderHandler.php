<?php

declare(strict_types=1);

namespace App\MessageHandler\Order;

use App\Message\Order\DispatchOrderToProviderMessage;
use App\Message\Order\SyncOrderStatusMessage;
use App\Repository\OrderRepository;
use App\Smm\Provider\SmmProviderRegistry;
use App\Smm\Exception\ProviderApiException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

#[AsMessageHandler]
final class DispatchOrderToProviderHandler
{
    public function __construct(
        private readonly OrderRepository        $orders,
        private readonly SmmProviderRegistry    $providers,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface    $bus,
        private readonly LoggerInterface        $logger,
    ) {}

    public function __invoke(DispatchOrderToProviderMessage $message): void
    {
        $order = $this->orders->find($message->orderId)
            ?? throw new \RuntimeException("Order #{$message->orderId} not found");

        if (!$order->canBeDispatched()) {
            $this->logger->warning('Order not dispatchable', [
                'id'     => $message->orderId,
                'status' => $order->getStatus()->value,
            ]);
            return;
        }

        $provider = $this->providers->get($message->providerSlug);

        try {
            $externalId = $provider->placeOrder(
                serviceId: $order->getExternalServiceId(),
                link:      $order->getTargetUrl(),
                quantity:  $order->getQuantity(),
            );

            $order->markAsQueued($externalId);
            $this->em->flush();

            $this->bus->dispatch(
                new SyncOrderStatusMessage($order->getId()),
                [new DelayStamp(120_000)] // 2 min
            );

            $this->logger->info('Order dispatched to provider', [
                'order'      => $order->getId(),
                'provider'   => $message->providerSlug,
                'externalId' => $externalId,
            ]);

        } catch (ProviderApiException $e) {
            $this->logger->error('Provider API error', [
                'order'   => $message->orderId,
                'message' => $e->getMessage(),
            ]);
            throw $e; // Messenger fará retry com backoff
        }
    }
}
