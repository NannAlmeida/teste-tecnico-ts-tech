<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Entities\PropostaAuditoria;
use App\Exceptions\DuplicateResourceException;
use App\Models\PropostaAuditoriaModel;
use CodeIgniter\Database\Exceptions\DatabaseException;

class PropostaAuditoriaRepository implements IdempotentRepository
{
    public function __construct(private readonly PropostaAuditoriaModel $model)
    {
    }

    public function insert(PropostaAuditoria $auditoria): PropostaAuditoria
    {
        try {
            $id = (int) $this->model->insert($auditoria, true);
        } catch (DatabaseException $e) {
            $chave = $auditoria->idempotency_key ?? null;

            if ($chave !== null && $this->findByIdempotencyKey($chave) !== null) {
                throw DuplicateResourceException::onField('idempotency_key', $chave);
            }

            throw $e;
        }

        return $this->model->find($id);
    }

    public function findByIdempotencyKey(string $key): ?PropostaAuditoria
    {
        return $this->model->where('idempotency_key', $key)->first();
    }

    /**
     * Ordena por id em vez de created_at: dois eventos gravados no mesmo segundo
     * empatariam no timestamp, e o indice (proposta_id, id) ja serve essa leitura.
     *
     * @return array{items: list<PropostaAuditoria>, total: int}
     */
    public function paginateByProposta(int $propostaId, int $page, int $perPage): array
    {
        $builder = $this->model->where('proposta_id', $propostaId);

        $total = $builder->countAllResults(false);

        return [
            'items' => $builder->orderBy('id', 'ASC')->findAll($perPage, ($page - 1) * $perPage),
            'total' => $total,
        ];
    }
}
