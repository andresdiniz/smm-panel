<?php

declare(strict_types=1);

namespace App\Enum;

enum SupportMessageSender: string
{
    case User  = 'user';
    case Admin = 'admin';

    public function label(): string
    {
        return match($this) {
            self::User  => 'Usuário',
            self::Admin => 'Suporte',
        };
    }
}
