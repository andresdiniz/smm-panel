<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AffiliateCommissionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AffiliateCommissionRepository::class)]
#[ORM\Table(name: 'affiliate_commission')]
#[ORM\HasLifecycleCallbacks]
class AffiliateCommission
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_PAID      = 'paid';
    public const STATUS_CANCELLED = 'cancelled';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** O afiliado que recebe a comissão */
    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'affiliateCommissions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $affiliate;

    /** O pedido que gerou a comissão */
    #[ORM\ManyToOne(targetEntity: Order::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Order $order;

    /** O cliente que fez o pedido (atalho, derivado de order->user) */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $customer;

    /** Valor da comissão em BRL */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $amount;

    /** Taxa que foi aplicada no momento (snapshot) */
    #[ORM\Column(type: 'decimal', precision: 5, scale: 4)]
    private string $rate;

    #[ORM\Column(length: 20, options: ['default' => 'pending'])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $paidAt = null;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getAffiliate(): User { return $this->affiliate; }
    public function setAffiliate(User $affiliate): static { $this->affiliate = $affiliate; return $this; }

    public function getOrder(): Order { return $this->order; }
    public function setOrder(Order $order): static { $this->order = $order; return $this; }

    public function getCustomer(): User { return $this->customer; }
    public function setCustomer(User $customer): static { $this->customer = $customer; return $this; }

    public function getAmount(): string { return $this->amount; }
    public function setAmount(string $amount): static { $this->amount = $amount; return $this; }

    public function getRate(): string { return $this->rate; }
    public function setRate(string $rate): static { $this->rate = $rate; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getPaidAt(): ?\DateTimeImmutable { return $this->paidAt; }
    public function setPaidAt(?\DateTimeImmutable $paidAt): static { $this->paidAt = $paidAt; return $this; }
}
