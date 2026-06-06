<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Order;
use App\Message\ProcessOrderMessage;
use App\Smm\SmmProviderRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ProcessOrderHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SmmProviderRegistry    $registry,
        private readonly LoggerInterface        $logger,
    ) {}

    public function __invoke(ProcessOrderMessage $message): void
    {
        /** @var Order|null $order */
        $order = $this->em->find(Order::class, $message->orderId);

        if (!$order) {
            $this->logger->warning('ProcessOrderHandler: pedido não encontrado.', ['id' => $message->orderId]);
            return;
        }

        if ($order->getStatus() !== Order::STATUS_PENDING) {
            $this->logger->info('ProcessOrderHandler: pedido já processado, ignorando.', [
                'id'     => $message->orderId,
                'status' => $order->getStatus(),
            ]);
            return;
        }

        $service = $order->getService();
        $slug    = $service->getProviderSlug();

        if (!$slug || !$this->registry->has($slug)) {
            $this->logger->error('ProcessOrderHandler: provider slug não configurado.', [
                'service_id' => $service->getId(),
                'slug'       => $slug,
            ]);
            $order->setStatus(Order::STATUS_CANCELLED);
            $this->em->flush();
            return;
        }

        try {
            $provider    = $this->registry->get($slug);
            $externalId  = $provider->addOrder(
                $service->getExternalServiceId(),
                $order->getTargetUrl(),
                $order->getQuantity()
            );

            $order->setExternalOrderId($externalId);
            $order->setStatus(Order::STATUS_PROCESSING);

            $this->logger->info('ProcessOrderHandler: pedido enviado ao provider.', [
                'order_id'    => $order->getId(),
                'external_id' => $externalId,
                'provider'    => $slug,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('ProcessOrderHandler: falha ao enviar pedido.', [
                'order_id' => $order->getId(),
                'error'    => $e->getMessage(),
            ]);
            $order->setStatus(Order::STATUS_CANCELLED);
        }

        $this->em->flush();
    }
}
