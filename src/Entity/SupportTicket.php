<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\SupportTicketPriority;
use App\Enum\SupportTicketStatus;
use App\Repository\SupportTicketRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SupportTicketRepository::class)]
#[ORM\Table(name: 'support_ticket')]
#[ORM\HasLifecycleCallbacks]
class SupportTicket
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\Column(length: 180)]
    private string $subject;

    #[ORM\Column(length: 20, enumType: SupportTicketStatus::class)]
    private SupportTicketStatus $status = SupportTicketStatus::Open;

    #[ORM\Column(length: 20, enumType: SupportTicketPriority::class)]
    private SupportTicketPriority $priority = SupportTicketPriority::Normal;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    /** @var Collection<int, SupportMessage> */
    #[ORM\OneToMany(mappedBy: 'ticket', targetEntity: SupportMessage::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $messages;

    public function __construct()
    {
        $this->messages = new ArrayCollection();
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return sprintf('#%d – %s', $this->id ?? 0, $this->subject);
    }

    public function getId(): ?int { return $this->id; }

    public function getUser(): User { return $this->user; }
    public function setUser(User $user): static { $this->user = $user; return $this; }

    public function getSubject(): string { return $this->subject; }
    public function setSubject(string $subject): static { $this->subject = $subject; return $this; }

    public function getStatus(): SupportTicketStatus { return $this->status; }
    public function setStatus(SupportTicketStatus $status): static
    {
        $this->status = $status;
        if ($status === SupportTicketStatus::Closed && $this->closedAt === null) {
            $this->closedAt = new \DateTimeImmutable();
        }
        return $this;
    }

    public function getPriority(): SupportTicketPriority { return $this->priority; }
    public function setPriority(SupportTicketPriority $priority): static { $this->priority = $priority; return $this; }

    public function getClosedAt(): ?\DateTimeImmutable { return $this->closedAt; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }

    /** @return Collection<int, SupportMessage> */
    public function getMessages(): Collection { return $this->messages; }

    public function addMessage(SupportMessage $message): static
    {
        if (!$this->messages->contains($message)) {
            $this->messages->add($message);
            $message->setTicket($this);
        }
        return $this;
    }

    public function removeMessage(SupportMessage $message): static
    {
        $this->messages->removeElement($message);
        return $this;
    }

    public function isOpen(): bool
    {
        return $this->status !== SupportTicketStatus::Closed;
    }

    public function countUnreadByAdmin(): int
    {
        return $this->messages->filter(
            fn(SupportMessage $m) => $m->getSender()->value === 'user' && !$m->isReadByAdmin()
        )->count();
    }

    public function countUnreadByUser(): int
    {
        return $this->messages->filter(
            fn(SupportMessage $m) => $m->getSender()->value === 'admin' && !$m->isReadByUser()
        )->count();
    }
}
