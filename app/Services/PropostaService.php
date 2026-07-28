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
use App\Repositories\PropostaAuditoriaRepository;
use App\Repositories\PropostaRepository;

class PropostaService
{
    public function __construct(
        private readonly PropostaRepository $repository,
        private readonly ClienteRepository $clientes,
        private readonly AuditoriaService $auditoria,
        private readonly PropostaAuditoriaRepository $auditorias,
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

        $resultado = $this->idempotencia->ensureOnce(
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

        return [
            'recurso' => $this->repository->findOrFail($resultado['recurso']->id),
            'replay' => $resultado['replay'],
        ];
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
     * @return array{recurso: Proposta, replay: bool}
     */
    public function submeter(int $id, int $versao, string $actor, ?string $idempotencyKey = null): array
    {
        return $this->transicionar($id, PropostaStatus::SUBMITTED, $versao, $actor, $idempotencyKey);
    }

    /**
     * @return array{recurso: Proposta, replay: bool}
     */
    public function aprovar(int $id, int $versao, string $actor, ?string $idempotencyKey = null): array
    {
        return $this->transicionar($id, PropostaStatus::APPROVED, $versao, $actor, $idempotencyKey);
    }

    /**
     * @return array{recurso: Proposta, replay: bool}
     */
    public function rejeitar(int $id, int $versao, string $actor, ?string $idempotencyKey = null): array
    {
        return $this->transicionar($id, PropostaStatus::REJECTED, $versao, $actor, $idempotencyKey);
    }

    /**
     * @return array{recurso: Proposta, replay: bool}
     */
    public function cancelar(int $id, int $versao, string $actor, ?string $idempotencyKey = null): array
    {
        return $this->transicionar($id, PropostaStatus::CANCELED, $versao, $actor, $idempotencyKey);
    }

    public function excluir(int $id, int $versao, string $actor): void
    {
        $this->transacao->run(function () use ($id, $versao, $actor): void {
            $proposta = $this->repository->findOrFail($id);

            StatusTransition::assertMutable($proposta->status);

            if ($proposta->versao !== $versao) {
                throw VersionConflictException::between($versao, $proposta->versao);
            }

            $this->repository->softDelete($id, $versao);
            $this->auditoria->registrarExclusao($proposta, $actor);
        });
    }

    /**
     * A chave de idempotencia vive na linha de auditoria, que e o registro de
     * que aquela transicao ocorreu. O hash cobre proposta e destino, mas nao a
     * versao: um retry legitimo pode chegar com versao diferente sem deixar de
     * ser a mesma operacao.
     *
     * @return array{recurso: Proposta, replay: bool}
     */
    private function transicionar(
        int $id,
        PropostaStatus $destino,
        int $versao,
        string $actor,
        ?string $idempotencyKey,
    ): array {
        $requestHash = IdempotencyService::hash([
            'proposta_id' => (string) $id,
            'para' => $destino->value,
        ]);

        $resultado = $this->idempotencia->ensureOnce(
            $this->auditorias,
            $idempotencyKey,
            $requestHash,
            function () use ($id, $destino, $versao, $actor, $idempotencyKey, $requestHash) {
                $proposta = $this->repository->findOrFail($id);

                StatusTransition::assert($proposta->status, $destino);

                if ($proposta->versao !== $versao) {
                    throw VersionConflictException::between($versao, $proposta->versao);
                }

                $this->repository->updateWithVersion($id, $versao, ['status' => $destino->value]);

                return $this->auditoria->registrarMudancaDeStatus(
                    $id,
                    $proposta->status,
                    $destino,
                    $actor,
                    $idempotencyKey,
                    $requestHash,
                );
            },
        );

        return [
            'recurso' => $this->repository->findOrFail($resultado['recurso']->proposta_id),
            'replay' => $resultado['replay'],
        ];
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
