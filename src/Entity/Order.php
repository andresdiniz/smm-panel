<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OrderRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OrderRepository::class)]
#[ORM\Table(name: 'orders')]
#[ORM\HasLifecycleCallbacks]
class Order
{
    public const STATUS_PENDING     = 'pending';
    public const STATUS_PROCESSING  = 'processing';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED   = 'completed';
    public const STATUS_PARTIAL     = 'partial';
    public const STATUS_CANCELLED   = 'cancelled';
    public const STATUS_REFUNDED    = 'refunded';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Service $service;

    #[ORM\Column(length: 30)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: 'bigint')]
    private int $amountCents;

    #[ORM\Column]
    private int $quantity;

    #[ORM\Column(length: 512)]
    private string $targetUrl;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $externalOrderId = null;

    #[ORM\Column(nullable: true)]
    private ?int $startCount = null;

    #[ORM\Column(nullable: true)]
    private ?int $remains = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $syncAttempts = 0;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    /** @var Collection<int, OrderLog> */
    #[ORM\OneToMany(targetEntity: OrderLog::class, mappedBy: 'order', fetch: 'EXTRA_LAZY')]
    #[ORM\OrderBy(['createdAt' => 'DESC'])]
    private Collection $orderLogs;

    public function __construct()
    {
        $this->orderLogs = new ArrayCollection();
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void { $this->createdAt = new \DateTimeImmutable(); }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void { $this->updatedAt = new \DateTimeImmutable(); }

    // ── Getters / Setters ────────────────────────────────────────────────

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function setUser(User $user): static { $this->user = $user; return $this; }
    public function getService(): Service { return $this->service; }
    public function setService(Service $service): static { $this->service = $service; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }
    public function getAmountCents(): int { return $this->amountCents; }
    public function setAmountCents(int $cents): static { $this->amountCents = $cents; return $this; }
    public function getQuantity(): int { return $this->quantity; }
    public function setQuantity(int $qty): static { $this->quantity = $qty; return $this; }
    public function getTargetUrl(): string { return $this->targetUrl; }
    public function setTargetUrl(string $url): static { $this->targetUrl = $url; return $this; }
    public function getExternalOrderId(): ?string { return $this->externalOrderId; }
    public function setExternalOrderId(?string $id): static { $this->externalOrderId = $id; return $this; }
    public function getStartCount(): ?int { return $this->startCount; }
    public function setStartCount(?int $n): static { $this->startCount = $n; return $this; }
    public function getRemains(): ?int { return $this->remains; }
    public function setRemains(?int $n): static { $this->remains = $n; return $this; }
    public function getSyncAttempts(): int { return $this->syncAttempts; }
    public function incrementSyncAttempts(): void { $this->syncAttempts++; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
    public function getCompletedAt(): ?\DateTimeImmutable { return $this->completedAt; }
    public function getProviderSlug(): ?string { return $this->service->getProviderSlug(); }
    public function getExternalId(): ?string { return $this->externalOrderId; }

    /** @return Collection<int, OrderLog> */
    public function getOrderLogs(): Collection { return $this->orderLogs; }

    public function markAsCompleted(int $delivered): void
    {
        $this->status      = self::STATUS_COMPLETED;
        $this->remains     = 0;
        $this->startCount  = $delivered;
        $this->completedAt = new \DateTimeImmutable();
    }

    public function markAsPartial(int $delivered): void
    {
        $this->status     = self::STATUS_PARTIAL;
        $this->startCount = $delivered;
        $this->remains    = max(0, $this->quantity - $delivered);
    }

    public function markAsFailed(string $reason): void
    {
        $this->status = self::STATUS_CANCELLED;
    }
}
