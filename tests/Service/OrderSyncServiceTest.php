<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Order;
use App\Entity\Service;
use App\Entity\User;
use App\Entity\Wallet;
use App\Entity\WalletTransaction;
use App\Message\Order\OrderNotificationMessage;
use App\Repository\WalletRepository;
use App\Service\OrderSyncService;
use App\Smm\SmmProviderInterface;
use App\Smm\SmmProviderRegistry;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class OrderSyncServiceTest extends TestCase
{
    public function testCancelledProviderOrderIsPersistedAndRefundedOnce(): void
    {
        $user = (new User())->setEmail('user@example.test')->setName('Test User');
        $wallet = (new Wallet())->setUser($user)->setBalanceCents(500);
        $service = (new Service())->setName('Test service')->setProviderSlug('example');
        $order = (new Order())
            ->setUser($user)
            ->setService($service)
            ->setStatus(Order::STATUS_PROCESSING)
            ->setAmountCents(275)
            ->setQuantity(10)
            ->setTargetUrl('https://example.test/profile')
            ->setExternalOrderId('remote-123');
        (new \ReflectionProperty(Order::class, 'id'))->setValue($order, 42);

        $provider = $this->createMock(SmmProviderInterface::class);
        $provider->expects(self::once())
            ->method('getOrderStatus')
            ->with('remote-123')
            ->willReturn(['status' => ' Canceled ', 'remains' => 10]);

        $walletRepository = $this->createMock(WalletRepository::class);
        $walletRepository->expects(self::once())
            ->method('findOneByUser')
            ->with($user)
            ->willReturn($wallet);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(OrderNotificationMessage::class), [])
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('find')
            ->with(Order::class, 42)
            ->willReturn($order);
        $entityManager->expects(self::exactly(2))
            ->method('persist')
            ->with(self::callback(static fn (object $entity): bool => $entity instanceof WalletTransaction || $entity instanceof \App\Entity\OrderLog));
        $entityManager->expects(self::once())->method('flush');

        $sync = new OrderSyncService(
            $entityManager,
            new SmmProviderRegistry(['example' => $provider]),
            $walletRepository,
            $bus,
            new NullLogger(),
        );

        self::assertSame(['processed' => 1, 'updated' => 1, 'errors' => 0], $sync->syncOne(42));
        self::assertSame(Order::STATUS_CANCELLED, $order->getStatus());
        self::assertSame(775, $wallet->getBalanceCents());
    }

    public function testSyncingAnAlreadyCancelledOrderDoesNotRefundAgain(): void
    {
        $user = (new User())->setEmail('user@example.test')->setName('Test User');
        $wallet = (new Wallet())->setUser($user)->setBalanceCents(775);
        $order = (new Order())
            ->setUser($user)
            ->setService((new Service())->setName('Test service')->setProviderSlug('example'))
            ->setStatus(Order::STATUS_CANCELLED)
            ->setAmountCents(275)
            ->setQuantity(10)
            ->setTargetUrl('https://example.test/profile')
            ->setExternalOrderId('remote-123');
        (new \ReflectionProperty(Order::class, 'id'))->setValue($order, 42);

        $provider = $this->createMock(SmmProviderInterface::class);
        $provider->expects(self::once())
            ->method('getOrderStatus')
            ->willReturn(['status' => 'Canceled']);
        $walletRepository = $this->createMock(WalletRepository::class);
        $walletRepository->expects(self::never())->method('findOneByUser');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('find')->willReturn($order);
        $entityManager->expects(self::once())->method('persist');
        $entityManager->expects(self::once())->method('flush');

        $sync = new OrderSyncService(
            $entityManager,
            new SmmProviderRegistry(['example' => $provider]),
            $walletRepository,
            $bus,
            new NullLogger(),
        );

        self::assertSame(['processed' => 1, 'updated' => 0, 'errors' => 0], $sync->syncOne(42));
        self::assertSame(775, $wallet->getBalanceCents());
    }
}
