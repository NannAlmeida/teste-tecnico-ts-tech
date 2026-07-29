<?php

declare(strict_types=1);

namespace App\Resources;

use App\Entities\PropostaAuditoria;

final class AuditoriaResource
{
    /**
     * @return array<string, mixed>
     */
    public static function item(PropostaAuditoria $auditoria): array
    {
        return [
            'id' => $auditoria->id,
            'evento' => $auditoria->evento->value,
            'actor' => $auditoria->actor,
            'payload' => $auditoria->payload,
            'created_at' => $auditoria->created_at?->format(DATE_ATOM),
        ];
    }

    /**
     * @param list<PropostaAuditoria> $auditorias
     *
     * @return list<array<string, mixed>>
     */
    public static function collection(array $auditorias): array
    {
        return array_map(static fn (PropostaAuditoria $a): array => self::item($a), $auditorias);
    }
}
