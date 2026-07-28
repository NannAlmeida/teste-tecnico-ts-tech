<?php

declare(strict_types=1);

use App\DTO\CreateClienteDTO;
use App\DTO\CreatePropostaDTO;
use App\DTO\UpdatePropostaDTO;
use App\Entities\Proposta;
use App\Enums\AuditoriaEvento;
use App\Enums\PropostaStatus;
use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\ImmutableResourceException;
use App\Exceptions\InvalidStatusTransitionException;
use App\Exceptions\ResourceNotFoundException;
use App\Exceptions\VersionConflictException;
use App\Models\ClienteModel;
use App\Models\PropostaAuditoriaModel;
use App\Models\PropostaModel;
use App\Repositories\ClienteRepository;
use App\Repositories\PropostaAuditoriaRepository;
use App\Repositories\PropostaRepository;
use App\Services\AuditoriaService;
use App\Services\ClienteService;
use App\Services\IdempotencyService;
use App\Services\PropostaService;
use App\Services\TransactionRunner;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class PropostaTransicaoTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = true;
    protected $refresh = true;
    protected $namespace = 'App';

    private PropostaService $servico;
    private PropostaRepository $propostas;
    private PropostaAuditoriaRepository $auditorias;
    private int $clienteId;

    protected function setUp(): void
    {
        parent::setUp();

        $transacao = new TransactionRunner($this->db);
        $idempotencia = new IdempotencyService($transacao);
        $clientes = new ClienteRepository(new ClienteModel());
        $this->propostas = new PropostaRepository(new PropostaModel());
        $this->auditorias = new PropostaAuditoriaRepository(new PropostaAuditoriaModel());

        $this->servico = new PropostaService(
            $this->propostas,
            $clientes,
            new AuditoriaService($this->auditorias),
            $this->auditorias,
            $idempotencia,
            $transacao,
        );

        $this->clienteId = (new ClienteService($clientes, $idempotencia))->criar(
            CreateClienteDTO::fromArray([
                'nome' => 'Ana Carolina Souza',
                'email' => 'ana@exemplo.com',
                'documento' => '52998224725',
            ]),
            null,
        )['recurso']->id;
    }

    private function novaProposta(): Proposta
    {
        return $this->servico->criar(
            CreatePropostaDTO::fromArray([
                'cliente_id' => (string) $this->clienteId,
                'produto' => 'Plano Ouro',
                'valor_mensal' => '1250.00',
                'origem' => 'API',
            ]),
            'user:123',
            null,
        )['recurso'];
    }

    private function trilha(int $propostaId): array
    {
        return $this->auditorias->paginateByProposta($propostaId, 1, 50)['items'];
    }

    public function testSubmeteIncrementaVersaoERegistraTransicao(): void
    {
        $proposta = $this->novaProposta();

        $submetida = $this->servico->submeter($proposta->id, 1, 'user:9')['recurso'];

        $this->assertSame(PropostaStatus::SUBMITTED, $submetida->status);
        $this->assertSame(2, $submetida->versao);

        $registros = $this->trilha($proposta->id);

        $this->assertCount(2, $registros);
        $this->assertSame(AuditoriaEvento::STATUS_CHANGED, $registros[1]->evento);
        $this->assertSame(['de' => 'DRAFT', 'para' => 'SUBMITTED'], $registros[1]->payload);
        $this->assertSame('user:9', $registros[1]->actor);
    }

    public function testFluxoCompletoAteAprovacao(): void
    {
        $proposta = $this->novaProposta();

        $this->servico->submeter($proposta->id, 1, 'user:1');
        $aprovada = $this->servico->aprovar($proposta->id, 2, 'user:2')['recurso'];

        $this->assertSame(PropostaStatus::APPROVED, $aprovada->status);
        $this->assertSame(3, $aprovada->versao);
        $this->assertCount(3, $this->trilha($proposta->id));
    }

    public function testRejeitaEDaCancelaAPartirDeSubmitted(): void
    {
        $rejeitada = $this->novaProposta();
        $this->servico->submeter($rejeitada->id, 1, 'user:1');
        $this->assertSame(PropostaStatus::REJECTED, $this->servico->rejeitar($rejeitada->id, 2, 'user:1')['recurso']->status);

        $cancelada = $this->novaProposta();
        $this->servico->submeter($cancelada->id, 1, 'user:1');
        $this->assertSame(PropostaStatus::CANCELED, $this->servico->cancelar($cancelada->id, 2, 'user:1')['recurso']->status);
    }

    public function testCancelaDiretoDeDraft(): void
    {
        $proposta = $this->novaProposta();

        $cancelada = $this->servico->cancelar($proposta->id, 1, 'user:1')['recurso'];

        $this->assertSame(PropostaStatus::CANCELED, $cancelada->status);
        $this->assertSame(2, $cancelada->versao);
    }

    public function testAprovarDireitoDeDraftEhTransicaoInvalida(): void
    {
        $proposta = $this->novaProposta();

        try {
            $this->servico->aprovar($proposta->id, 1, 'user:1');
            $this->fail('esperava InvalidStatusTransitionException');
        } catch (InvalidStatusTransitionException $e) {
            $this->assertSame(409, $e->getCode());
            $this->assertSame(['SUBMITTED', 'CANCELED'], $e->details()['transicoes_permitidas']);
        }

        $this->assertSame(PropostaStatus::DRAFT, $this->propostas->findOrFail($proposta->id)->status);
        $this->assertCount(1, $this->trilha($proposta->id));
    }

    public function testTransicaoAPartirDeEstadoFinalEhImutavel(): void
    {
        $proposta = $this->novaProposta();
        $this->servico->cancelar($proposta->id, 1, 'user:1');

        $this->expectException(ImmutableResourceException::class);

        $this->servico->submeter($proposta->id, 2, 'user:1');
    }

    public function testTransicaoComVersaoDefasadaResponde409(): void
    {
        $proposta = $this->novaProposta();
        $this->servico->submeter($proposta->id, 1, 'user:1');

        try {
            $this->servico->aprovar($proposta->id, 1, 'user:1');
            $this->fail('esperava VersionConflictException');
        } catch (VersionConflictException $e) {
            $this->assertSame(['versao_enviada' => 1, 'versao_atual' => 2], $e->details());
        }

        $this->assertSame(PropostaStatus::SUBMITTED, $this->propostas->findOrFail($proposta->id)->status);
    }

    public function testSubmitRepetidoComAMesmaChaveNaoDuplica(): void
    {
        $proposta = $this->novaProposta();

        $primeira = $this->servico->submeter($proposta->id, 1, 'user:1', 'submit-xyz');
        $segunda = $this->servico->submeter($proposta->id, 1, 'user:1', 'submit-xyz');

        $this->assertFalse($primeira['replay']);
        $this->assertTrue($segunda['replay']);
        $this->assertSame(PropostaStatus::SUBMITTED, $segunda['recurso']->status);
        $this->assertSame(2, $segunda['recurso']->versao);
        $this->assertCount(2, $this->trilha($proposta->id));
    }

    public function testGuardaDeEstadoTemPrecedenciaSobreAVersao(): void
    {
        $proposta = $this->novaProposta();
        $this->servico->submeter($proposta->id, 1, 'user:1');

        try {
            $this->servico->submeter($proposta->id, 1, 'user:1');
            $this->fail('esperava InvalidStatusTransitionException');
        } catch (InvalidStatusTransitionException $e) {
            $this->assertSame('SUBMITTED', $e->details()['de']);
            $this->assertSame('SUBMITTED', $e->details()['para']);
        }

        $this->assertSame(2, $this->propostas->findOrFail($proposta->id)->versao);
    }

    public function testMesmaChaveParaOutraOperacaoResponde409(): void
    {
        $proposta = $this->novaProposta();
        $this->servico->submeter($proposta->id, 1, 'user:1', 'chave-compartilhada');

        $this->expectException(IdempotencyConflictException::class);

        $this->servico->aprovar($proposta->id, 2, 'user:1', 'chave-compartilhada');
    }

    public function testExclusaoLogicaEscondeARegistraAuditoria(): void
    {
        $proposta = $this->novaProposta();

        $this->servico->excluir($proposta->id, 1, 'user:7');

        $this->assertNull($this->propostas->find($proposta->id));
        $this->seeInDatabase('propostas', ['id' => $proposta->id, 'versao' => 2]);

        $registros = $this->trilha($proposta->id);

        $this->assertCount(2, $registros);
        $this->assertSame(AuditoriaEvento::DELETED_LOGICAL, $registros[1]->evento);
        $this->assertSame('DRAFT', $registros[1]->payload['status_no_momento']);
        $this->assertSame('user:7', $registros[1]->actor);
    }

    public function testExclusaoEmEstadoFinalEhBloqueada(): void
    {
        $proposta = $this->novaProposta();
        $this->servico->cancelar($proposta->id, 1, 'user:1');

        $this->expectException(ImmutableResourceException::class);

        $this->servico->excluir($proposta->id, 2, 'user:1');
    }

    public function testExclusaoComVersaoDefasadaResponde409(): void
    {
        $proposta = $this->novaProposta();
        $this->servico->alterar($proposta->id, UpdatePropostaDTO::fromArray(['versao' => '1', 'produto' => 'Plano Platina']), 'user:1');

        $this->expectException(VersionConflictException::class);

        $this->servico->excluir($proposta->id, 1, 'user:1');
    }

    public function testPropostaExcluidaNaoAceitaMaisTransicao(): void
    {
        $proposta = $this->novaProposta();
        $this->servico->excluir($proposta->id, 1, 'user:1');

        $this->expectException(ResourceNotFoundException::class);

        $this->servico->submeter($proposta->id, 2, 'user:1');
    }

    public function testTransicaoQueFalhaNaoDeixaAuditoriaOrfa(): void
    {
        $proposta = $this->novaProposta();

        try {
            $this->servico->aprovar($proposta->id, 1, 'user:1');
        } catch (InvalidStatusTransitionException) {
        }

        $this->assertCount(1, $this->trilha($proposta->id));
        $this->assertSame(1, $this->propostas->findOrFail($proposta->id)->versao);
    }
}
