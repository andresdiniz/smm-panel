<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'services')]
class Service
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $name = '';

    #[ORM\Column(length: 40)]
    private string $category = 'Instagram';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 4)]
    private string $pricePer1000 = '0.0000';

    #[ORM\Column(type: 'integer')]
    private int $minQuantity = 100;

    #[ORM\Column(type: 'integer')]
    private int $maxQuantity = 100000;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $providerServiceId = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $refill = false;

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }
    public function getCategory(): string { return $this->category; }
    public function setCategory(string $category): static { $this->category = $category; return $this; }
    public function getPricePer1000(): string { return $this->pricePer1000; }
    public function setPricePer1000(string $price): static { $this->pricePer1000 = $price; return $this; }
    public function getMinQuantity(): int { return $this->minQuantity; }
    public function setMinQuantity(int $quantity): static { $this->minQuantity = $quantity; return $this; }
    public function getMaxQuantity(): int { return $this->maxQuantity; }
    public function setMaxQuantity(int $quantity): static { $this->maxQuantity = $quantity; return $this; }
    public function getProviderServiceId(): ?string { return $this->providerServiceId; }
    public function setProviderServiceId(?string $id): static { $this->providerServiceId = $id; return $this; }
    public function isActive(): bool { return $this->active; }
    public function setActive(bool $active): static { $this->active = $active; return $this; }
    public function hasRefill(): bool { return $this->refill; }
    public function setRefill(bool $refill): static { $this->refill = $refill; return $this; }
}
