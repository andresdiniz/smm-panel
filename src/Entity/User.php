<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\HasLifecycleCallbacks]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private int $id;

    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $uuid;

    #[ORM\Column(length: 180, unique: true)]
    private string $email;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(type: Types::JSON)]
    private array $roles = [];

    #[ORM\Column]
    private string $password;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $emailVerified = false;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $emailVerificationToken = null;

    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(type: Types::STRING, length: 14, nullable: true)]
    private ?string $document = null; // CPF ou CNPJ

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    // CRM
    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    private ?string $utmSource = null;

    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    private ?string $utmMedium = null;

    #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
    private ?string $utmCampaign = null;

    #[ORM\OneToMany(targetEntity: Order::class, mappedBy: 'user')]
    private Collection $orders;

    #[ORM\OneToOne(targetEntity: Wallet::class, mappedBy: 'user', cascade: ['persist'])]
    private ?Wallet $wallet = null;

    #[ORM\OneToMany(targetEntity: CrmContact::class, mappedBy: 'user')]
    private Collection $crmContacts;

    public function __construct()
    {
        $this->uuid        = Uuid::v7();
        $this->createdAt   = new \DateTimeImmutable();
        $this->updatedAt   = new \DateTimeImmutable();
        $this->orders      = new ArrayCollection();
        $this->crmContacts = new ArrayCollection();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): int { return $this->id; }
    public function getUuid(): Uuid { return $this->uuid; }
    public function getEmail(): string { return $this->email; }
    public function setEmail(string $email): self { $this->email = $email; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }
    public function getRoles(): array { return array_unique(array_merge($this->roles, ['ROLE_USER'])); }
    public function setRoles(array $roles): self { $this->roles = $roles; return $this; }
    public function getPassword(): string { return $this->password; }
    public function setPassword(string $password): self { $this->password = $password; return $this; }
    public function getUserIdentifier(): string { return $this->email; }
    public function eraseCredentials(): void {}
    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $v): self { $this->isActive = $v; return $this; }
    public function isEmailVerified(): bool { return $this->emailVerified; }
    public function setEmailVerified(bool $v): self { $this->emailVerified = $v; return $this; }
    public function getEmailVerificationToken(): ?string { return $this->emailVerificationToken; }
    public function setEmailVerificationToken(?string $t): self { $this->emailVerificationToken = $t; return $this; }
    public function getPhone(): ?string { return $this->phone; }
    public function setPhone(?string $p): self { $this->phone = $p; return $this; }
    public function getDocument(): ?string { return $this->document; }
    public function setDocument(?string $d): self { $this->document = $d; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function getLastLoginAt(): ?\DateTimeImmutable { return $this->lastLoginAt; }
    public function setLastLoginAt(?\DateTimeImmutable $d): self { $this->lastLoginAt = $d; return $this; }
    public function getUtmSource(): ?string { return $this->utmSource; }
    public function setUtmSource(?string $v): self { $this->utmSource = $v; return $this; }
    public function getUtmMedium(): ?string { return $this->utmMedium; }
    public function setUtmMedium(?string $v): self { $this->utmMedium = $v; return $this; }
    public function getUtmCampaign(): ?string { return $this->utmCampaign; }
    public function setUtmCampaign(?string $v): self { $this->utmCampaign = $v; return $this; }
    public function getOrders(): Collection { return $this->orders; }
    public function getWallet(): ?Wallet { return $this->wallet; }
    public function setWallet(Wallet $w): self { $this->wallet = $w; return $this; }
    public function getCrmContacts(): Collection { return $this->crmContacts; }
    public function __toString(): string { return $this->name . ' <' . $this->email . '>'; }
}
