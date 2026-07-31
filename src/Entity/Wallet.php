<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WalletRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WalletRepository::class)]
#[ORM\Table(name: 'wallets')]
#[ORM\HasLifecycleCallbacks]
class Wallet
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'wallet')]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\Column(type: 'bigint', options: ['default' => 0])]
    private int $balanceCents = 0;

    #[ORM\OneToMany(targetEntity: WalletTransaction::class, mappedBy: 'wallet', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'DESC'])]
    private Collection $transactions;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->transactions = new ArrayCollection();
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void { $this->createdAt = new \DateTimeImmutable(); }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void { $this->updatedAt = new \DateTimeImmutable(); }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function setUser(User $user): static { $this->user = $user; return $this; }
    public function getBalanceCents(): int { return $this->balanceCents; }
    public function setBalanceCents(int $cents): static { $this->balanceCents = $cents; return $this; }

    /** @return Collection<int, WalletTransaction> */
    public function getTransactions(): Collection { return $this->transactions; }

    public function addTransaction(WalletTransaction $t): static
    {
        if (!$this->transactions->contains($t)) {
            $this->transactions->add($t);
            $t->setWallet($this);
        }
        return $this;
    }

    public function removeTransaction(WalletTransaction $t): static
    {
        if ($this->transactions->removeElement($t)) {
            if ($t->getWallet() === $this) {
                // WalletTransaction.wallet is non-nullable — only remove via orphanRemoval
            }
        }
        return $this;
    }

    public function credit(int $cents): static { $this->balanceCents += $cents; return $this; }

    public function debit(int $cents): static
    {
        if ($cents > $this->balanceCents) {
            throw new \DomainException('Saldo insuficiente.');
        }
        $this->balanceCents -= $cents;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
}
