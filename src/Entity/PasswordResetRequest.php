<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PasswordResetRequestRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PasswordResetRequestRepository::class)]
#[ORM\Table(name: 'password_reset_requests')]
#[ORM\Index(name: 'IDX_password_reset_user', columns: ['user_id'])]
#[ORM\Index(name: 'IDX_password_reset_expiry', columns: ['expires_at'])]
class PasswordResetRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 32, unique: true)]
    private string $selector;

    #[ORM\Column(length: 64, unique: true)]
    private string $tokenHash;

    #[ORM\Column]
    private \DateTimeImmutable $requestedAt;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    public function __construct(
        User $user,
        string $selector,
        string $tokenHash,
        \DateTimeImmutable $requestedAt,
        \DateTimeImmutable $expiresAt,
    ) {
        $this->user = $user;
        $this->selector = $selector;
        $this->tokenHash = $tokenHash;
        $this->requestedAt = $requestedAt;
        $this->expiresAt = $expiresAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getSelector(): string { return $this->selector; }
    public function getTokenHash(): string { return $this->tokenHash; }
    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }
    public function getUsedAt(): ?\DateTimeImmutable { return $this->usedAt; }

    public function isUsable(string $token, \DateTimeImmutable $now): bool
    {
        return $this->usedAt === null
            && $this->expiresAt > $now
            && hash_equals($this->tokenHash, hash('sha256', $token));
    }

    public function markUsed(\DateTimeImmutable $usedAt): void
    {
        $this->usedAt = $usedAt;
    }
}
