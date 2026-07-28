<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Entities\Proposta;
use App\Exceptions\DuplicateResourceException;
use App\Exceptions\ResourceNotFoundException;
use App\Exceptions\VersionConflictException;
use App\Models\PropostaModel;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\I18n\Time;

class PropostaRepository
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
