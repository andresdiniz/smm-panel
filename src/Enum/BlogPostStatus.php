<?php

namespace App\Enum;

enum BlogPostStatus: string
{
    case Draft     = 'draft';
    case Published = 'published';
    case Archived  = 'archived';

    public function label(): string
    {
        return match($this) {
            self::Draft     => 'Rascunho',
            self::Published => 'Publicado',
            self::Archived  => 'Arquivado',
        };
    }

    public function badgeClass(): string
    {
        return match($this) {
            self::Draft     => 'warning',
            self::Published => 'success',
            self::Archived  => 'secondary',
        };
    }
}
