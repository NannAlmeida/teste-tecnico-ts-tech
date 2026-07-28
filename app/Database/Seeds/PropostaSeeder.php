<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;
use RuntimeException;

class PropostaSeeder extends Seeder
{
    private const TOTAL = 60;

    private const PRODUTOS = [
        'Plano Essencial',
        'Plano Ouro',
        'Plano Platina',
        'Seguro Residencial',
        'Seguro Empresarial',
        'Consorcio Imovel',
    ];

    private const ORIGENS = ['APP', 'SITE', 'API'];

    private const DISTRIBUICAO_STATUS = [
        'DRAFT',
        'DRAFT',
        'DRAFT',
        'SUBMITTED',
        'SUBMITTED',
        'SUBMITTED',
        'APPROVED',
        'APPROVED',
        'REJECTED',
        'CANCELED',
    ];

    public function run(): void
    {
        $clienteIds = array_column(
            $this->db->table('clientes')->select('id')->orderBy('id')->get()->getResultArray(),
            'id',
        );

        if ($clienteIds === []) {
            throw new RuntimeException('Rode o ClienteSeeder antes: propostas precisam de clientes.');
        }

        $this->db->table('propostas')->insertBatch($this->montarPropostas($clienteIds));
        $this->db->table('proposta_auditorias')->insertBatch($this->montarAuditorias());
    }

    /**
     * @param list<int|string> $clienteIds
     *
     * @return list<array<string, mixed>>
     */
    private function montarPropostas(array $clienteIds): array
    {
        $propostas = [];

        for ($i = 0; $i < self::TOTAL; $i++) {
            $status = self::DISTRIBUICAO_STATUS[$i % count(self::DISTRIBUICAO_STATUS)];
            $excluida = $i % 17 === 16;
            $criadoEm = strtotime(sprintf('-%d days -%d hours', self::TOTAL - $i, $i % 24));
            $transicoes = $this->trilha($status);

            $propostas[] = [
                'cliente_id' => $clienteIds[$i % count($clienteIds)],
                'produto' => self::PRODUTOS[$i % count(self::PRODUTOS)],
                'valor_mensal' => number_format(199.9 + ($i % 12) * 150, 2, '.', ''),
                'status' => $status,
                'origem' => self::ORIGENS[$i % count(self::ORIGENS)],
                'versao' => count($transicoes) + 1 + ($excluida ? 1 : 0),
                'idempotency_key' => null,
                'request_hash' => null,
                'created_at' => date('Y-m-d H:i:s', $criadoEm),
                'updated_at' => date('Y-m-d H:i:s', $criadoEm + (count($transicoes) * 3600)),
                'deleted_at' => $excluida ? date('Y-m-d H:i:s', $criadoEm + 86400) : null,
            ];
        }

        return $propostas;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function montarAuditorias(): array
    {
        $propostas = $this->db
            ->table('propostas')
            ->select('id, cliente_id, produto, valor_mensal, origem, status, versao, created_at, deleted_at')
            ->orderBy('id')
            ->get()
            ->getResultArray();

        $auditorias = [];

        foreach ($propostas as $indice => $proposta) {
            $criadoEm = strtotime($proposta['created_at']);
            $actor = $indice % 3 === 0 ? 'system' : 'user:' . (100 + ($indice % 7));

            $auditorias[] = $this->auditoria($proposta['id'], $actor, 'CREATED', [
                'cliente_id' => (int) $proposta['cliente_id'],
                'produto' => $proposta['produto'],
                'valor_mensal' => $proposta['valor_mensal'],
                'origem' => $proposta['origem'],
            ], $criadoEm);

            foreach ($this->trilha($proposta['status']) as $passo => [$de, $para]) {
                $auditorias[] = $this->auditoria($proposta['id'], $actor, 'STATUS_CHANGED', [
                    'de' => $de,
                    'para' => $para,
                ], $criadoEm + (($passo + 1) * 3600));
            }

            if ($proposta['deleted_at'] !== null) {
                $auditorias[] = $this->auditoria($proposta['id'], $actor, 'DELETED_LOGICAL', [
                    'status_no_momento' => $proposta['status'],
                    'versao' => (int) $proposta['versao'] - 1,
                ], strtotime($proposta['deleted_at']));
            }
        }

        return $auditorias;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function trilha(string $status): array
    {
        return match ($status) {
            'DRAFT' => [],
            'SUBMITTED' => [['DRAFT', 'SUBMITTED']],
            'APPROVED' => [['DRAFT', 'SUBMITTED'], ['SUBMITTED', 'APPROVED']],
            'REJECTED' => [['DRAFT', 'SUBMITTED'], ['SUBMITTED', 'REJECTED']],
            'CANCELED' => [['DRAFT', 'CANCELED']],
        };
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function auditoria(int|string $propostaId, string $actor, string $evento, array $payload, int $quando): array
    {
        return [
            'proposta_id' => $propostaId,
            'actor' => $actor,
            'evento' => $evento,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'idempotency_key' => null,
            'request_hash' => null,
            'created_at' => date('Y-m-d H:i:s', $quando),
        ];
    }
}
