<?php

declare(strict_types=1);

namespace App\Enum;

enum SupportTicketStatus: string
{
    case Open         = 'open';
    case InProgress   = 'in_progress';
    case WaitingUser  = 'waiting_user';
    case Closed       = 'closed';

    public function label(): string
    {
        return match($this) {
            self::Open        => 'Aberto',
            self::InProgress  => 'Em andamento',
            self::WaitingUser => 'Aguardando usuário',
            self::Closed      => 'Fechado',
        };
    }

    public function badgeClass(): string
    {
        return match($this) {
            self::Open        => 'badge-info',
            self::InProgress  => 'badge-warning',
            self::WaitingUser => 'badge-secondary',
            self::Closed      => 'badge-success',
        };
    }
}
