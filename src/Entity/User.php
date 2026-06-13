<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\HasLifecycleCallbacks]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    private string $email;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column]
    private array $roles = [];

    #[ORM\Column]
    private string $password;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\OneToOne(mappedBy: 'user', cascade: ['persist', 'remove'])]
    private ?Wallet $wallet = null;

    // ── Affiliate fields ────────────────────────────────────────────────────

    /** Código único do afiliado (ex: "ab3f9x") */
    #[ORM\Column(length: 16, unique: true, nullable: true)]
    private ?string $affiliateCode = null;

    /**
     * Taxa de comissão personalizada (ex: 0.10 = 10%).
     * null = usa a taxa padrão definida no .env (AFFILIATE_DEFAULT_RATE).
     */
    #[ORM\Column(type: 'decimal', precision: 5, scale: 4, nullable: true)]
    private ?string $affiliateCommissionRate = null;

    /** Quem indicou este usuário */
    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'referredUsers')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $referredBy = null;

    /** Usuários que foram indicados por este afiliado */
    #[ORM\OneToMany(mappedBy: 'referredBy', targetEntity: self::class)]
    private Collection $referredUsers;

    /** Comissões geradas por este afiliado */
    #[ORM\OneToMany(mappedBy: 'affiliate', targetEntity: AffiliateCommission::class, cascade: ['remove'])]
    private Collection $affiliateCommissions;

    // ────────────────────────────────────────────────────────────────────────

    public function __construct()
    {
        $this->referredUsers        = new ArrayCollection();
        $this->affiliateCommissions = new ArrayCollection();
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return sprintf('%s (%s)', $this->name, $this->email);
    }

    public function getId(): ?int { return $this->id; }
    public function getEmail(): string { return $this->email; }
    public function setEmail(string $email): static { $this->email = $email; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }
    public function getUserIdentifier(): string { return $this->email; }
    public function getRoles(): array { $roles = $this->roles; $roles[] = 'ROLE_USER'; return array_unique($roles); }
    public function setRoles(array $roles): static { $this->roles = $roles; return $this; }
    public function getPassword(): string { return $this->password; }
    public function setPassword(string $password): static { $this->password = $password; return $this; }
    public function eraseCredentials(): void {}
    public function isActive(): bool { return $this->active; }
    public function setActive(bool $active): static { $this->active = $active; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
    public function getWallet(): ?Wallet { return $this->wallet; }
    public function setWallet(?Wallet $wallet): static { $this->wallet = $wallet; return $this; }

    // ── Affiliate getters/setters ────────────────────────────────────────────

    public function getAffiliateCode(): ?string { return $this->affiliateCode; }
    public function setAffiliateCode(?string $code): static { $this->affiliateCode = $code; return $this; }

    public function getAffiliateCommissionRate(): ?string { return $this->affiliateCommissionRate; }
    public function setAffiliateCommissionRate(?string $rate): static { $this->affiliateCommissionRate = $rate; return $this; }

    public function getReferredBy(): ?User { return $this->referredBy; }
    public function setReferredBy(?User $referredBy): static { $this->referredBy = $referredBy; return $this; }

    /** @return Collection<int, User> */
    public function getReferredUsers(): Collection { return $this->referredUsers; }

    /** @return Collection<int, AffiliateCommission> */
    public function getAffiliateCommissions(): Collection { return $this->affiliateCommissions; }

    /** Retorna a taxa efetiva (personalizada ou padrão do .env) */
    public function getEffectiveCommissionRate(float $defaultRate): float
    {
        return $this->affiliateCommissionRate !== null
            ? (float) $this->affiliateCommissionRate
            : $defaultRate;
    }

    /** Saldo pendente de comissões (status=pending) */
    public function getPendingCommissionAmount(): float
    {
        $total = 0.0;
        foreach ($this->affiliateCommissions as $c) {
            if ($c->getStatus() === AffiliateCommission::STATUS_PENDING) {
                $total += (float) $c->getAmount();
            }
        }
        return $total;
    }

    /** Total já pago ao afiliado */
    public function getPaidCommissionAmount(): float
    {
        $total = 0.0;
        foreach ($this->affiliateCommissions as $c) {
            if ($c->getStatus() === AffiliateCommission::STATUS_PAID) {
                $total += (float) $c->getAmount();
            }
        }
        return $total;
    }
}
