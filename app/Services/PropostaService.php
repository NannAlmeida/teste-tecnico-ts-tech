<?php

declare(strict_types=1);

namespace App\Services;

use App\DTO\CreatePropostaDTO;
use App\DTO\UpdatePropostaDTO;
use App\Entities\Proposta;
use App\Enums\PropostaStatus;
use App\Exceptions\ResourceNotFoundException;
use App\Exceptions\ValidationException;
use App\Exceptions\VersionConflictException;
use App\Repositories\ClienteRepository;
use App\Repositories\PropostaRepository;

class PropostaService
{
    public function __construct(
        private readonly PropostaRepository $repository,
        private readonly ClienteRepository $clientes,
        private readonly AuditoriaService $auditoria,
        private readonly IdempotencyService $idempotencia,
        private readonly TransactionRunner $transacao,
    ) {
    }

    /**
     * @return array{recurso: Proposta, replay: bool}
     */
    public function criar(CreatePropostaDTO $dados, string $actor, ?string $idempotencyKey): array
    {
        $requestHash = IdempotencyService::hash($dados->toArray());

        return $this->idempotencia->ensureOnce(
            $this->repository,
            $idempotencyKey,
            $requestHash,
            function () use ($dados, $actor, $idempotencyKey, $requestHash): Proposta {
                $this->assertClienteExiste($dados->clienteId);

                $proposta = new Proposta();
                $proposta->fill([
                    'cliente_id' => $dados->clienteId,
                    'produto' => $dados->produto,
                    'valor_mensal' => $dados->valorMensal,
                    'status' => PropostaStatus::DRAFT,
                    'origem' => $dados->origem,
                    'versao' => 1,
                    'idempotency_key' => $idempotencyKey,
                    'request_hash' => $requestHash,
                ]);

                $criada = $this->repository->insert($proposta);
                $this->auditoria->registrarCriacao($criada, $actor);

                return $criada;
            },
        );
    }

    public function buscar(int $id): Proposta
    {
        return $this->repository->findOrFail($id);
    }

    /**
     * PATCH parcial: so os campos presentes no corpo entram, e um corpo que nao
     * altera nada devolve a proposta intacta, sem gravar nem incrementar versao.
     */
    public function alterar(int $id, UpdatePropostaDTO $dados, string $actor): Proposta
    {
        return $this->transacao->run(function () use ($id, $dados, $actor): Proposta {
            $proposta = $this->repository->findOrFail($id);

            StatusTransition::assertMutable($proposta->status);

            if ($proposta->versao !== $dados->versao) {
                throw VersionConflictException::between($dados->versao, $proposta->versao);
            }

            $diff = AuditoriaService::diff($this->camposAlteraveis($proposta), $dados->alteracoes);

            if ($diff === []) {
                return $proposta;
            }

            $atualizada = $this->repository->updateWithVersion($id, $dados->versao, $dados->alteracoes);
            $this->auditoria->registrarAlteracao($id, $diff, $actor);

            return $atualizada;
        });
    }

    /**
     * @return array<string, string>
     */
    private function camposAlteraveis(Proposta $proposta): array
    {
        return [
            'produto' => $proposta->produto,
            'valor_mensal' => $proposta->valor_mensal,
            'origem' => $proposta->origem->value,
        ];
    }

    private function assertClienteExiste(int $clienteId): void
    {
        try {
            $this->clientes->findOrFail($clienteId);
        } catch (ResourceNotFoundException) {
            throw ValidationException::onField('cliente_id', 'Cliente nao encontrado.');
        }
    }
}
