<?php

declare(strict_types=1);

namespace App\Services;

use App\Entities\Proposta;
use App\Entities\PropostaAuditoria;
use App\Enums\AuditoriaEvento;
use App\Enums\PropostaStatus;
use App\Repositories\PropostaAuditoriaRepository;

class AuditoriaService
{
    public const ACTOR_PADRAO = 'system';

    public function __construct(private readonly PropostaAuditoriaRepository $repository)
    {
    }

    public function registrarCriacao(Proposta $proposta, string $actor, ?string $idempotencyKey = null): PropostaAuditoria
    {
        return $this->registrar($proposta->id, AuditoriaEvento::CREATED, [
            'cliente_id' => $proposta->cliente_id,
            'produto' => $proposta->produto,
            'valor_mensal' => $proposta->valor_mensal,
            'origem' => $proposta->origem->value,
            'status' => $proposta->status->value,
        ], $actor, $idempotencyKey);
    }

    /**
     * @param array<string, array{de: mixed, para: mixed}> $diff
     */
    public function registrarAlteracao(int $propostaId, array $diff, string $actor): ?PropostaAuditoria
    {
        if ($diff === []) {
            return null;
        }

        return $this->registrar($propostaId, AuditoriaEvento::UPDATED_FIELDS, $diff, $actor);
    }

    public function registrarMudancaDeStatus(
        int $propostaId,
        PropostaStatus $de,
        PropostaStatus $para,
        string $actor,
        ?string $idempotencyKey = null,
    ): PropostaAuditoria {
        return $this->registrar($propostaId, AuditoriaEvento::STATUS_CHANGED, [
            'de' => $de->value,
            'para' => $para->value,
        ], $actor, $idempotencyKey);
    }

    public function registrarExclusao(Proposta $proposta, string $actor): PropostaAuditoria
    {
        return $this->registrar($proposta->id, AuditoriaEvento::DELETED_LOGICAL, [
            'status_no_momento' => $proposta->status->value,
            'versao' => $proposta->versao,
        ], $actor);
    }

    /**
     * @param array<string, mixed> $antes
     * @param array<string, mixed> $depois
     *
     * @return array<string, array{de: mixed, para: mixed}>
     */
    public static function diff(array $antes, array $depois): array
    {
        $mudancas = [];

        foreach ($depois as $campo => $novo) {
            $anterior = $antes[$campo] ?? null;

            if ($anterior !== $novo) {
                $mudancas[$campo] = ['de' => $anterior, 'para' => $novo];
            }
        }

        return $mudancas;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function registrar(
        int $propostaId,
        AuditoriaEvento $evento,
        array $payload,
        string $actor,
        ?string $idempotencyKey = null,
    ): PropostaAuditoria {
        $auditoria = new PropostaAuditoria();
        $auditoria->fill([
            'proposta_id' => $propostaId,
            'actor' => trim($actor) === '' ? self::ACTOR_PADRAO : trim($actor),
            'evento' => $evento,
            'payload' => $payload,
            'idempotency_key' => $idempotencyKey,
        ]);

        return $this->repository->insert($auditoria);
    }
}
