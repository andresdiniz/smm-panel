<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CronLogRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Registra cada execução de cron/command com duração, resultado e contexto.
 */
#[ORM\Entity(repositoryClass: CronLogRepository::class)]
#[ORM\Table(name: 'cron_logs')]
#[ORM\Index(columns: ['command'], name: 'idx_cron_logs_command')]
#[ORM\Index(columns: ['started_at'], name: 'idx_cron_logs_started_at')]
#[ORM\Index(columns: ['success'], name: 'idx_cron_logs_success')]
class CronLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Nome do comando/cron, ex: app:sync-orders */
    #[ORM\Column(length: 128)]
    private string $command;

    /** true = finalizou sem exception, false = falhou */
    #[ORM\Column]
    private bool $success = true;

    /** Duração em milissegundos */
    #[ORM\Column(nullable: true)]
    private ?int $durationMs = null;

    /** Quantos itens processados (pedidos sincronizados, emails enviados etc.) */
    #[ORM\Column(nullable: true)]
    private ?int $itemsProcessed = null;

    /** Mensagem de erro se success=false */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    /** Output resumido do comando (últimas N linhas) */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $output = null;

    /** Dados extras livres (JSON) */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $context = null;

    #[ORM\Column(name: 'started_at')]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(name: 'finished_at', nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    public function __construct(string $command)
    {
        $this->command   = $command;
        $this->startedAt = new \DateTimeImmutable();
    }

    public function finish(bool $success, ?string $errorMessage = null): static
    {
        $this->success      = $success;
        $this->errorMessage = $errorMessage;
        $this->finishedAt   = new \DateTimeImmutable();
        if ($this->finishedAt !== null) {
            $this->durationMs = (int) (($this->finishedAt->getTimestamp() - $this->startedAt->getTimestamp()) * 1000
                + ($this->finishedAt->format('u') - $this->startedAt->format('u')) / 1000);
        }
        return $this;
    }

    public function getId(): ?int { return $this->id; }
    public function getCommand(): string { return $this->command; }
    public function isSuccess(): bool { return $this->success; }
    public function getDurationMs(): ?int { return $this->durationMs; }
    public function setDurationMs(?int $ms): static { $this->durationMs = $ms; return $this; }
    public function getItemsProcessed(): ?int { return $this->itemsProcessed; }
    public function setItemsProcessed(?int $n): static { $this->itemsProcessed = $n; return $this; }
    public function getErrorMessage(): ?string { return $this->errorMessage; }
    public function getOutput(): ?string { return $this->output; }
    public function setOutput(?string $output): static { $this->output = $output; return $this; }
    public function getContext(): ?array { return $this->context; }
    public function setContext(?array $context): static { $this->context = $context; return $this; }
    public function getStartedAt(): \DateTimeImmutable { return $this->startedAt; }
    public function getFinishedAt(): ?\DateTimeImmutable { return $this->finishedAt; }
}
