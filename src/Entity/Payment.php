<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PaymentRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PaymentRepository::class)]
#[ORM\Table(name: 'payments')]
#[ORM\HasLifecycleCallbacks]
class Payment
{
    public const TYPE_DEPOSIT  = 'deposit';
    public const TYPE_EXPENSE  = 'expense';
    public const TYPE_REFUND   = 'refund';

    public const STATUS_PENDING   = 'pending';
    public const STATUS_APPROVED  = 'approved';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REFUNDED  = 'refunded';

    public const METHOD_PIX         = 'pix';
    public const METHOD_CREDIT_CARD = 'credit_card';
    public const METHOD_DEBIT_CARD  = 'debit_card';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\Column(length: 20)]
    private string $type;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(length: 20)]
    private string $method;

    #[ORM\Column(type: 'bigint')]
    private int $amountCents;

    #[ORM\Column(type: 'bigint', options: ['default' => 0])]
    private int $feeCents = 0;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $pixCode = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $qrCodeBase64 = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $externalId = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $gatewayResponse = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $paidAt = null;

    #[ORM\PrePersist]
    public function onPrePersist(): void { $this->createdAt = new \DateTimeImmutable(); }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function setUser(User $user): static { $this->user = $user; return $this; }
    public function getType(): string { return $this->type; }
    public function setType(string $type): static { $this->type = $type; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }
    public function getMethod(): string { return $this->method; }
    public function setMethod(string $method): static { $this->method = $method; return $this; }
    public function getAmountCents(): int { return $this->amountCents; }
    public function setAmountCents(int $cents): static { $this->amountCents = $cents; return $this; }
    public function getFeeCents(): int { return $this->feeCents; }
    public function setFeeCents(int $cents): static { $this->feeCents = $cents; return $this; }
    public function getPixCode(): ?string { return $this->pixCode; }
    public function setPixCode(?string $code): static { $this->pixCode = $code; return $this; }
    public function getQrCodeBase64(): ?string { return $this->qrCodeBase64; }
    public function setQrCodeBase64(?string $base64): static { $this->qrCodeBase64 = $base64; return $this; }
    public function getExternalId(): ?string { return $this->externalId; }
    public function setExternalId(?string $id): static { $this->externalId = $id; return $this; }
    public function getGatewayResponse(): ?string { return $this->gatewayResponse; }
    public function setGatewayResponse(?string $json): static { $this->gatewayResponse = $json; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getPaidAt(): ?\DateTimeImmutable { return $this->paidAt; }
    public function setPaidAt(?\DateTimeImmutable $dt): static { $this->paidAt = $dt; return $this; }
    public function approve(): static { $this->status = self::STATUS_APPROVED; $this->paidAt = new \DateTimeImmutable(); return $this; }
}
