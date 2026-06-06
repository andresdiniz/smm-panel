<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OrderRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OrderRepository::class)]
#[ORM\Table(name: 'orders')]
#[ORM\HasLifecycleCallbacks]
class Order
{
    public const STATUS_PENDING    = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_IN_PROGRESS= 'in_progress';
    public const STATUS_COMPLETED  = 'completed';
    public const STATUS_PARTIAL    = 'partial';
    public const STATUS_CANCELLED  = 'cancelled';
    public const STATUS_REFUNDED   = 'refunded';

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

    /** URL alvo (perfil, post, vídeo etc.) */
    #[ORM\Column(length: 512)]
    private string $targetUrl;

    /** ID do pedido no provider SMM externo */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $externalOrderId = null;

    /** Quantidade já entregue reportada pelo provider */
    #[ORM\Column(nullable: true)]
    private ?int $startCount = null;

    #[ORM\Column(nullable: true)]
    private ?int $remains = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\PrePersist]
    public function onPrePersist(): void { $this->createdAt = new \DateTimeImmutable(); }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void { $this->updatedAt = new \DateTimeImmutable(); }

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
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
}
