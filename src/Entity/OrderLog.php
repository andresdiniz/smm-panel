<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OrderLogRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OrderLogRepository::class)]
#[ORM\Table(name: 'order_logs')]
#[ORM\Index(columns: ['order_id'], name: 'idx_order_logs_order')]
#[ORM\Index(columns: ['provider'], name: 'idx_order_logs_provider')]
#[ORM\Index(columns: ['action'], name: 'idx_order_logs_action')]
#[ORM\Index(columns: ['created_at'], name: 'idx_order_logs_created_at')]
class OrderLog
{
    public const ACTION_ADD    = 'add';
    public const ACTION_STATUS = 'status';
    public const ACTION_SYNC   = 'sync';
    public const ACTION_OTHER  = 'other';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\ManyToOne(targetEntity: Order::class, inversedBy: 'orderLogs')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Order $order = null;

    #[ORM\Column(type: 'string', length: 64)]
    private string $provider = '';

    #[ORM\Column(type: 'string', length: 32)]
    private string $action = self::ACTION_ADD;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $httpStatus = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $responseBody = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $elapsedMs = null;

    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $retryCount = 0;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $context = null;

    #[ORM\Column(type: 'datetime_immutable', name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): int { return $this->id; }
    public function getOrder(): ?Order { return $this->order; }
    public function setOrder(?Order $order): static { $this->order = $order; return $this; }
    public function getProvider(): string { return $this->provider; }
    public function setProvider(string $provider): static { $this->provider = $provider; return $this; }
    public function getAction(): string { return $this->action; }
    public function setAction(string $action): static { $this->action = $action; return $this; }
    public function getHttpStatus(): ?int { return $this->httpStatus; }
    public function setHttpStatus(?int $httpStatus): static { $this->httpStatus = $httpStatus; return $this; }
    public function getResponseBody(): ?array { return $this->responseBody; }
    public function setResponseBody(?array $responseBody): static { $this->responseBody = $responseBody; return $this; }
    public function getErrorMessage(): ?string { return $this->errorMessage; }
    public function setErrorMessage(?string $errorMessage): static { $this->errorMessage = $errorMessage; return $this; }
    public function getElapsedMs(): ?int { return $this->elapsedMs; }
    public function setElapsedMs(?int $elapsedMs): static { $this->elapsedMs = $elapsedMs; return $this; }
    public function getRetryCount(): int { return $this->retryCount; }
    public function setRetryCount(int $retryCount): static { $this->retryCount = $retryCount; return $this; }
    public function getContext(): ?array { return $this->context; }
    public function setContext(?array $context): static { $this->context = $context; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function hasError(): bool { return $this->errorMessage !== null; }
}
