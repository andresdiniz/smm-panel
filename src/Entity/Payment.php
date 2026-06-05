<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\PaymentMethod;
use App\Enum\PaymentStatus;
use App\Repository\PaymentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: PaymentRepository::class)]
#[ORM\Table(name: 'payments')]
#[ORM\HasLifecycleCallbacks]
class Payment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private int $id;

    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $uuid;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    // Pode ser um pedido ou um depósito de saldo
    #[ORM\ManyToOne(targetEntity: Order::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Order $order = null;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: PaymentMethod::class)]
    private PaymentMethod $method;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: PaymentStatus::class)]
    private PaymentStatus $status = PaymentStatus::PENDING;

    // Gateway utilizado: 'asaas', 'pagbank', 'stripe'
    #[ORM\Column(length: 30)]
    private string $gateway;

    // ID da cobrança no gateway
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $gatewayId = null;

    // Valor total (centavos)
    #[ORM\Column(type: Types::INTEGER)]
    private int $amountCents;

    // Taxa cobrada pelo gateway (centavos)
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $feeCents = 0;

    // Valor líquido recebido (centavos)
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $netCents = 0;

    // QR Code Pix (texto copia e cola)
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $pixPayload = null;

    // URL da imagem do QR Code
    #[ORM\Column(type: Types::STRING, length: 500, nullable: true)]
    private ?string $pixQrCodeUrl = null;

    // Expiração do Pix
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $pixExpiresAt = null;

    // Payload do webhook recebido do gateway
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $webhookPayload = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $paidAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->uuid      = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void { $this->updatedAt = new \DateTimeImmutable(); }

    public function markAsPaid(?\DateTimeImmutable $paidAt = null): void
    {
        $this->status = PaymentStatus::PAID;
        $this->paidAt = $paidAt ?? new \DateTimeImmutable();
    }

    public function markAsFailed(): void { $this->status = PaymentStatus::FAILED; }
    public function markAsRefunded(): void { $this->status = PaymentStatus::REFUNDED; }
    public function markAsChargeback(): void { $this->status = PaymentStatus::CHARGEBACK; }

    public function getId(): int { return $this->id; }
    public function getUuid(): Uuid { return $this->uuid; }
    public function getUser(): User { return $this->user; }
    public function setUser(User $u): self { $this->user = $u; return $this; }
    public function getOrder(): ?Order { return $this->order; }
    public function setOrder(?Order $o): self { $this->order = $o; return $this; }
    public function getMethod(): PaymentMethod { return $this->method; }
    public function setMethod(PaymentMethod $m): self { $this->method = $m; return $this; }
    public function getStatus(): PaymentStatus { return $this->status; }
    public function getGateway(): string { return $this->gateway; }
    public function setGateway(string $g): self { $this->gateway = $g; return $this; }
    public function getGatewayId(): ?string { return $this->gatewayId; }
    public function setGatewayId(?string $id): self { $this->gatewayId = $id; return $this; }
    public function getAmountCents(): int { return $this->amountCents; }
    public function setAmountCents(int $v): self { $this->amountCents = $v; return $this; }
    public function getFeeCents(): int { return $this->feeCents; }
    public function setFeeCents(int $v): self { $this->feeCents = $v; return $this; }
    public function getNetCents(): int { return $this->netCents; }
    public function setNetCents(int $v): self { $this->netCents = $v; return $this; }
    public function getPixPayload(): ?string { return $this->pixPayload; }
    public function setPixPayload(?string $v): self { $this->pixPayload = $v; return $this; }
    public function getPixQrCodeUrl(): ?string { return $this->pixQrCodeUrl; }
    public function setPixQrCodeUrl(?string $v): self { $this->pixQrCodeUrl = $v; return $this; }
    public function getPixExpiresAt(): ?\DateTimeImmutable { return $this->pixExpiresAt; }
    public function setPixExpiresAt(?\DateTimeImmutable $v): self { $this->pixExpiresAt = $v; return $this; }
    public function getWebhookPayload(): ?array { return $this->webhookPayload; }
    public function setWebhookPayload(?array $p): self { $this->webhookPayload = $p; return $this; }
    public function getPaidAt(): ?\DateTimeImmutable { return $this->paidAt; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
