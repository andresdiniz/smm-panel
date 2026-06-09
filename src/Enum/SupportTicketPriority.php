<?php

declare(strict_types=1);

namespace App\Enum;

enum SupportTicketPriority: string
{
    case Low    = 'low';
    case Normal = 'normal';
    case High   = 'high';
    case Urgent = 'urgent';

    public function label(): string
    {
        return match($this) {
            self::Low    => 'Baixa',
            self::Normal => 'Normal',
            self::High   => 'Alta',
            self::Urgent => 'Urgente',
        };
    }

    public function badgeClass(): string
    {
        return match($this) {
            self::Low    => 'badge-secondary',
            self::Normal => 'badge-info',
            self::High   => 'badge-warning',
            self::Urgent => 'badge-danger',
        };
    }
}
