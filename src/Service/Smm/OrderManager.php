<?php

namespace App\Service\Smm;

use App\Entity\Order;
use App\Entity\Service;
use App\Entity\User;
use App\Repository\OrderRepository;
use App\Service\WalletService;
use Doctrine\ORM\EntityManagerInterface;

class OrderManager
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WalletService $wallet,
        private readonly SmmProviderClient $provider,
        private readonly OrderRepository $orders,
    ) {}

    public function create(User $user, Service $service, string $link, int $quantity): Order
    {
        if (!$service->isActive()) {
            throw new \DomainException('Service unavailable.');
        }

        if ($quantity < $service->getMinQuantity() || $quantity > $service->getMaxQuantity()) {
            throw new \DomainException(sprintf('Quantity must be between %d and %d.', $service->getMinQuantity(), $service->getMaxQuantity()));
        }

        if (!filter_var($link, FILTER_VALIDATE_URL)) {
            throw new \DomainException('Invalid link.');
        }

        $charge = bcmul($service->getPricePer1000(), (string) $quantity, 4);
        $charge = bcdiv($charge, '1000', 2);

        $order = new Order();
        $order->setUser($user)
            ->setService($service)
            ->setLink($link)
            ->setQuantity($quantity)
            ->setCharge($charge)
            ->setStatus(Order::STATUS_PENDING);

        $this->em->persist($order);
        $this->em->flush();

        try {
            $this->wallet->debit($user, $charge, 'order', 'order-' . $order->getId(), 'Order #' . $order->getId());
            $providerOrder = $this->provider->addOrder((string) $service->getProviderServiceId(), $link, $quantity);

            if (isset($providerOrder['order'])) {
                $order->setProviderOrderId((string) $providerOrder['order']);
                $order->setStatus(Order::STATUS_PROCESSING);
            } else {
                throw new \RuntimeException($providerOrder['error'] ?? 'Provider rejected the order.');
            }
        } catch (\Throwable $e) {
            $order->setStatus(Order::STATUS_FAILED);
            $order->setLastError($e->getMessage());
            $this->em->flush();
            throw $e;
        }

        $this->em->flush();

        return $order;
    }

    public function syncPendingOrders(): int
    {
        $updated = 0;

        foreach ($this->orders->findPendingWithProviderOrder() as $order) {
            try {
                $status = $this->provider->status((string) $order->getProviderOrderId());
                $map = [
                    'Pending' => Order::STATUS_PENDING,
                    'In progress' => Order::STATUS_PROCESSING,
                    'Processing' => Order::STATUS_PROCESSING,
                    'Completed' => Order::STATUS_COMPLETED,
                    'Partial' => Order::STATUS_COMPLETED,
                    'Canceled' => Order::STATUS_CANCELED,
                    'Cancelled' => Order::STATUS_CANCELED,
                ];

                $order->setStatus($map[$status['status'] ?? ''] ?? $order->getStatus());
                $order->setRemains(isset($status['remains']) ? (int) $status['remains'] : null);
                $order->setStartCount(isset($status['start_count']) ? (int) $status['start_count'] : null);
                $updated++;
            } catch (\Throwable $e) {
                $order->setLastError($e->getMessage());
            }
        }

        $this->em->flush();

        return $updated;
    }
}
