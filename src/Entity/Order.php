<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'orders')]
#[ORM\Index(columns: ['status'])]
#[ORM\Index(columns: ['user_id'])]
class Order
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELED = 'canceled';
    public const STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Service::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Service $service;

    #[ORM\Column(length: 500)]
    private string $link = '';

    #[ORM\Column(type: 'integer')]
    private int $quantity = 0;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 4)]
    private string $charge = '0.0000';

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $providerOrderId = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $startCount = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $remains = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function setUser(User $user): static { $this->user = $user; return $this; }
    public function getService(): Service { return $this->service; }
    public function setService(Service $service): static { $this->service = $service; return $this; }
    public function getLink(): string { return $this->link; }
    public function setLink(string $link): static { $this->link = $link; return $this; }
    public function getQuantity(): int { return $this->quantity; }
    public function setQuantity(int $quantity): static { $this->quantity = $quantity; return $this; }
    public function getCharge(): string { return $this->charge; }
    public function setCharge(string $charge): static { $this->charge = $charge; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; $this->updatedAt = new \DateTimeImmutable(); return $this; }
    public function getProviderOrderId(): ?string { return $this->providerOrderId; }
    public function setProviderOrderId(?string $id): static { $this->providerOrderId = $id; return $this; }
    public function getStartCount(): ?int { return $this->startCount; }
    public function setStartCount(?int $value): static { $this->startCount = $value; return $this; }
    public function getRemains(): ?int { return $this->remains; }
    public function setRemains(?int $value): static { $this->remains = $value; return $this; }
    public function getLastError(): ?string { return $this->lastError; }
    public function setLastError(?string $error): static { $this->lastError = $error; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
}
