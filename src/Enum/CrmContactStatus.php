<?php

declare(strict_types=1);

namespace App\Enum;

enum CrmContactStatus: string
{
    case LEAD       = 'lead';
    case PROSPECT   = 'prospect';
    case CUSTOMER   = 'customer';
    case CHURNED    = 'churned';
    case BLOCKED    = 'blocked';

    public function label(): string
    {
        return match($this) {
            self::LEAD     => 'Lead',
            self::PROSPECT => 'Prospect',
            self::CUSTOMER => 'Cliente',
            self::CHURNED  => 'Churn',
            self::BLOCKED  => 'Bloqueado',
        };
    }
}
