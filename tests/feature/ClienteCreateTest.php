<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * @internal
 */
final class ClienteCreateTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate = true;
    protected $refresh = true;
    protected $namespace = 'App';

    private const CORPO = [
        'nome' => 'Ana Carolina Souza',
        'email' => 'ana@exemplo.com',
        'documento' => '529.982.247-25',
    ];

    private function postar(array $corpo = [], array $headers = ['Idempotency-Key' => 'chave-abc'])
    {
        return $this->withHeaders($headers)->withBodyFormat('json')->post('api/v1/clientes', $corpo + self::CORPO);
    }

    private function erroDe($resposta): array
    {
        return json_decode($resposta->getJSON(), true)['error'];
    }

    public function testCriaClienteERespondeComRecursoCompleto(): void
    {
        $resposta = $this->postar();

        $resposta->assertStatus(201);

        $dados = json_decode($resposta->getJSON(), true)['data'];

        $this->assertSame('Ana Carolina Souza', $dados['nome']);
        $this->assertSame('ana@exemplo.com', $dados['email']);
        $this->assertSame('52998224725', $dados['documento']);
        $this->assertSame('529.982.247-25', $dados['documento_formatado']);
        $this->assertSame('CPF', $dados['documento_tipo']);
        $this->assertNotEmpty($dados['created_at']);
        $this->seeInDatabase('clientes', ['id' => $dados['id'], 'email' => 'ana@exemplo.com']);
    }

    public function testRespondeLocationApontandoParaORecurso(): void
    {
        $resposta = $this->postar();
        $id = json_decode($resposta->getJSON(), true)['data']['id'];

        $this->assertSame('/api/v1/clientes/' . $id, $resposta->response()->getHeaderLine('Location'));
    }

    public function testNaoVazaCampoInternoNaResposta(): void
    {
        $dados = json_decode($this->postar()->getJSON(), true)['data'];

        $this->assertArrayNotHasKey('idempotency_key', $dados);
        $this->assertArrayNotHasKey('request_hash', $dados);
    }

    public function testAceitaCnpjAlfanumerico(): void
    {
        $resposta = $this->postar(['documento' => '12.ABC.345/01DE-35']);

        $resposta->assertStatus(201);

        $dados = json_decode($resposta->getJSON(), true)['data'];

        $this->assertSame('12ABC34501DE35', $dados['documento']);
        $this->assertSame('12.ABC.345/01DE-35', $dados['documento_formatado']);
        $this->assertSame('CNPJ', $dados['documento_tipo']);
    }

    public function testDocumentoInvalidoResponde422(): void
    {
        $resposta = $this->postar(['documento' => '11111111111']);

        $resposta->assertStatus(422);

        $erro = $this->erroDe($resposta);

        $this->assertSame('VALIDATION_ERROR', $erro['code']);
        $this->assertArrayHasKey('documento', $erro['details']);
        $this->assertNotEmpty($erro['trace_id']);
    }

    public function testCorpoVazioListaTodosOsCamposObrigatorios(): void
    {
        $resposta = $this->withHeaders(['Idempotency-Key' => 'chave-abc'])
            ->withBodyFormat('json')
            ->post('api/v1/clientes', []);

        $resposta->assertStatus(422);

        $detalhes = $this->erroDe($resposta)['details'];

        $this->assertArrayHasKey('nome', $detalhes);
        $this->assertArrayHasKey('email', $detalhes);
        $this->assertArrayHasKey('documento', $detalhes);
    }

    public function testEmailRepetidoResponde409(): void
    {
        $this->postar();

        $resposta = $this->postar(['documento' => '11144477735'], ['Idempotency-Key' => 'outra-chave']);

        $resposta->assertStatus(409);
        $this->assertSame('DUPLICATE_RESOURCE', $this->erroDe($resposta)['code']);
        $this->assertSame('email', $this->erroDe($resposta)['details']['campo']);
    }

    public function testSemIdempotencyKeyResponde400(): void
    {
        $resposta = $this->withBodyFormat('json')->post('api/v1/clientes', self::CORPO);

        $resposta->assertStatus(400);
        $this->assertSame('MISSING_IDEMPOTENCY_KEY', $this->erroDe($resposta)['code']);
        $this->assertSame(0, $this->db->table('clientes')->countAllResults());
    }

    public function testRetryComMesmaChaveDevolveOMesmoRecursoMarcado(): void
    {
        $primeira = $this->postar();
        $marcaDaPrimeira = $primeira->response()->getHeaderLine('Idempotency-Replayed');
        $idDaPrimeira = json_decode($primeira->getJSON(), true)['data']['id'];

        $segunda = $this->postar();

        $segunda->assertStatus(201);

        $this->assertSame('', $marcaDaPrimeira);
        $this->assertSame('true', $segunda->response()->getHeaderLine('Idempotency-Replayed'));
        $this->assertSame($idDaPrimeira, json_decode($segunda->getJSON(), true)['data']['id']);
        $this->assertSame(1, $this->db->table('clientes')->countAllResults());
    }

    public function testMesmaChaveComOutroCorpoResponde409(): void
    {
        $this->postar();

        $resposta = $this->postar(['nome' => 'Outro Nome']);

        $resposta->assertStatus(409);
        $this->assertSame('IDEMPOTENCY_KEY_REUSE', $this->erroDe($resposta)['code']);
    }

    public function testCorpoQueNaoEhJsonResponde400(): void
    {
        $resposta = $this->withHeaders(['Idempotency-Key' => 'chave-abc'])
            ->withBody('nome=Ana&email=ana@exemplo.com')
            ->post('api/v1/clientes');

        $resposta->assertStatus(400);
        $this->assertSame('MALFORMED_JSON', $this->erroDe($resposta)['code']);
    }
}
