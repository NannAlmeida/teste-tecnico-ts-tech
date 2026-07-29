<?php

declare(strict_types=1);

use App\DTO\CreateClienteDTO;
use App\DTO\CreatePropostaDTO;
use App\Entities\Proposta;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * @internal
 */
final class PropostaStatusFlowTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate = true;
    protected $refresh = true;
    protected $namespace = 'App';

    private int $clienteId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clienteId = service('clienteService')->criar(
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
        return service('propostaService')->criar(
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

    private function transicionar(int $id, string $acao, int $versao, array $headers = [])
    {
        return $this->withHeaders($headers + ['Idempotency-Key' => uniqid('chave', true)])
            ->withBodyFormat('json')
            ->post("api/v1/propostas/{$id}/{$acao}", ['versao' => $versao]);
    }

    private function dadosDe($resposta): array
    {
        return json_decode($resposta->getJSON(), true)['data'];
    }

    private function erroDe($resposta): array
    {
        return json_decode($resposta->getJSON(), true)['error'];
    }

    private function eventosDe(int $propostaId): array
    {
        return array_column(
            $this->db->table('proposta_auditorias')->where('proposta_id', $propostaId)->orderBy('id')->get()->getResultArray(),
            'evento',
        );
    }

    public function testSubmeteEIncrementaAVersao(): void
    {
        $proposta = $this->novaProposta();

        $resposta = $this->transicionar($proposta->id, 'submit', 1, ['X-Actor' => 'user:9']);

        $resposta->assertStatus(200);

        $dados = $this->dadosDe($resposta);

        $this->assertSame('SUBMITTED', $dados['status']);
        $this->assertSame(2, $dados['versao']);
        $this->assertSame('"2"', $resposta->response()->getHeaderLine('ETag'));
        $this->assertSame(['CREATED', 'STATUS_CHANGED'], $this->eventosDe($proposta->id));
    }

    public function testFluxoCompletoAteAprovacao(): void
    {
        $proposta = $this->novaProposta();

        $this->transicionar($proposta->id, 'submit', 1);
        $aprovada = $this->transicionar($proposta->id, 'approve', 2);

        $aprovada->assertStatus(200);
        $this->assertSame('APPROVED', $this->dadosDe($aprovada)['status']);
        $this->assertSame(3, $this->dadosDe($aprovada)['versao']);
        $this->assertSame(['CREATED', 'STATUS_CHANGED', 'STATUS_CHANGED'], $this->eventosDe($proposta->id));
    }

    public function testRejeitaAPartirDeSubmitted(): void
    {
        $proposta = $this->novaProposta();
        $this->transicionar($proposta->id, 'submit', 1);

        $this->assertSame('REJECTED', $this->dadosDe($this->transicionar($proposta->id, 'reject', 2))['status']);
    }

    public function testCancelaDiretoDeDraft(): void
    {
        $proposta = $this->novaProposta();

        $this->assertSame('CANCELED', $this->dadosDe($this->transicionar($proposta->id, 'cancel', 1))['status']);
    }

    public function testTransicaoInvalidaResponde409ListandoAsPermitidas(): void
    {
        $proposta = $this->novaProposta();

        $resposta = $this->transicionar($proposta->id, 'approve', 1);

        $resposta->assertStatus(409);

        $erro = $this->erroDe($resposta);

        $this->assertSame('INVALID_STATUS_TRANSITION', $erro['code']);
        $this->assertSame('DRAFT', $erro['details']['de']);
        $this->assertSame('APPROVED', $erro['details']['para']);
        $this->assertSame(['SUBMITTED', 'CANCELED'], $erro['details']['transicoes_permitidas']);
        $this->assertSame(['CREATED'], $this->eventosDe($proposta->id));
    }

    public function testEstadoFinalResponde409ImmutableResource(): void
    {
        $proposta = $this->novaProposta();
        $this->transicionar($proposta->id, 'cancel', 1);

        $resposta = $this->transicionar($proposta->id, 'submit', 2);

        $resposta->assertStatus(409);
        $this->assertSame('IMMUTABLE_RESOURCE', $this->erroDe($resposta)['code']);
        $this->assertSame('CANCELED', $this->erroDe($resposta)['details']['status']);
    }

    public function testVersaoDefasadaResponde409(): void
    {
        $proposta = $this->novaProposta();
        $this->transicionar($proposta->id, 'submit', 1);

        $resposta = $this->transicionar($proposta->id, 'approve', 1);

        $resposta->assertStatus(409);
        $this->assertSame('VERSION_CONFLICT', $this->erroDe($resposta)['code']);
        $this->assertSame(['versao_enviada' => 1, 'versao_atual' => 2], $this->erroDe($resposta)['details']);
    }

    public function testVersaoPodeVirNoIfMatch(): void
    {
        $proposta = $this->novaProposta();

        $resposta = $this->withHeaders(['Idempotency-Key' => 'sub-1', 'If-Match' => '"1"'])
            ->post("api/v1/propostas/{$proposta->id}/submit");

        $resposta->assertStatus(200);
        $this->assertSame('SUBMITTED', $this->dadosDe($resposta)['status']);
    }

    public function testSubmitExigeIdempotencyKey(): void
    {
        $proposta = $this->novaProposta();

        $resposta = $this->withBodyFormat('json')->post("api/v1/propostas/{$proposta->id}/submit", ['versao' => 1]);

        $resposta->assertStatus(400);
        $this->assertSame('MISSING_IDEMPOTENCY_KEY', $this->erroDe($resposta)['code']);
        $this->assertSame(['CREATED'], $this->eventosDe($proposta->id));
    }

    public function testApproveNaoExigeIdempotencyKey(): void
    {
        $proposta = $this->novaProposta();
        $this->transicionar($proposta->id, 'submit', 1);

        $resposta = $this->withHeaders([])->withBodyFormat('json')
            ->post("api/v1/propostas/{$proposta->id}/approve", ['versao' => 2]);

        $resposta->assertStatus(200);
        $this->assertSame('APPROVED', $this->dadosDe($resposta)['status']);
    }

    public function testSubmitRepetidoComAMesmaChaveEhReplay(): void
    {
        $proposta = $this->novaProposta();

        $primeira = $this->withHeaders(['Idempotency-Key' => 'sub-1'])
            ->withBodyFormat('json')
            ->post("api/v1/propostas/{$proposta->id}/submit", ['versao' => 1]);
        $marcaDaPrimeira = $primeira->response()->getHeaderLine('Idempotency-Replayed');

        $segunda = $this->withHeaders(['Idempotency-Key' => 'sub-1'])
            ->withBodyFormat('json')
            ->post("api/v1/propostas/{$proposta->id}/submit", ['versao' => 1]);

        $segunda->assertStatus(200);
        $this->assertSame('', $marcaDaPrimeira);
        $this->assertSame('true', $segunda->response()->getHeaderLine('Idempotency-Replayed'));
        $this->assertSame('SUBMITTED', $this->dadosDe($segunda)['status']);
        $this->assertSame(2, $this->dadosDe($segunda)['versao']);
        $this->assertSame(['CREATED', 'STATUS_CHANGED'], $this->eventosDe($proposta->id));
    }

    public function testSubmitRepetidoComChaveNovaBateNaGuardaDeEstado(): void
    {
        $proposta = $this->novaProposta();
        $this->transicionar($proposta->id, 'submit', 1);

        $resposta = $this->transicionar($proposta->id, 'submit', 2);

        $resposta->assertStatus(409);
        $this->assertSame('INVALID_STATUS_TRANSITION', $this->erroDe($resposta)['code']);
        $this->assertSame(['CREATED', 'STATUS_CHANGED'], $this->eventosDe($proposta->id));
    }

    public function testMesmaChaveEmOperacaoDiferenteResponde409(): void
    {
        $proposta = $this->novaProposta();

        $this->withHeaders(['Idempotency-Key' => 'compartilhada'])
            ->withBodyFormat('json')
            ->post("api/v1/propostas/{$proposta->id}/submit", ['versao' => 1]);

        $resposta = $this->withHeaders(['Idempotency-Key' => 'compartilhada'])
            ->withBodyFormat('json')
            ->post("api/v1/propostas/{$proposta->id}/approve", ['versao' => 2]);

        $resposta->assertStatus(409);
        $this->assertSame('IDEMPOTENCY_KEY_REUSE', $this->erroDe($resposta)['code']);
    }

    public function testPropostaInexistenteResponde404(): void
    {
        $this->transicionar(9999, 'submit', 1)->assertStatus(404);
    }

    public function testPropostaExcluidaResponde404(): void
    {
        $proposta = $this->novaProposta();
        service('propostaService')->excluir($proposta->id, 1, 'user:1');

        $this->transicionar($proposta->id, 'submit', 2)->assertStatus(404);
    }
}
