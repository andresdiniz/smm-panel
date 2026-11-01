<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProviderCredentialRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProviderCredentialRepository::class)]
#[ORM\Table(name: 'provider_credentials')]
#[ORM\UniqueConstraint(name: 'uniq_provider_slug', columns: ['type', 'slug'])]
#[ORM\HasLifecycleCallbacks]
class ProviderCredential
{
    public const TYPE_PAYMENT = 'payment_gateway';
    public const TYPE_SMM     = 'smm_provider';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 40)]
    private string $type;

    #[ORM\Column(length: 60)]
    private string $slug;

    #[ORM\Column(length: 512)]
    private string $baseUrl;

    #[ORM\Column(length: 512)]
    private string $apiKey;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $secretToken = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column(length: 3, options: ['default' => 'BRL'])]
    private string $currency = 'BRL';

    #[ORM\Column(type: 'decimal', precision: 18, scale: 6, options: ['default' => 1])]
    private string $exchangeRate = '1';

    #[ORM\Column(type: 'decimal', precision: 6, scale: 3, options: ['default' => 0])]
    private string $defaultProfitPercent = '0';

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\PrePersist]
    public function onPrePersist(): void { $this->createdAt = new \DateTimeImmutable(); }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void { $this->updatedAt = new \DateTimeImmutable(); }

    public function getId(): ?int { return $this->id; }
    public function getType(): string { return $this->type; }
    public function setType(string $type): static { $this->type = $type; return $this; }

    public function getSlug(): string { return strtolower(trim($this->slug)); }
    public function setSlug(string $slug): static { $this->slug = strtolower(trim($slug)); return $this; }

    public function getBaseUrl(): string { return $this->baseUrl; }
    public function setBaseUrl(string $url): static { $this->baseUrl = $url; return $this; }
    public function getApiKey(): string { return $this->apiKey; }
    public function setApiKey(string $key): static { $this->apiKey = $key; return $this; }
    public function getSecretToken(): ?string { return $this->secretToken; }
    public function setSecretToken(?string $token): static { $this->secretToken = $token; return $this; }
    public function isActive(): bool { return $this->active; }
    public function setActive(bool $active): static { $this->active = $active; return $this; }

    public function getCurrency(): string { return strtoupper($this->currency); }
    public function setCurrency(string $currency): static { $this->currency = strtoupper(trim($currency)); return $this; }

    public function getExchangeRate(): float { return (float) $this->exchangeRate; }
    public function setExchangeRate(float $rate): static { $this->exchangeRate = number_format($rate, 6, '.', ''); return $this; }

    public function getDefaultProfitPercent(): float { return (float) $this->defaultProfitPercent; }
    public function setDefaultProfitPercent(float $percent): static { $this->defaultProfitPercent = number_format($percent, 3, '.', ''); return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
}
