<?php

declare(strict_types=1);

namespace App\Resources;

use App\Entities\Proposta;

final class PropostaResource
{
    /**
     * @return array<string, mixed>
     */
    public static function item(Proposta $proposta): array
    {
        return [
            'id' => $proposta->id,
            'cliente' => self::cliente($proposta),
            'produto' => $proposta->produto,
            'valor_mensal' => $proposta->valor_mensal,
            'status' => $proposta->status->value,
            'origem' => $proposta->origem->value,
            'versao' => $proposta->versao,
            'created_at' => $proposta->created_at?->format(DATE_ATOM),
            'updated_at' => $proposta->updated_at?->format(DATE_ATOM),
            'deleted_at' => $proposta->deleted_at?->format(DATE_ATOM),
        ];
    }

    /**
     * @param list<Proposta> $propostas
     *
     * @return list<array<string, mixed>>
     */
    public static function collection(array $propostas): array
    {
        return array_map(static fn (Proposta $proposta): array => self::item($proposta), $propostas);
    }

    /**
     * Resumo do cliente, sem documento: a listagem nao precisa espalhar dado
     * pessoal que um GET /clientes/{id} entrega quando for de fato necessario.
     *
     * @return array<string, mixed>
     */
    private static function cliente(Proposta $proposta): array
    {
        return [
            'id' => $proposta->cliente_id,
            'nome' => $proposta->cliente_nome ?? null,
            'email' => $proposta->cliente_email ?? null,
        ];
    }
}
