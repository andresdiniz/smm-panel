<?php

declare(strict_types=1);

namespace App\Enum;

enum OrderStatus: string
{
    case CREATED          = 'created';
    case PENDING_PAYMENT  = 'pending_payment';
    case PAID             = 'paid';
    case QUEUED           = 'queued';
    case PROCESSING       = 'processing';
    case PARTIAL          = 'partial';
    case COMPLETED        = 'completed';
    case FAILED           = 'failed';
    case CANCELLED        = 'cancelled';
    case REFUNDED         = 'refunded';

    public function label(): string
    {
        return match($this) {
            self::CREATED         => 'Criado',
            self::PENDING_PAYMENT => 'Aguardando pagamento',
            self::PAID            => 'Pago',
            self::QUEUED          => 'Na fila',
            self::PROCESSING      => 'Processando',
            self::PARTIAL         => 'Parcial',
            self::COMPLETED       => 'Concluído',
            self::FAILED          => 'Falhou',
            self::CANCELLED       => 'Cancelado',
            self::REFUNDED        => 'Reembolsado',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::CREATED, self::PENDING_PAYMENT => 'warning',
            self::PAID, self::QUEUED             => 'info',
            self::PROCESSING                     => 'primary',
            self::COMPLETED                      => 'success',
            self::PARTIAL                        => 'warning',
            self::FAILED, self::CANCELLED        => 'danger',
            self::REFUNDED                       => 'secondary',
        };
    }
}
