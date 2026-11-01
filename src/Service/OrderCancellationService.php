<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Order;
use App\Entity\Wallet;
use App\Entity\WalletTransaction;
use App\Enum\TransactionType;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final class OrderCancellationService
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function cancelAndRefund(int $orderId, string $reason): bool
    {
        $error = null;
        $cancelled = $this->em->wrapInTransaction(function () use ($orderId, $reason, &$error): bool {
            /** @var Order|null $order */
            $order = $this->em->find(Order::class, $orderId, LockMode::PESSIMISTIC_WRITE);
            if ($order === null) {
                $error = sprintf('Pedido #%d não encontrado para cancelamento.', $orderId);
                return false;
            }

            if (in_array($order->getStatus(), [
                Order::STATUS_COMPLETED,
                Order::STATUS_CANCELLED,
                Order::STATUS_REFUNDED,
            ], true)) {
                return false;
            }

            $refundCents = $order->getAmountCents();
            if ($refundCents > 0) {
                /** @var Wallet|null $wallet */
                $wallet = $this->em->createQueryBuilder()
                    ->select('w')
                    ->from(Wallet::class, 'w')
                    ->where('w.user = :user')
                    ->setParameter('user', $order->getUser())
                    ->getQuery()
                    ->setLockMode(LockMode::PESSIMISTIC_WRITE)
                    ->getOneOrNullResult();

                if ($wallet === null) {
                    $error = sprintf('Não existe carteira para reembolsar o pedido #%d.', $orderId);
                    return false;
                }

                $wallet->credit($refundCents);
                $this->em->persist((new WalletTransaction())
                    ->setWallet($wallet)
                    ->setType(TransactionType::REFUND)
                    ->setAmountCents($refundCents)
                    ->setBalanceAfterCents($wallet->getBalanceCents())
                    ->setDescription(sprintf('Reembolso automático — pedido #%d cancelado pelo provider', $orderId)));
            }

            $order->markAsFailed($reason);

            return true;
        });

        if ($error !== null) {
            throw new \DomainException($error);
        }

        return $cancelled;
    }
}
