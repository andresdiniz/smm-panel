<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ServiceCategoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ServiceCategoryRepository::class)]
#[ORM\Table(name: 'service_categories')]
class ServiceCategory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private int $id;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(length: 120, unique: true)]
    private string $slug;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $sortOrder = 0;

    public function getId(): int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $n): self { $this->name = $n; return $this; }
    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $s): self { $this->slug = $s; return $this; }
    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $v): self { $this->isActive = $v; return $this; }
    public function getSortOrder(): int { return $this->sortOrder; }
    public function setSortOrder(int $v): self { $this->sortOrder = $v; return $this; }
    public function __toString(): string { return $this->name; }
}
