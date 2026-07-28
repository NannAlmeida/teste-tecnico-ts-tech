<?php

declare(strict_types=1);

use App\DTO\CreateClienteDTO;
use App\DTO\CreatePropostaDTO;
use App\DTO\UpdatePropostaDTO;
use App\Entities\Proposta;
use App\Enums\AuditoriaEvento;
use App\Enums\PropostaOrigem;
use App\Enums\PropostaStatus;
use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\ImmutableResourceException;
use App\Exceptions\ValidationException;
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
final class PropostaServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = true;
    protected $refresh = true;
    protected $namespace = 'App';

    private PropostaService $servico;
    private PropostaAuditoriaRepository $auditorias;
    private PropostaRepository $propostas;
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

    private function criar(array $override = [], ?string $chave = null): array
    {
        return $this->servico->criar(
            CreatePropostaDTO::fromArray($override + [
                'cliente_id' => (string) $this->clienteId,
                'produto' => 'Plano Ouro',
                'valor_mensal' => '1250.00',
                'origem' => 'API',
            ]),
            'user:123',
            $chave,
        );
    }

    private function novaProposta(array $override = []): Proposta
    {
        return $this->criar($override)['recurso'];
    }

    private function trilha(int $propostaId): array
    {
        return $this->auditorias->paginateByProposta($propostaId, 1, 50)['items'];
    }

    private function ultimoRegistro(int $propostaId)
    {
        $registros = $this->trilha($propostaId);

        return end($registros);
    }

    public function testCriaEmDraftComVersaoUm(): void
    {
        $proposta = $this->novaProposta();

        $this->assertSame(PropostaStatus::DRAFT, $proposta->status);
        $this->assertSame(PropostaOrigem::API, $proposta->origem);
        $this->assertSame(1, $proposta->versao);
        $this->assertSame('1250.00', $proposta->valor_mensal);
    }

    public function testCriacaoRegistraAuditoriaComSnapshot(): void
    {
        $proposta = $this->novaProposta();
        $registros = $this->trilha($proposta->id);

        $this->assertCount(1, $registros);
        $this->assertSame(AuditoriaEvento::CREATED, $registros[0]->evento);
        $this->assertSame('user:123', $registros[0]->actor);
        $this->assertSame('Plano Ouro', $registros[0]->payload['produto']);
    }

    public function testClienteInexistenteResponde422NoCampo(): void
    {
        try {
            $this->criar(['cliente_id' => '9999']);
            $this->fail('esperava ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(422, $e->getCode());
            $this->assertArrayHasKey('cliente_id', $e->details());
        }

        $this->assertSame(0, $this->db->table('propostas')->countAllResults());
    }

    public function testRetryComMesmaChaveNaoDuplica(): void
    {
        $primeira = $this->criar([], 'chave-abc');
        $segunda = $this->criar([], 'chave-abc');

        $this->assertFalse($primeira['replay']);
        $this->assertTrue($segunda['replay']);
        $this->assertSame($primeira['recurso']->id, $segunda['recurso']->id);
        $this->assertSame(1, $this->db->table('propostas')->countAllResults());
        $this->assertCount(1, $this->trilha($primeira['recurso']->id));
    }

    public function testRetryComPayloadDiferenteResponde409(): void
    {
        $this->criar([], 'chave-abc');

        $this->expectException(IdempotencyConflictException::class);

        $this->criar(['produto' => 'Plano Platina'], 'chave-abc');
    }

    public function testAlteracaoAplicaIncrementaVersaoERegistraDiff(): void
    {
        $proposta = $this->novaProposta();

        $alterada = $this->servico->alterar(
            $proposta->id,
            UpdatePropostaDTO::fromArray(['versao' => '1', 'produto' => 'Plano Platina', 'valor_mensal' => '1899.90']),
            'user:9',
        );

        $this->assertSame('Plano Platina', $alterada->produto);
        $this->assertSame('1899.90', $alterada->valor_mensal);
        $this->assertSame(2, $alterada->versao);

        $ultimo = $this->ultimoRegistro($proposta->id);

        $this->assertSame(AuditoriaEvento::UPDATED_FIELDS, $ultimo->evento);
        $this->assertSame(['de' => 'Plano Ouro', 'para' => 'Plano Platina'], $ultimo->payload['produto']);
        $this->assertSame(['de' => '1250.00', 'para' => '1899.90'], $ultimo->payload['valor_mensal']);
    }

    public function testAlteracaoSoTocaOsCamposEnviados(): void
    {
        $proposta = $this->novaProposta();

        $alterada = $this->servico->alterar(
            $proposta->id,
            UpdatePropostaDTO::fromArray(['versao' => '1', 'produto' => 'Plano Platina']),
            'user:9',
        );

        $this->assertSame('1250.00', $alterada->valor_mensal);
        $this->assertSame(PropostaOrigem::API, $alterada->origem);
        $this->assertArrayNotHasKey('valor_mensal', $this->ultimoRegistro($proposta->id)->payload);
    }

    public function testAlteracaoQueNaoMudaNadaNaoGravaNemIncrementaVersao(): void
    {
        $proposta = $this->novaProposta();

        $resultado = $this->servico->alterar(
            $proposta->id,
            UpdatePropostaDTO::fromArray(['versao' => '1', 'produto' => 'Plano Ouro']),
            'user:9',
        );

        $this->assertSame(1, $resultado->versao);
        $this->assertCount(1, $this->trilha($proposta->id));
    }

    public function testAlteracaoComVersaoDefasadaResponde409(): void
    {
        $proposta = $this->novaProposta();
        $this->servico->alterar($proposta->id, UpdatePropostaDTO::fromArray(['versao' => '1', 'produto' => 'Primeira']), 'user:1');

        try {
            $this->servico->alterar($proposta->id, UpdatePropostaDTO::fromArray(['versao' => '1', 'produto' => 'Segunda']), 'user:2');
            $this->fail('esperava VersionConflictException');
        } catch (VersionConflictException $e) {
            $this->assertSame(['versao_enviada' => 1, 'versao_atual' => 2], $e->details());
        }

        $this->assertSame('Primeira', $this->propostas->findOrFail($proposta->id)->produto);
    }

    public function testAlteracaoEmEstadoFinalResponde409(): void
    {
        $proposta = $this->novaProposta();
        $this->propostas->updateWithVersion($proposta->id, 1, ['status' => PropostaStatus::CANCELED->value]);

        $this->expectException(ImmutableResourceException::class);

        $this->servico->alterar($proposta->id, UpdatePropostaDTO::fromArray(['versao' => '2', 'produto' => 'Plano Platina']), 'user:1');
    }

    public function testFalhaNaAlteracaoNaoDeixaAuditoriaOrfa(): void
    {
        $proposta = $this->novaProposta();

        try {
            $this->servico->alterar(
                $proposta->id,
                UpdatePropostaDTO::fromArray(['versao' => '99', 'produto' => 'Plano Platina']),
                'user:1',
            );
        } catch (VersionConflictException) {
        }

        $this->assertCount(1, $this->trilha($proposta->id));
        $this->assertSame('Plano Ouro', $this->propostas->findOrFail($proposta->id)->produto);
    }
}
