<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PaymentRefundRequestRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PaymentRefundRequestRepository::class)]
#[ORM\Table(name: 'payment_refund_requests')]
#[ORM\Index(columns: ['status'], name: 'idx_payment_refund_status')]
#[ORM\Index(columns: ['requested_at'], name: 'idx_payment_refund_requested_at')]
class PaymentRefundRequest
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_COMPLETED = 'completed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Payment $payment;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'text')]
    private string $reason;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $adminNote = null;

    #[ORM\Column]
    private \DateTimeImmutable $requestedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $reviewedAt = null;

    public function __construct(Payment $payment, string $reason)
    {
        $this->payment = $payment;
        $this->user = $payment->getUser();
        $this->reason = $reason;
        $this->requestedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getPayment(): Payment { return $this->payment; }
    public function getUser(): User { return $this->user; }
    public function getReason(): string { return $this->reason; }
    public function getStatus(): string { return $this->status; }
    public function getAdminNote(): ?string { return $this->adminNote; }
    public function getRequestedAt(): \DateTimeImmutable { return $this->requestedAt; }
    public function getReviewedAt(): ?\DateTimeImmutable { return $this->reviewedAt; }

    public function markProcessing(?string $adminNote = null): void
    {
        $this->status = self::STATUS_PROCESSING;
        $this->adminNote = $adminNote;
        $this->reviewedAt = new \DateTimeImmutable();
    }

    public function returnToPending(string $adminNote): void
    {
        $this->status = self::STATUS_PENDING;
        $this->adminNote = $adminNote;
        $this->reviewedAt = null;
    }

    public function setAdminNote(?string $adminNote): void
    {
        $this->adminNote = $adminNote;
    }

    public function markCompleted(): void
    {
        $this->status = self::STATUS_COMPLETED;
        $this->reviewedAt = new \DateTimeImmutable();
    }

    public function reject(string $adminNote): void
    {
        $this->status = self::STATUS_REJECTED;
        $this->adminNote = $adminNote;
        $this->reviewedAt = new \DateTimeImmutable();
    }
}
