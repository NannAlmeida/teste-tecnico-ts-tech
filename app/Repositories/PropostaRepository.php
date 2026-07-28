<?php

declare(strict_types=1);

namespace App\Repositories;

use App\DTO\PropostaQuery;
use App\Entities\Proposta;
use App\Exceptions\DuplicateResourceException;
use App\Exceptions\ResourceNotFoundException;
use App\Exceptions\VersionConflictException;
use App\Models\PropostaModel;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\I18n\Time;
use CodeIgniter\Model;

class PropostaRepository implements IdempotentRepository
{
    public function __construct(private readonly PropostaModel $model)
    {
    }

    public function find(int $id): ?Proposta
    {
        return $this->model->find($id);
    }

    public function findOrFail(int $id): Proposta
    {
        return $this->find($id) ?? throw ResourceNotFoundException::of('proposta', $id);
    }

    public function findByIdempotencyKey(string $key): ?Proposta
    {
        return $this->model->withDeleted()->where('idempotency_key', $key)->first();
    }

    public function insert(Proposta $proposta): Proposta
    {
        try {
            $id = (int) $this->model->insert($proposta, true);
        } catch (DatabaseException $e) {
            $chave = $proposta->idempotency_key ?? null;

            if ($chave !== null && $this->findByIdempotencyKey($chave) !== null) {
                throw DuplicateResourceException::onField('idempotency_key', $chave);
            }

            throw $e;
        }

        return $this->findOrFail($id);
    }

    /**
     * Aplica a alteracao apenas se a versao em disco ainda for a esperada,
     * incrementando-a no mesmo UPDATE.
     *
     * @param array<string, scalar> $changes
     *
     * @throws ResourceNotFoundException quando a proposta sumiu ou foi excluida
     * @throws VersionConflictException  quando outra escrita chegou antes
     */
    public function updateWithVersion(int $id, int $expectedVersion, array $changes): Proposta
    {
        $changes['versao'] = $expectedVersion + 1;

        $this->model
            ->where('versao', $expectedVersion)
            ->where('deleted_at', null)
            ->update($id, $changes);

        $this->assertLockHeld($id, $expectedVersion);

        return $this->findOrFail($id);
    }

    public function softDelete(int $id, int $expectedVersion): void
    {
        $agora = Time::now()->toDateTimeString();

        $this->model->builder()
            ->where('id', $id)
            ->where('versao', $expectedVersion)
            ->where('deleted_at', null)
            ->update([
                'deleted_at' => $agora,
                'updated_at' => $agora,
                'versao' => $expectedVersion + 1,
            ]);

        $this->assertLockHeld($id, $expectedVersion);
    }

    /**
     * Duas queries no total, independente de quantas propostas voltem: o cliente
     * de cada linha vem no mesmo SELECT e o COUNT reaproveita o mesmo WHERE.
     *
     * @return array{items: list<Proposta>, total: int}
     */
    public function search(PropostaQuery $query): array
    {
        $builder = $this->scoped($query)
            ->select(
                'propostas.*,'
                . ' clientes.nome AS cliente_nome,'
                . ' clientes.email AS cliente_email,'
                . ' clientes.documento AS cliente_documento'
            )
            ->join('clientes', 'clientes.id = propostas.cliente_id');

        $this->applyFilters($builder, $query);

        $total = $builder->countAllResults(false);

        foreach ($query->sort as $ordem) {
            $builder->orderBy('propostas.' . $ordem['campo'], $ordem['direcao']);
        }

        return [
            'items' => $builder->findAll($query->perPage, $query->offset()),
            'total' => $total,
        ];
    }

    private function scoped(PropostaQuery $query): Model
    {
        return $query->incluirExcluidas ? $this->model->withDeleted() : $this->model;
    }

    private function applyFilters(Model $builder, PropostaQuery $query): void
    {
        if ($query->clienteId !== null) {
            $builder->where('propostas.cliente_id', $query->clienteId);
        }

        if ($query->status !== []) {
            $builder->whereIn('propostas.status', array_column($query->status, 'value'));
        }

        if ($query->origem !== []) {
            $builder->whereIn('propostas.origem', array_column($query->origem, 'value'));
        }

        if ($query->produto !== null) {
            $builder->like('propostas.produto', $query->produto);
        }

        if ($query->valorMin !== null) {
            $builder->where('propostas.valor_mensal >=', $query->valorMin);
        }

        if ($query->valorMax !== null) {
            $builder->where('propostas.valor_mensal <=', $query->valorMax);
        }

        if ($query->criadoDe !== null) {
            $builder->where('propostas.created_at >=', $query->criadoDe);
        }

        if ($query->criadoAte !== null) {
            $builder->where('propostas.created_at <=', $query->criadoAte);
        }

        if ($query->temBuscaTextual()) {
            $builder->groupStart()
                ->like('propostas.produto', $query->busca)
                ->orLike('clientes.nome', $query->busca)
                ->orLike('clientes.email', $query->busca)
                ->groupEnd();
        }
    }

    private function assertLockHeld(int $id, int $expectedVersion): void
    {
        if ($this->model->db->affectedRows() > 0) {
            return;
        }

        $atual = $this->model->withDeleted()->find($id);

        if ($atual === null || $atual->isDeleted()) {
            throw ResourceNotFoundException::of('proposta', $id);
        }

        throw VersionConflictException::between($expectedVersion, $atual->versao);
    }
}
