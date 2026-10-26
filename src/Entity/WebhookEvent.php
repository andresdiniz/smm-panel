<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'webhook_events')]
#[ORM\UniqueConstraint(name: 'uniq_webhook_event_external', columns: ['provider', 'external_id'])]
class WebhookEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 30)]
    private string $provider = 'asaas';

    #[ORM\Column(length: 120)]
    private string $externalId = '';

    #[ORM\Column(length: 80)]
    private string $event = '';

    #[ORM\Column(type: 'json')]
    private array $payload = [];

    #[ORM\Column(options: ['default' => false])]
    private bool $processed = false;

    #[ORM\Column]
    private \DateTimeImmutable $receivedAt;

    public function __construct()
    {
        $this->receivedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getProvider(): string { return $this->provider; }
    public function setProvider(string $provider): static { $this->provider = $provider; return $this; }
    public function getExternalId(): string { return $this->externalId; }
    public function setExternalId(string $externalId): static { $this->externalId = $externalId; return $this; }
    public function getEvent(): string { return $this->event; }
    public function setEvent(string $event): static { $this->event = $event; return $this; }
    public function getPayload(): array { return $this->payload; }
    public function setPayload(array $payload): static { $this->payload = $payload; return $this; }
    public function isProcessed(): bool { return $this->processed; }
    public function setProcessed(bool $processed): static { $this->processed = $processed; return $this; }
    public function getReceivedAt(): \DateTimeImmutable { return $this->receivedAt; }
}
