<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\OrderStatus;
use App\Repository\OrderRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: OrderRepository::class)]
#[ORM\Table(name: 'orders')]
#[ORM\HasLifecycleCallbacks]
class Order
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private int $id;

    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $uuid;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'orders')]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Service::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Service $service;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: OrderStatus::class)]
    private OrderStatus $status = OrderStatus::CREATED;

    #[ORM\Column(length: 500)]
    private string $targetUrl;

    #[ORM\Column(type: Types::INTEGER)]
    private int $quantity;

    // Preço pago pelo usuário (centavos)
    #[ORM\Column(type: Types::INTEGER)]
    private int $priceCents;

    // Custo no provider (centavos)
    #[ORM\Column(type: Types::INTEGER)]
    private int $costCents;

    // ID retornado pelo provider externo após despacho
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $externalId = null;

    // Progresso retornado pelo provider
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $deliveredCount = 0;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $syncAttempts = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $failReason = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    public function __construct()
    {
        $this->uuid      = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void { $this->updatedAt = new \DateTimeImmutable(); }

    public function canBeDispatched(): bool
    {
        return $this->status === OrderStatus::PAID;
    }

    public function markAsQueued(string $externalId): void
    {
        $this->externalId = $externalId;
        $this->status     = OrderStatus::QUEUED;
    }

    public function markAsProcessing(): void { $this->status = OrderStatus::PROCESSING; }

    public function markAsPartial(int $delivered): void
    {
        $this->deliveredCount = $delivered;
        $this->status         = OrderStatus::PARTIAL;
    }

    public function markAsCompleted(int $delivered): void
    {
        $this->deliveredCount = $delivered;
        $this->status         = OrderStatus::COMPLETED;
        $this->completedAt    = new \DateTimeImmutable();
    }

    public function markAsFailed(string $reason): void
    {
        $this->failReason = $reason;
        $this->status     = OrderStatus::FAILED;
    }

    public function incrementSyncAttempts(): void { $this->syncAttempts++; }

    public function getId(): int { return $this->id; }
    public function getUuid(): Uuid { return $this->uuid; }
    public function getUser(): User { return $this->user; }
    public function setUser(User $u): self { $this->user = $u; return $this; }
    public function getService(): Service { return $this->service; }
    public function setService(Service $s): self { $this->service = $s; return $this; }
    public function getStatus(): OrderStatus { return $this->status; }
    public function setStatus(OrderStatus $s): self { $this->status = $s; return $this; }
    public function getTargetUrl(): string { return $this->targetUrl; }
    public function setTargetUrl(string $u): self { $this->targetUrl = $u; return $this; }
    public function getQuantity(): int { return $this->quantity; }
    public function setQuantity(int $q): self { $this->quantity = $q; return $this; }
    public function getPriceCents(): int { return $this->priceCents; }
    public function setPriceCents(int $v): self { $this->priceCents = $v; return $this; }
    public function getCostCents(): int { return $this->costCents; }
    public function setCostCents(int $v): self { $this->costCents = $v; return $this; }
    public function getExternalId(): ?string { return $this->externalId; }
    public function getDeliveredCount(): int { return $this->deliveredCount; }
    public function getSyncAttempts(): int { return $this->syncAttempts; }
    public function getFailReason(): ?string { return $this->failReason; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function getCompletedAt(): ?\DateTimeImmutable { return $this->completedAt; }
    public function getProviderSlug(): string { return $this->service->getProviderSlug(); }
    public function getExternalServiceId(): string { return $this->service->getExternalServiceId(); }
    public function getProfit(): int { return $this->priceCents - $this->costCents; }
    public function __toString(): string { return '#' . $this->id . ' — ' . $this->service->getName(); }
}
