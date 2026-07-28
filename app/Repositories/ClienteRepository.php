<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Entities\Cliente;
use App\Exceptions\DuplicateResourceException;
use App\Exceptions\ResourceNotFoundException;
use App\Models\ClienteModel;
use CodeIgniter\Database\Exceptions\DatabaseException;

class ClienteRepository implements IdempotentRepository
{
    private const UNIQUE_FIELDS = ['idempotency_key', 'email', 'documento'];

    public function __construct(private readonly ClienteModel $model)
    {
    }

    public function find(int $id): ?Cliente
    {
        return $this->model->find($id);
    }

    public function findOrFail(int $id): Cliente
    {
        return $this->find($id) ?? throw ResourceNotFoundException::of('cliente', $id);
    }

    public function findByIdempotencyKey(string $key): ?Cliente
    {
        return $this->model->where('idempotency_key', $key)->first();
    }

    /**
     * @throws DuplicateResourceException quando email, documento ou chave ja existem
     */
    public function insert(Cliente $cliente): Cliente
    {
        try {
            $id = (int) $this->model->insert($cliente, true);
        } catch (DatabaseException $e) {
            throw $this->translateConflict($cliente, $e);
        }

        return $this->findOrFail($id);
    }

    private function translateConflict(Cliente $cliente, DatabaseException $original): DuplicateResourceException
    {
        foreach (self::UNIQUE_FIELDS as $field) {
            $value = $cliente->{$field} ?? null;

            if ($value !== null && $this->model->where($field, $value)->countAllResults() > 0) {
                return DuplicateResourceException::onField($field, (string) $value);
            }
        }

        throw $original;
    }
}
