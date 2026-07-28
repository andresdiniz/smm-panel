<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Entity\Wallet;
use App\Entity\WalletTransaction;
use App\Enum\TransactionType;
use App\Repository\WalletRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Gerencia operações de carteira dos usuários:
 *  - criação automática de carteira ao primeiro uso
 *  - crédito (depósito, comissão, bônus)
 *  - débito (consumo de saldo em pedidos)
 *  - reembolso
 *  - consulta de saldo
 */
class WalletService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WalletRepository       $walletRepository,
    ) {}

    // ── Acesso à carteira ────────────────────────────────────────────────────

    /**
     * Retorna a carteira do usuário, criando-a se ainda não existir.
     */
    public function getOrCreateWallet(User $user): Wallet
    {
        $wallet = $this->walletRepository->findOneBy(['user' => $user]);

        if ($wallet === null) {
            $wallet = new Wallet();
            $wallet->setUser($user);
            $this->em->persist($wallet);
            $this->em->flush();
        }

        return $wallet;
    }

    // ── Operações financeiras ─────────────────────────────────────────────────

    /**
     * Credita um valor (em reais) na carteira do usuário.
     *
     * @param float  $amount      Valor em reais (ex.: 12.50)
     * @param string $description Descrição legível da transação
     */
    public function credit(User $user, float $amount, string $description = 'Crédito'): WalletTransaction
    {
        return $this->record($user, $amount, TransactionType::CREDIT, $description);
    }

    /**
     * Debita um valor (em reais) da carteira do usuário.
     *
     * @throws \DomainException se o saldo for insuficiente
     */
    public function debit(User $user, float $amount, string $description = 'Débito'): WalletTransaction
    {
        return $this->record($user, $amount, TransactionType::DEBIT, $description);
    }

    /**
     * Reembolsa um valor (em reais) na carteira do usuário.
     */
    public function refund(User $user, float $amount, string $description = 'Reembolso'): WalletTransaction
    {
        return $this->record($user, $amount, TransactionType::REFUND, $description);
    }

    /**
     * Concede um bônus/crédito promocional na carteira do usuário.
     */
    public function bonus(User $user, float $amount, string $description = 'Bônus'): WalletTransaction
    {
        return $this->record($user, $amount, TransactionType::BONUS, $description);
    }

    // ── Consulta ─────────────────────────────────────────────────────────────

    /**
     * Retorna o saldo em reais do usuário.
     */
    public function getBalance(User $user): float
    {
        $wallet = $this->walletRepository->findOneBy(['user' => $user]);

        return $wallet !== null ? $wallet->getBalanceCents() / 100 : 0.0;
    }

    // ── Lógica interna ────────────────────────────────────────────────────────

    private function record(
        User            $user,
        float           $amount,
        TransactionType $type,
        string          $description,
    ): WalletTransaction {
        $cents  = (int) round($amount * 100);
        $wallet = $this->getOrCreateWallet($user);

        match ($type) {
            TransactionType::DEBIT                                  => $wallet->debit($cents),  // lança DomainException se insuficiente
            TransactionType::CREDIT, TransactionType::REFUND, TransactionType::BONUS => $wallet->credit($cents),
        };

        $tx = new WalletTransaction();
        $tx->setWallet($wallet);
        $tx->setType($type);
        $tx->setAmountCents($cents);
        $tx->setBalanceAfterCents($wallet->getBalanceCents());
        $tx->setDescription(mb_substr($description, 0, 200));

        $this->em->persist($tx);
        $this->em->flush();

        return $tx;
    }
}
