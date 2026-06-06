<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProviderCredentialRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Armazena credenciais de APIs externas no banco de dados.
 * Substitui variáveis .env para gateways de pagamento e providers SMM.
 *
 * type: 'payment_gateway' | 'smm_provider'
 * slug: 'asaas' | 'mercadopago' | 'pagbank' | 'smmkings' | 'justanother' | 'peakerr' | ...
 */
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

    /** Token adicional (ex: webhook secret, bearer token) */
    #[ORM\Column(length: 512, nullable: true)]
    private ?string $secretToken = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

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
    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $slug): static { $this->slug = $slug; return $this; }
    public function getBaseUrl(): string { return $this->baseUrl; }
    public function setBaseUrl(string $url): static { $this->baseUrl = $url; return $this; }
    public function getApiKey(): string { return $this->apiKey; }
    public function setApiKey(string $key): static { $this->apiKey = $key; return $this; }
    public function getSecretToken(): ?string { return $this->secretToken; }
    public function setSecretToken(?string $token): static { $this->secretToken = $token; return $this; }
    public function isActive(): bool { return $this->active; }
    public function setActive(bool $active): static { $this->active = $active; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
}
