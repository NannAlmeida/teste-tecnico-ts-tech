<?php

declare(strict_types=1);

namespace App\Enums;

enum DocumentoTipo: string
{
    case CPF = 'CPF';
    case CNPJ = 'CNPJ';

    public function tamanho(): int
    {
        return match ($this) {
            self::CPF => 11,
            self::CNPJ => 14,
        };
    }
}
