<?php

declare(strict_types=1);

namespace App\Enum;

enum PaymentStatus: string
{
    case PENDING    = 'pending';
    case PAID       = 'paid';
    case FAILED     = 'failed';
    case REFUNDED   = 'refunded';
    case CHARGEBACK = 'chargeback';
    case EXPIRED    = 'expired';

    public function label(): string
    {
        return match($this) {
            self::PENDING    => 'Pendente',
            self::PAID       => 'Pago',
            self::FAILED     => 'Falhou',
            self::REFUNDED   => 'Reembolsado',
            self::CHARGEBACK => 'Chargeback',
            self::EXPIRED    => 'Expirado',
        };
    }
}
