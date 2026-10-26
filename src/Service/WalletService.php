<?php

namespace App\Service;

use App\Entity\Transaction;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

class WalletService
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function credit(User $user, string $amount, string $type, ?string $reference = null, ?string $description = null): Transaction
    {
        return $this->apply($user, $amount, $type, $reference, $description);
    }

    public function debit(User $user, string $amount, string $type, ?string $reference = null, ?string $description = null): Transaction
    {
        return $this->apply($user, '-' . ltrim($amount, '-'), $type, $reference, $description);
    }

    private function apply(User $user, string $signedAmount, string $type, ?string $reference, ?string $description): Transaction
    {
        $this->em->wrapInTransaction(function () use ($user, $signedAmount, $type, $reference, $description): void {
            $user = $this->em->find(User::class, $user->getId());
            $newBalance = bcadd($user->getBalance(), $signedAmount, 2);

            if (bccomp($newBalance, '0', 2) === -1) {
                throw new \DomainException('Insufficient balance.');
            }

            $user->setBalance($newBalance);

            $transaction = new Transaction();
            $transaction->setUser($user)
                ->setType($type)
                ->setAmount($signedAmount)
                ->setBalanceAfter($newBalance)
                ->setReference($reference)
                ->setDescription($description);

            $this->em->persist($transaction);
            $this->em->flush();
        });

        return $this->em->getRepository(Transaction::class)->findOneBy(['reference' => $reference], ['id' => 'DESC']) ?? new Transaction();
    }
}
