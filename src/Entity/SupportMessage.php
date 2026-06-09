<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\SupportMessageSender;
use App\Repository\SupportMessageRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SupportMessageRepository::class)]
#[ORM\Table(name: 'support_message')]
#[ORM\HasLifecycleCallbacks]
class SupportMessage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SupportTicket::class, inversedBy: 'messages')]
    #[ORM\JoinColumn(nullable: false)]
    private SupportTicket $ticket;

    #[ORM\Column(length: 20, enumType: SupportMessageSender::class)]
    private SupportMessageSender $sender;

    #[ORM\Column(type: 'text')]
    private string $body;

    #[ORM\Column]
    private bool $readByAdmin = false;

    #[ORM\Column]
    private bool $readByUser = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
        // Mensagem do admin já nasce lida pelo admin, e vice-versa
        if ($this->sender === SupportMessageSender::Admin) {
            $this->readByAdmin = true;
        } else {
            $this->readByUser = true;
        }
    }

    public function getId(): ?int { return $this->id; }

    public function getTicket(): SupportTicket { return $this->ticket; }
    public function setTicket(SupportTicket $ticket): static { $this->ticket = $ticket; return $this; }

    public function getSender(): SupportMessageSender { return $this->sender; }
    public function setSender(SupportMessageSender $sender): static { $this->sender = $sender; return $this; }

    public function getBody(): string { return $this->body; }
    public function setBody(string $body): static { $this->body = $body; return $this; }

    public function isReadByAdmin(): bool { return $this->readByAdmin; }
    public function markReadByAdmin(): static { $this->readByAdmin = true; return $this; }

    public function isReadByUser(): bool { return $this->readByUser; }
    public function markReadByUser(): static { $this->readByUser = true; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function isFromAdmin(): bool { return $this->sender === SupportMessageSender::Admin; }
    public function isFromUser(): bool { return $this->sender === SupportMessageSender::User; }
}
