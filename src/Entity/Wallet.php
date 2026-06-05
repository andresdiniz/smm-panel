<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WalletRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WalletRepository::class)]
#[ORM\Table(name: 'wallets')]
class Wallet
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private int $id;

    #[ORM\OneToOne(targetEntity: User::class, inversedBy: 'wallet')]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    // Saldo em centavos
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $balanceCents = 0;

    #[ORM\OneToMany(targetEntity: WalletTransaction::class, mappedBy: 'wallet')]
    private Collection $transactions;

    public function __construct()
    {
        $this->transactions = new ArrayCollection();
    }

    public function credit(int $cents): void
    {
        $this->balanceCents += $cents;
    }

    public function debit(int $cents): void
    {
        if ($this->balanceCents < $cents) {
            throw new \DomainException('Saldo insuficiente.');
        }
        $this->balanceCents -= $cents;
    }

    public function getId(): int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function setUser(User $u): self { $this->user = $u; return $this; }
    public function getBalanceCents(): int { return $this->balanceCents; }
    public function getTransactions(): Collection { return $this->transactions; }
}
