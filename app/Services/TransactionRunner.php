<?php

declare(strict_types=1);

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Throwable;

class TransactionRunner
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * Executa a operacao dentro de uma transacao. Chamadas aninhadas participam
     * da transacao externa: o CodeIgniter conta a profundidade e so a mais
     * externa realmente faz commit ou rollback.
     *
     * transException(true) e obrigatorio: dentro de transacao o CodeIgniter
     * engole erro de query por padrao, e a operacao devolveria false em vez de
     * lancar.
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    public function run(callable $operation): mixed
    {
        $this->db->transException(true)->transBegin();

        try {
            $resultado = $operation();
        } catch (Throwable $e) {
            $this->db->transRollback();

            throw $e;
        }

        $this->db->transCommit();

        return $resultado;
    }
}
