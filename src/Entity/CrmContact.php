<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CrmContactRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CrmContactRepository::class)]
#[ORM\Table(name: 'crm_contact')]
#[ORM\Index(columns: ['user_id'], name: 'idx_crm_user')]
class CrmContact
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** @var string[] */
    #[ORM\Column(type: 'json')]
    private array $tags = [];

    /** @var array<int, array{event: string, payload: array<string,mixed>, at: string}> */
    #[ORM\Column(type: 'json')]
    private array $timeline = [];

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $utmSource = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $utmCampaign = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $utmMedium = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $user)
    {
        $this->user      = $user;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }

    public function getTags(): array { return $this->tags; }

    public function addTag(string $tag): void
    {
        if (!in_array($tag, $this->tags, true)) {
            $this->tags[] = $tag;
        }
        $this->touch();
    }

    public function removeTag(string $tag): void
    {
        $this->tags = array_values(array_filter($this->tags, fn(string $t) => $t !== $tag));
        $this->touch();
    }

    public function getTimeline(): array { return $this->timeline; }

    public function addEvent(string $event, array $payload = []): void
    {
        $this->timeline[] = [
            'event'   => $event,
            'payload' => $payload,
            'at'      => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];
        $this->touch();
    }

    public function getUtmSource(): ?string { return $this->utmSource; }
    public function setUtmSource(?string $v): void { $this->utmSource = $v; $this->touch(); }

    public function getUtmCampaign(): ?string { return $this->utmCampaign; }
    public function setUtmCampaign(?string $v): void { $this->utmCampaign = $v; $this->touch(); }

    public function getUtmMedium(): ?string { return $this->utmMedium; }
    public function setUtmMedium(?string $v): void { $this->utmMedium = $v; $this->touch(); }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
