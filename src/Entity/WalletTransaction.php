<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\TransactionType;
use App\Repository\WalletTransactionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WalletTransactionRepository::class)]
#[ORM\Table(name: 'wallet_transactions')]
class WalletTransaction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private int $id;

    #[ORM\ManyToOne(targetEntity: Wallet::class, inversedBy: 'transactions')]
    #[ORM\JoinColumn(nullable: false)]
    private Wallet $wallet;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: TransactionType::class)]
    private TransactionType $type;

    // Valor em centavos (sempre positivo; type define direção)
    #[ORM\Column(type: Types::INTEGER)]
    private int $amountCents;

    // Saldo após a operação (snapshot)
    #[ORM\Column(type: Types::INTEGER)]
    private int $balanceAfterCents;

    #[ORM\Column(type: Types::STRING, length: 200)]
    private string $description;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): int { return $this->id; }
    public function getWallet(): Wallet { return $this->wallet; }
    public function setWallet(Wallet $w): self { $this->wallet = $w; return $this; }
    public function getType(): TransactionType { return $this->type; }
    public function setType(TransactionType $t): self { $this->type = $t; return $this; }
    public function getAmountCents(): int { return $this->amountCents; }
    public function setAmountCents(int $v): self { $this->amountCents = $v; return $this; }
    public function getBalanceAfterCents(): int { return $this->balanceAfterCents; }
    public function setBalanceAfterCents(int $v): self { $this->balanceAfterCents = $v; return $this; }
    public function getDescription(): string { return $this->description; }
    public function setDescription(string $d): self { $this->description = $d; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
