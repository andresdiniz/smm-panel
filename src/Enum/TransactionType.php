<?php

declare(strict_types=1);

namespace App\Enum;

enum TransactionType: string
{
    case CREDIT    = 'credit';   // Depósito, pagamento aprovado
    case DEBIT     = 'debit';    // Pedido, taxa
    case REFUND    = 'refund';   // Devolução
    case BONUS     = 'bonus';    // Crédito promocional

    public function label(): string
    {
        return match($this) {
            self::CREDIT => 'Crédito',
            self::DEBIT  => 'Débito',
            self::REFUND => 'Reembolso',
            self::BONUS  => 'Bônus',
        };
    }
}
