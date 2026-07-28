<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\DuplicateResourceException;
use App\Exceptions\IdempotencyConflictException;
use App\Repositories\IdempotentRepository;
use CodeIgniter\Entity\Entity;

class IdempotencyService
{
    public function __construct(private readonly TransactionRunner $transacao)
    {
    }

    /**
     * Executa a criacao no maximo uma vez por chave. Sem chave, executa direto.
     *
     * @param callable(): Entity $create
     *
     * @return array{recurso: Entity, replay: bool}
     *
     * @throws IdempotencyConflictException quando a chave ja foi usada com outro payload
     */
    public function ensureOnce(
        IdempotentRepository $repository,
        ?string $key,
        string $requestHash,
        callable $create,
    ): array {
        if ($key === null || $key === '') {
            return ['recurso' => $this->transacao->run($create), 'replay' => false];
        }

        $existente = $repository->findByIdempotencyKey($key);

        if ($existente !== null) {
            return $this->replay($existente, $key, $requestHash);
        }

        try {
            return ['recurso' => $this->transacao->run($create), 'replay' => false];
        } catch (DuplicateResourceException $e) {
            if (($e->details()['campo'] ?? null) !== 'idempotency_key') {
                throw $e;
            }

            $vencedor = $repository->findByIdempotencyKey($key);

            if ($vencedor === null) {
                throw $e;
            }

            return $this->replay($vencedor, $key, $requestHash);
        }
    }

    /**
     * Chaves ordenadas em qualquer profundidade: reordenar campos no corpo da
     * requisicao nao pode contar como payload diferente.
     *
     * @param array<string, mixed> $payload
     */
    public static function hash(array $payload): string
    {
        return hash('sha256', (string) json_encode(
            self::ordenar($payload),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }

    /**
     * @param array<array-key, mixed> $dados
     *
     * @return array<array-key, mixed>
     */
    private static function ordenar(array $dados): array
    {
        ksort($dados);

        foreach ($dados as $chave => $valor) {
            if (is_array($valor)) {
                $dados[$chave] = self::ordenar($valor);
            }
        }

        return $dados;
    }

    /**
     * @return array{recurso: Entity, replay: bool}
     */
    private function replay(Entity $recurso, string $key, string $requestHash): array
    {
        if (($recurso->request_hash ?? null) !== $requestHash) {
            throw IdempotencyConflictException::keyReuse($key);
        }

        return ['recurso' => $recurso, 'replay' => true];
    }
}
