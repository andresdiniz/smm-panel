<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ServiceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ServiceRepository::class)]
#[ORM\Table(name: 'services')]
class Service
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private int $id;

    #[ORM\ManyToOne(targetEntity: ServiceCategory::class, inversedBy: 'services')]
    #[ORM\JoinColumn(nullable: false)]
    private ServiceCategory $category;

    #[ORM\Column(length: 200)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    // ID do serviço no provider externo
    #[ORM\Column(length: 50)]
    private string $externalServiceId;

    // Slug do provider: 'peakerr', 'smmhaven', etc.
    #[ORM\Column(length: 50)]
    private string $providerSlug;

    // Preço de custo (provider) em centavos
    #[ORM\Column(type: Types::INTEGER)]
    private int $costPerThousandCents;

    // Preço de venda em centavos por 1000
    #[ORM\Column(type: Types::INTEGER)]
    private int $pricePerThousandCents;

    #[ORM\Column(type: Types::INTEGER)]
    private int $minQuantity = 100;

    #[ORM\Column(type: Types::INTEGER)]
    private int $maxQuantity = 1000000;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $sortOrder = 0;

    public function getId(): int { return $this->id; }
    public function getCategory(): ServiceCategory { return $this->category; }
    public function setCategory(ServiceCategory $c): self { $this->category = $c; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $n): self { $this->name = $n; return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $d): self { $this->description = $d; return $this; }
    public function getExternalServiceId(): string { return $this->externalServiceId; }
    public function setExternalServiceId(string $v): self { $this->externalServiceId = $v; return $this; }
    public function getProviderSlug(): string { return $this->providerSlug; }
    public function setProviderSlug(string $v): self { $this->providerSlug = $v; return $this; }
    public function getCostPerThousandCents(): int { return $this->costPerThousandCents; }
    public function setCostPerThousandCents(int $v): self { $this->costPerThousandCents = $v; return $this; }
    public function getPricePerThousandCents(): int { return $this->pricePerThousandCents; }
    public function setPricePerThousandCents(int $v): self { $this->pricePerThousandCents = $v; return $this; }
    public function getMinQuantity(): int { return $this->minQuantity; }
    public function setMinQuantity(int $v): self { $this->minQuantity = $v; return $this; }
    public function getMaxQuantity(): int { return $this->maxQuantity; }
    public function setMaxQuantity(int $v): self { $this->maxQuantity = $v; return $this; }
    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $v): self { $this->isActive = $v; return $this; }
    public function getSortOrder(): int { return $this->sortOrder; }
    public function setSortOrder(int $v): self { $this->sortOrder = $v; return $this; }

    public function calculatePrice(int $quantity): int
    {
        return (int) ceil(($quantity / 1000) * $this->pricePerThousandCents);
    }

    public function __toString(): string { return $this->name; }
}
