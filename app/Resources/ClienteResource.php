<?php

declare(strict_types=1);

namespace App\Resources;

use App\Entities\Cliente;
use App\ValueObjects\Documento;

final class ClienteResource
{
    /**
     * @return array<string, mixed>
     */
    public static function item(Cliente $cliente): array
    {
        return [
            'id' => $cliente->id,
            'nome' => $cliente->nome,
            'email' => $cliente->email,
            'documento' => $cliente->documento,
            'documento_formatado' => self::formatar($cliente->documento),
            'documento_tipo' => $cliente->documento_tipo?->value,
            'created_at' => $cliente->created_at?->format(DATE_ATOM),
            'updated_at' => $cliente->updated_at?->format(DATE_ATOM),
        ];
    }

    private static function formatar(?string $documento): ?string
    {
        return $documento === null ? null : Documento::tryDe($documento)?->formatado() ?? $documento;
    }
}
