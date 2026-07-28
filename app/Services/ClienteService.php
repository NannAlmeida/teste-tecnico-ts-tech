<?php

declare(strict_types=1);

namespace App\Services;

use App\DTO\CreateClienteDTO;
use App\Entities\Cliente;
use App\Repositories\ClienteRepository;

class ClienteService
{
    public function __construct(
        private readonly ClienteRepository $repository,
        private readonly IdempotencyService $idempotencia,
    ) {
    }

    /**
     * @return array{recurso: Cliente, replay: bool}
     */
    public function criar(CreateClienteDTO $dados, ?string $idempotencyKey): array
    {
        $requestHash = IdempotencyService::hash($dados->toArray());

        return $this->idempotencia->ensureOnce(
            $this->repository,
            $idempotencyKey,
            $requestHash,
            function () use ($dados, $idempotencyKey, $requestHash): Cliente {
                $cliente = new Cliente();
                $cliente->fill([
                    'nome' => $dados->nome,
                    'email' => $dados->email,
                    'documento' => $dados->documento->valor,
                    'documento_tipo' => $dados->documento->tipo,
                    'idempotency_key' => $idempotencyKey,
                    'request_hash' => $requestHash,
                ]);

                return $this->repository->insert($cliente);
            },
        );
    }

    public function buscar(int $id): Cliente
    {
        return $this->repository->findOrFail($id);
    }
}
