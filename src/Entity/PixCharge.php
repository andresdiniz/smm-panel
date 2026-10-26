<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'pix_charges')]
#[ORM\UniqueConstraint(name: 'uniq_pix_charge_reference', columns: ['reference'])]
class PixCharge
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_REFUNDED = 'refunded';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 64)]
    private string $reference = '';

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $asaasPaymentId = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $amount = '0.00';

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $qrCode = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $copyPasteCode = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $invoiceUrl = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $confirmedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function setUser(User $user): static { $this->user = $user; return $this; }
    public function getReference(): string { return $this->reference; }
    public function setReference(string $reference): static { $this->reference = $reference; return $this; }
    public function getAsaasPaymentId(): ?string { return $this->asaasPaymentId; }
    public function setAsaasPaymentId(?string $id): static { $this->asaasPaymentId = $id; return $this; }
    public function getAmount(): string { return $this->amount; }
    public function setAmount(string $amount): static { $this->amount = $amount; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }
    public function getQrCode(): ?string { return $this->qrCode; }
    public function setQrCode(?string $qrCode): static { $this->qrCode = $qrCode; return $this; }
    public function getCopyPasteCode(): ?string { return $this->copyPasteCode; }
    public function setCopyPasteCode(?string $code): static { $this->copyPasteCode = $code; return $this; }
    public function getInvoiceUrl(): ?string { return $this->invoiceUrl; }
    public function setInvoiceUrl(?string $url): static { $this->invoiceUrl = $url; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getConfirmedAt(): ?\DateTimeImmutable { return $this->confirmedAt; }
    public function setConfirmedAt(?\DateTimeImmutable $date): static { $this->confirmedAt = $date; return $this; }
}
