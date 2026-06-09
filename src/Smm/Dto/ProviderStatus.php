<?php

declare(strict_types=1);

namespace App\Smm\Dto;

/**
 * DTO de status de um pedido retornado pelo provider SMM.
 *
 * Campos da API padrão (JustAnotherPanel, SMMKings, Peakerr, etc.):
 *   status      → string: "Pending", "Processing", "In progress",
 *                          "Completed", "Partial", "Canceled", "Cancelled"
 *   start_count → int: contagem inicial de seguidores/likes antes do pedido
 *   remains     → int: quantidade AINDA NÃO entregue
 *   charge      → float: custo cobrado
 *   error       → string: mensagem de erro (quando houver)
 *
 * Mapeamento de $state (normalizado para lowercase, sem acento):
 *   'pending'     → aguardando
 *   'processing'  → provider aceitou, ainda não começou
 *   'in_progress' → em andamento
 *   'partial'     → entregou parcialmente e encerrou
 *   'completed'   → entregue por completo
 *   'cancelled'   → cancelado pelo provider (inclui 'canceled' americano)
 */
final readonly class ProviderStatus
{
    public function __construct(
        /** Estado normalizado: pending | processing | in_progress | partial | completed | cancelled */
        public string  $state,
        /** Quantidade entregue até agora (quantity - remains) */
        public int     $delivered = 0,
        /** Motivo de cancelamento ou erro, quando houver */
        public ?string $reason    = null,
    ) {}

    /**
     * Constrói o DTO a partir do array bruto da API SMM.
     *
     * @param array{status?: string, start_count?: int|string, remains?: int|string, charge?: float|string, error?: string} $data
     */
    public static function fromArray(array $data, int $orderedQuantity = 0): self
    {
        $rawState   = strtolower(trim($data['status'] ?? 'pending'));
        $remains    = (int) ($data['remains'] ?? 0);
        $startCount = (int) ($data['start_count'] ?? 0);

        // Normaliza as variações de 'cancelled'/'canceled' e 'in progress'/'in_progress'
        $state = match (true) {
            in_array($rawState, ['canceled', 'cancelled'], true) => 'cancelled',
            in_array($rawState, ['in progress', 'in_progress'], true) => 'in_progress',
            default => $rawState,
        };

        // Quantidade entregue = (quantidade pedida - restante)
        // Quando start_count estiver disponível e o pedido ainda não tiver começado,
        // start_count representa o baseline anterior — usamos quantity - remains.
        $delivered = $orderedQuantity > 0
            ? max(0, $orderedQuantity - $remains)
            : max(0, $startCount - $remains);  // fallback sem quantity

        return new self(
            state:     $state,
            delivered: $delivered,
            reason:    $data['error'] ?? null,
        );
    }
}
