<?php

declare(strict_types=1);

namespace App\Billing;

use App\Entity\Payment;
use App\Entity\User;
use App\Entity\Wallet;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Base compartilhada entre os gateways reais.
 * Centraliza persistência, criação de Payment e crédito de Wallet.
 */
abstract class AbstractGateway
{
    public function __construct(
        protected readonly EntityManagerInterface $em,
    ) {}

    protected function buildPayment(User $user, int $amountCents, string $method, int $feeCents = 0): Payment
    {
        $payment = new Payment();
        $payment->setUser($user);
        $payment->setType(Payment::TYPE_DEPOSIT);
        $payment->setMethod($method);
        $payment->setAmountCents($amountCents);
        $payment->setFeeCents($feeCents);
        $payment->setStatus(Payment::STATUS_PENDING);
        return $payment;
    }

    protected function persist(Payment $payment): void
    {
        $this->em->persist($payment);
        $this->em->flush();
    }

    protected function approveAndCredit(Payment $payment): void
    {
        $payment->approve();
        $net = $payment->getAmountCents() - $payment->getFeeCents();
        $wallet = $this->em->getRepository(Wallet::class)->findOneBy(['user' => $payment->getUser()]);
        if ($wallet) {
            $wallet->credit($net);
            $this->em->persist($wallet);
        }
        $this->em->flush();
    }

    protected function findByExternalId(string $externalId): Payment
    {
        $payment = $this->em->getRepository(Payment::class)->findOneBy(['externalId' => $externalId]);
        if (!$payment) {
            throw new \InvalidArgumentException('Pagamento não encontrado: ' . $externalId);
        }
        return $payment;
    }
}
