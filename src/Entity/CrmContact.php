<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CrmContactStatus;
use App\Repository\CrmContactRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CrmContactRepository::class)]
#[ORM\Table(name: 'crm_contacts')]
#[ORM\HasLifecycleCallbacks]
class CrmContact
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private int $id;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'crmContacts')]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: CrmContactStatus::class)]
    private CrmContactStatus $status = CrmContactStatus::LEAD;

    // Rastreamento
    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    private ?string $utmSource = null;

    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    private ?string $utmMedium = null;

    #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
    private ?string $utmCampaign = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $referrer = null;

    // Última página visitada antes do cadastro
    #[ORM\Column(type: Types::STRING, length: 500, nullable: true)]
    private ?string $landingPage = null;

    // Eventos registrados (array de objetos: event, ts, payload)
    #[ORM\Column(type: Types::JSON)]
    private array $events = [];

    // Tags para segmentação de remarketing
    #[ORM\Column(type: Types::JSON)]
    private array $tags = [];

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void { $this->updatedAt = new \DateTimeImmutable(); }

    public function addEvent(string $event, array $payload = []): void
    {
        $this->events[] = [
            'event'   => $event,
            'ts'      => (new \DateTimeImmutable())->format('c'),
            'payload' => $payload,
        ];
    }

    public function addTag(string $tag): void
    {
        if (!in_array($tag, $this->tags, true)) {
            $this->tags[] = $tag;
        }
    }

    public function getId(): int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function setUser(User $u): self { $this->user = $u; return $this; }
    public function getStatus(): CrmContactStatus { return $this->status; }
    public function setStatus(CrmContactStatus $s): self { $this->status = $s; return $this; }
    public function getUtmSource(): ?string { return $this->utmSource; }
    public function setUtmSource(?string $v): self { $this->utmSource = $v; return $this; }
    public function getUtmMedium(): ?string { return $this->utmMedium; }
    public function setUtmMedium(?string $v): self { $this->utmMedium = $v; return $this; }
    public function getUtmCampaign(): ?string { return $this->utmCampaign; }
    public function setUtmCampaign(?string $v): self { $this->utmCampaign = $v; return $this; }
    public function getReferrer(): ?string { return $this->referrer; }
    public function setReferrer(?string $v): self { $this->referrer = $v; return $this; }
    public function getLandingPage(): ?string { return $this->landingPage; }
    public function setLandingPage(?string $v): self { $this->landingPage = $v; return $this; }
    public function getEvents(): array { return $this->events; }
    public function getTags(): array { return $this->tags; }
    public function setTags(array $t): self { $this->tags = $t; return $this; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $n): self { $this->notes = $n; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
