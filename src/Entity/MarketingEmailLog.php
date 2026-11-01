<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MarketingEmailLogRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MarketingEmailLogRepository::class)]
#[ORM\Table(name: 'marketing_email_logs')]
#[ORM\Index(columns: ['campaign_id', 'created_at'], name: 'idx_marketing_email_campaign')]
#[ORM\Index(columns: ['user_id', 'created_at'], name: 'idx_marketing_email_user')]
class MarketingEmailLog
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 36)]
    private string $campaignId;

    #[ORM\Column(length: 64, unique: true)]
    private string $token;

    #[ORM\Column(length: 180)]
    private string $subject;

    #[ORM\Column(length: 12)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $openedAt = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $openCount = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $clickCount = 0;

    /** @var array<string, string> */
    #[ORM\Column(type: 'json')]
    private array $links = [];

    /** @var array<string, string> Link ID mapped to its first click timestamp. */
    #[ORM\Column(type: 'json')]
    private array $clickedLinks = [];

    public function __construct(User $user, string $campaignId, string $subject)
    {
        $this->user = $user;
        $this->campaignId = $campaignId;
        $this->subject = $subject;
        $this->token = bin2hex(random_bytes(32));
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getCampaignId(): string { return $this->campaignId; }
    public function getToken(): string { return $this->token; }
    public function getSubject(): string { return $this->subject; }
    public function getStatus(): string { return $this->status; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getSentAt(): ?\DateTimeImmutable { return $this->sentAt; }
    public function getOpenedAt(): ?\DateTimeImmutable { return $this->openedAt; }
    public function getOpenCount(): int { return $this->openCount; }
    public function getClickCount(): int { return $this->clickCount; }

    /** @return array<string, string> */
    public function getLinks(): array { return $this->links; }

    /** @return array<string, string> */
    public function getClickedLinks(): array { return $this->clickedLinks; }

    /** @param array<string, string> $links */
    public function setLinks(array $links): void
    {
        $this->links = $links;
    }

    public function markSent(): void
    {
        $this->status = self::STATUS_SENT;
        $this->sentAt = new \DateTimeImmutable();
    }

    public function markFailed(): void
    {
        $this->status = self::STATUS_FAILED;
    }

    public function recordOpen(): void
    {
        ++$this->openCount;
        $this->openedAt ??= new \DateTimeImmutable();
    }

    public function recordClick(string $linkId): void
    {
        if (!isset($this->links[$linkId])) {
            return;
        }

        ++$this->clickCount;
        $this->clickedLinks[$linkId] ??= (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
    }
}
