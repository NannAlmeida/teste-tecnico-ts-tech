<?php

declare(strict_types=1);

use App\Entities\Cliente;
use App\Entities\Proposta;
use App\Enums\AuditoriaEvento;
use App\Enums\PropostaOrigem;
use App\Enums\PropostaStatus;
use App\Models\ClienteModel;
use App\Models\PropostaAuditoriaModel;
use App\Models\PropostaModel;
use App\Repositories\ClienteRepository;
use App\Repositories\PropostaAuditoriaRepository;
use App\Repositories\PropostaRepository;
use App\Services\AuditoriaService;
use App\Services\TransactionRunner;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class AuditoriaTransacionalTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = true;
    protected $refresh = true;
    protected $namespace = 'App';

    private PropostaRepository $propostas;
    private PropostaAuditoriaRepository $auditorias;
    private AuditoriaService $auditoria;
    private TransactionRunner $transacao;

    protected function setUp(): void
    {
        parent::setUp();

        $this->propostas = new PropostaRepository(new PropostaModel());
        $this->auditorias = new PropostaAuditoriaRepository(new PropostaAuditoriaModel());
        $this->auditoria = new AuditoriaService($this->auditorias);
        $this->transacao = new TransactionRunner($this->db);
    }

    private function novaProposta(array $override = []): Proposta
    {
        $cliente = new Cliente();
        $cliente->fill([
            'nome' => 'Ana Carolina Souza',
            'email' => uniqid('ana', true) . '@exemplo.com',
            'documento' => (string) random_int(10000000000, 99999999999),
            'documento_tipo' => 'CPF',
        ]);
        $cliente = (new ClienteRepository(new ClienteModel()))->insert($cliente);

        $proposta = new Proposta();
        $proposta->fill($override + [
            'cliente_id' => $cliente->id,
            'produto' => 'Plano Ouro',
            'valor_mensal' => '1250.00',
            'status' => PropostaStatus::DRAFT,
            'origem' => PropostaOrigem::API,
            'versao' => 1,
        ]);

        return $this->propostas->insert($proposta);
    }

    private function trilha(int $propostaId): array
    {
        return $this->auditorias->paginateByProposta($propostaId, 1, 50)['items'];
    }

    public function testRunDevolveOValorDaOperacao(): void
    {
        $resultado = $this->transacao->run(static fn (): string => 'pronto');

        $this->assertSame('pronto', $resultado);
    }

    public function testMutacaoEAuditoriaCommitamJuntas(): void
    {
        $proposta = $this->novaProposta();

        $this->transacao->run(function () use ($proposta): void {
            $this->propostas->updateWithVersion($proposta->id, 1, ['status' => PropostaStatus::SUBMITTED->value]);
            $this->auditoria->registrarMudancaDeStatus($proposta->id, PropostaStatus::DRAFT, PropostaStatus::SUBMITTED, 'user:123');
        });

        $this->assertSame(PropostaStatus::SUBMITTED, $this->propostas->findOrFail($proposta->id)->status);
        $this->assertCount(1, $this->trilha($proposta->id));
    }

    public function testFalhaNaAuditoriaDesfazAMutacao(): void
    {
        $proposta = $this->novaProposta();

        try {
            $this->transacao->run(function () use ($proposta): void {
                $this->propostas->updateWithVersion($proposta->id, 1, ['status' => PropostaStatus::SUBMITTED->value]);

                throw new RuntimeException('auditoria falhou');
            });
            $this->fail('esperava a excecao subir');
        } catch (RuntimeException) {
        }

        $recarregada = $this->propostas->findOrFail($proposta->id);

        $this->assertSame(PropostaStatus::DRAFT, $recarregada->status);
        $this->assertSame(1, $recarregada->versao);
        $this->assertSame([], $this->trilha($proposta->id));
    }

    public function testRollbackDesfazTambemAAuditoriaJaGravada(): void
    {
        $proposta = $this->novaProposta();

        try {
            $this->transacao->run(function () use ($proposta): void {
                $this->auditoria->registrarMudancaDeStatus($proposta->id, PropostaStatus::DRAFT, PropostaStatus::SUBMITTED, 'user:123');

                throw new RuntimeException('mutacao falhou depois');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame([], $this->trilha($proposta->id));
    }

    public function testTransacaoAninhadaSoCommitaNaMaisExterna(): void
    {
        $proposta = $this->novaProposta();

        try {
            $this->transacao->run(function () use ($proposta): void {
                $this->transacao->run(function () use ($proposta): void {
                    $this->auditoria->registrarAlteracao($proposta->id, ['produto' => ['de' => 'a', 'para' => 'b']], 'user:1');
                });

                throw new RuntimeException('a externa falhou');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame([], $this->trilha($proposta->id), 'a interna nao pode ter commitado sozinha');
    }

    public function testCriacaoGuardaSnapshotDaProposta(): void
    {
        $proposta = $this->novaProposta();

        $this->auditoria->registrarCriacao($proposta, 'user:123', 'chave-abc');

        $registro = $this->trilha($proposta->id)[0];

        $this->assertSame(AuditoriaEvento::CREATED, $registro->evento);
        $this->assertSame('user:123', $registro->actor);
        $this->assertSame('chave-abc', $registro->idempotency_key);
        $this->assertEqualsCanonicalizing([
            'cliente_id' => $proposta->cliente_id,
            'produto' => 'Plano Ouro',
            'valor_mensal' => '1250.00',
            'origem' => 'API',
            'status' => 'DRAFT',
        ], $registro->payload);
    }

    public function testMudancaDeStatusGuardaOrigemEDestino(): void
    {
        $proposta = $this->novaProposta();

        $this->auditoria->registrarMudancaDeStatus($proposta->id, PropostaStatus::DRAFT, PropostaStatus::CANCELED, 'user:9');

        $registro = $this->trilha($proposta->id)[0];

        $this->assertSame(AuditoriaEvento::STATUS_CHANGED, $registro->evento);
        $this->assertSame(['de' => 'DRAFT', 'para' => 'CANCELED'], $registro->payload);
    }

    public function testExclusaoGuardaOEstadoNoMomento(): void
    {
        $proposta = $this->novaProposta(['status' => PropostaStatus::SUBMITTED, 'versao' => 2]);

        $this->auditoria->registrarExclusao($proposta, 'system');

        $registro = $this->trilha($proposta->id)[0];

        $this->assertSame(AuditoriaEvento::DELETED_LOGICAL, $registro->evento);
        $this->assertEqualsCanonicalizing(['status_no_momento' => 'SUBMITTED', 'versao' => 2], $registro->payload);
    }

    public function testAlteracaoSemMudancaNaoGeraRegistro(): void
    {
        $proposta = $this->novaProposta();

        $resultado = $this->auditoria->registrarAlteracao($proposta->id, [], 'user:1');

        $this->assertNull($resultado);
        $this->assertSame([], $this->trilha($proposta->id));
    }

    public function testActorVazioViraSystem(): void
    {
        $proposta = $this->novaProposta();

        $this->auditoria->registrarMudancaDeStatus($proposta->id, PropostaStatus::DRAFT, PropostaStatus::SUBMITTED, '   ');

        $this->assertSame('system', $this->trilha($proposta->id)[0]->actor);
    }
}
