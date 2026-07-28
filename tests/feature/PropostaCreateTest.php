<?php

declare(strict_types=1);

use App\DTO\CreateClienteDTO;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * @internal
 */
final class PropostaCreateTest extends CIUnitTestCase
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

    private function postar(array $corpo = [], array $headers = ['Idempotency-Key' => 'chave-abc'])
    {
        return $this->withHeaders($headers)->withBodyFormat('json')->post('api/v1/propostas', $corpo + [
            'cliente_id' => $this->clienteId,
            'produto' => 'Plano Ouro',
            'valor_mensal' => '1250.00',
            'origem' => 'API',
        ]);
    }

    private function dadosDe($resposta): array
    {
        return json_decode($resposta->getJSON(), true)['data'];
    }

    private function erroDe($resposta): array
    {
        return json_decode($resposta->getJSON(), true)['error'];
    }

    public function testCriaEmDraftComClienteAninhado(): void
    {
        $resposta = $this->postar();

        $resposta->assertStatus(201);

        $dados = $this->dadosDe($resposta);

        $this->assertSame('DRAFT', $dados['status']);
        $this->assertSame('API', $dados['origem']);
        $this->assertSame(1, $dados['versao']);
        $this->assertSame('1250.00', $dados['valor_mensal']);
        $this->assertSame($this->clienteId, $dados['cliente']['id']);
        $this->assertSame('Ana Carolina Souza', $dados['cliente']['nome']);
        $this->assertSame('ana@exemplo.com', $dados['cliente']['email']);
        $this->assertNull($dados['deleted_at']);
    }

    public function testClienteAninhadoNaoTrazDocumento(): void
    {
        $this->assertArrayNotHasKey('documento', $this->dadosDe($this->postar())['cliente']);
    }

    public function testNaoVazaCampoInterno(): void
    {
        $dados = $this->dadosDe($this->postar());

        $this->assertArrayNotHasKey('idempotency_key', $dados);
        $this->assertArrayNotHasKey('request_hash', $dados);
        $this->assertArrayNotHasKey('cliente_nome', $dados);
    }

    public function testRespondeLocationEEtagComAVersao(): void
    {
        $resposta = $this->postar();
        $id = $this->dadosDe($resposta)['id'];

        $this->assertSame('/api/v1/propostas/' . $id, $resposta->response()->getHeaderLine('Location'));
        $this->assertSame('"1"', $resposta->response()->getHeaderLine('ETag'));
    }

    public function testNormalizaValorEOrigem(): void
    {
        $dados = $this->dadosDe($this->postar(['valor_mensal' => '199.9', 'origem' => 'app']));

        $this->assertSame('199.90', $dados['valor_mensal']);
        $this->assertSame('APP', $dados['origem']);
    }

    public function testRegistraAuditoriaDeCriacaoComOActorDoHeader(): void
    {
        $id = $this->dadosDe($this->postar([], ['Idempotency-Key' => 'chave-abc', 'X-Actor' => 'user:123']))['id'];

        $this->seeInDatabase('proposta_auditorias', [
            'proposta_id' => $id,
            'evento' => 'CREATED',
            'actor' => 'user:123',
        ]);
    }

    public function testSemActorRegistraSystem(): void
    {
        $id = $this->dadosDe($this->postar())['id'];

        $this->seeInDatabase('proposta_auditorias', ['proposta_id' => $id, 'actor' => 'system']);
    }

    public function testClienteInexistenteResponde422(): void
    {
        $resposta = $this->postar(['cliente_id' => 9999]);

        $resposta->assertStatus(422);
        $this->assertArrayHasKey('cliente_id', $this->erroDe($resposta)['details']);
        $this->assertSame(0, $this->db->table('propostas')->countAllResults());
    }

    public function testOrigemInvalidaResponde422(): void
    {
        $resposta = $this->postar(['origem' => 'WHATSAPP']);

        $resposta->assertStatus(422);
        $this->assertArrayHasKey('origem', $this->erroDe($resposta)['details']);
    }

    public function testSemIdempotencyKeyResponde400(): void
    {
        $resposta = $this->withBodyFormat('json')->post('api/v1/propostas', [
            'cliente_id' => $this->clienteId,
            'produto' => 'Plano Ouro',
            'valor_mensal' => '1250.00',
            'origem' => 'API',
        ]);

        $resposta->assertStatus(400);
        $this->assertSame('MISSING_IDEMPOTENCY_KEY', $this->erroDe($resposta)['code']);
    }

    public function testRetryComMesmaChaveNaoDuplica(): void
    {
        $primeira = $this->postar();
        $idDaPrimeira = $this->dadosDe($primeira)['id'];

        $segunda = $this->postar();

        $segunda->assertStatus(201);
        $this->assertSame('true', $segunda->response()->getHeaderLine('Idempotency-Replayed'));
        $this->assertSame($idDaPrimeira, $this->dadosDe($segunda)['id']);
        $this->assertSame(1, $this->db->table('propostas')->countAllResults());
        $this->assertSame(1, $this->db->table('proposta_auditorias')->countAllResults());
    }

    public function testReplayTambemTrazOClienteAninhado(): void
    {
        $this->postar();

        $dados = $this->dadosDe($this->postar());

        $this->assertSame('Ana Carolina Souza', $dados['cliente']['nome']);
    }

    public function testMesmaChaveComOutroCorpoResponde409(): void
    {
        $this->postar();

        $resposta = $this->postar(['produto' => 'Plano Platina']);

        $resposta->assertStatus(409);
        $this->assertSame('IDEMPOTENCY_KEY_REUSE', $this->erroDe($resposta)['code']);
    }
}
