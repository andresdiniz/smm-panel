<?php

declare(strict_types=1);

namespace App\Enum;

enum PaymentMethod: string
{
    case PIX           = 'pix';
    case CREDIT_CARD   = 'credit_card';
    case DEBIT_CARD    = 'debit_card';
    case WALLET        = 'wallet'; // saldo interno

    public function label(): string
    {
        return match($this) {
            self::PIX         => 'Pix',
            self::CREDIT_CARD => 'Cartão de crédito',
            self::DEBIT_CARD  => 'Cartão de débito',
            self::WALLET      => 'Saldo',
        };
    }
}
