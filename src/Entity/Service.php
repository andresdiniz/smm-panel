<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ServiceRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ServiceRepository::class)]
#[ORM\Table(name: 'services')]
class Service
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $category = null;

    #[ORM\Column(length: 200)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'bigint')]
    private int $pricePerThousandCents;

    #[ORM\Column]
    private int $minQty;

    #[ORM\Column]
    private int $maxQty;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $externalServiceId = null;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $providerSlug = null;

    #[ORM\Column(type: 'decimal', precision: 8, scale: 3, nullable: true)]
    private ?string $profitPercent = null;

    public function __toString(): string
    {
        return $this->name ?? '';
    }

    public function getId(): ?int { return $this->id; }
    public function getCategory(): ?string { return $this->category; }
    public function setCategory(?string $cat): static { $this->category = $cat; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $desc): static { $this->description = $desc; return $this; }
    public function getPricePerThousandCents(): int { return $this->pricePerThousandCents; }
    public function setPricePerThousandCents(int $cents): static { $this->pricePerThousandCents = $cents; return $this; }
    public function getMinQty(): int { return $this->minQty; }
    public function setMinQty(int $qty): static { $this->minQty = $qty; return $this; }
    public function getMaxQty(): int { return $this->maxQty; }
    public function setMaxQty(int $qty): static { $this->maxQty = $qty; return $this; }
    public function isActive(): bool { return $this->active; }
    public function setActive(bool $active): static { $this->active = $active; return $this; }
    public function getExternalServiceId(): ?string { return $this->externalServiceId; }
    public function setExternalServiceId(?string $id): static { $this->externalServiceId = $id; return $this; }

    public function getProviderSlug(): ?string
    {
        return $this->providerSlug !== null
            ? strtolower(trim($this->providerSlug))
            : null;
    }

    public function setProviderSlug(?string $slug): static
    {
        $this->providerSlug = $slug !== null ? strtolower(trim($slug)) : null;
        return $this;
    }

    public function getProfitPercent(): ?float
    {
        return $this->profitPercent !== null ? (float) $this->profitPercent : null;
    }

    public function setProfitPercent(?float $percent): static
    {
        $this->profitPercent = $percent === null
            ? null
            : number_format($percent, 3, '.', '');

        return $this;
    }

    public function calculatePriceCents(int $quantity): int
    {
        return (int) ceil($this->pricePerThousandCents * $quantity / 1000);
    }
}
