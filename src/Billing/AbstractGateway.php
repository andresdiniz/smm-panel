<?php

declare(strict_types=1);

namespace App\Billing;

use App\Entity\Payment;
use App\Entity\User;
use App\Entity\Wallet;
use App\Entity\WalletTransaction;
use App\Enum\TransactionType;
use Doctrine\DBAL\LockMode;
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

    public function reconcileApprovedPayment(Payment $payment): bool
    {
        return $this->approveAndCredit($payment);
    }

    protected function approveAndCredit(Payment $payment): bool
    {
        return $this->em->wrapInTransaction(function () use ($payment): bool {
            $gatewayResponse = $payment->getGatewayResponse();
            $this->em->refresh($payment, LockMode::PESSIMISTIC_WRITE);
            if ($gatewayResponse !== null) {
                $payment->setGatewayResponse($gatewayResponse);
            }

            if ($payment->getStatus() !== Payment::STATUS_PENDING) {
                return false;
            }

            $net = $payment->getAmountCents() - $payment->getFeeCents();
            if ($net <= 0) {
                throw new \DomainException('O valor líquido do pagamento deve ser positivo.');
            }

            $wallet = $this->em->createQueryBuilder()
                ->select('w')
                ->from(Wallet::class, 'w')
                ->where('w.user = :user')
                ->setParameter('user', $payment->getUser())
                ->getQuery()
                ->setLockMode(LockMode::PESSIMISTIC_WRITE)
                ->getOneOrNullResult();

            if ($wallet === null) {
                $wallet = (new Wallet())->setUser($payment->getUser());
                $this->em->persist($wallet);
            }

            $wallet->credit($net);

            $transaction = (new WalletTransaction())
                ->setWallet($wallet)
                ->setType(TransactionType::CREDIT)
                ->setAmountCents($net)
                ->setBalanceAfterCents($wallet->getBalanceCents())
                ->setDescription(sprintf('Depósito aprovado (pagamento #%d)', $payment->getId()));

            $payment->approve();
            $this->em->persist($transaction);

            return true;
        });
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
