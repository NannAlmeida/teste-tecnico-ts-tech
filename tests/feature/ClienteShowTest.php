<?php

declare(strict_types=1);

use App\DTO\CreateClienteDTO;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * @internal
 */
final class ClienteShowTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate = true;
    protected $refresh = true;
    protected $namespace = 'App';

    private function criarCliente(array $override = []): int
    {
        return service('clienteService')->criar(
            CreateClienteDTO::fromArray($override + [
                'nome' => 'Ana Carolina Souza',
                'email' => 'ana@exemplo.com',
                'documento' => '52998224725',
            ]),
            null,
        )['recurso']->id;
    }

    public function testDevolveOClienteComOMesmoContratoDaCriacao(): void
    {
        $id = $this->criarCliente();

        $resposta = $this->get('api/v1/clientes/' . $id);

        $resposta->assertStatus(200);

        $dados = json_decode($resposta->getJSON(), true)['data'];

        $this->assertSame($id, $dados['id']);
        $this->assertSame('Ana Carolina Souza', $dados['nome']);
        $this->assertSame('52998224725', $dados['documento']);
        $this->assertSame('529.982.247-25', $dados['documento_formatado']);
        $this->assertSame('CPF', $dados['documento_tipo']);
        $this->assertNotEmpty($dados['created_at']);
    }

    public function testFormataCnpjAlfanumerico(): void
    {
        $id = $this->criarCliente(['documento' => '12ABC34501DE35', 'email' => 'empresa@exemplo.com']);

        $dados = json_decode($this->get('api/v1/clientes/' . $id)->getJSON(), true)['data'];

        $this->assertSame('12.ABC.345/01DE-35', $dados['documento_formatado']);
        $this->assertSame('CNPJ', $dados['documento_tipo']);
    }

    public function testNaoVazaCampoInterno(): void
    {
        $dados = json_decode($this->get('api/v1/clientes/' . $this->criarCliente())->getJSON(), true)['data'];

        $this->assertArrayNotHasKey('idempotency_key', $dados);
        $this->assertArrayNotHasKey('request_hash', $dados);
    }

    public function testClienteInexistenteResponde404NoEnvelope(): void
    {
        $resposta = $this->get('api/v1/clientes/9999');

        $resposta->assertStatus(404);

        $erro = json_decode($resposta->getJSON(), true)['error'];

        $this->assertSame('RESOURCE_NOT_FOUND', $erro['code']);
        $this->assertSame(['recurso' => 'cliente', 'id' => 9999], $erro['details']);
        $this->assertNotEmpty($erro['trace_id']);
    }

    public function testRespostaEcoaOTraceIdEnviado(): void
    {
        $resposta = $this->withHeaders(['X-Trace-Id' => 'rastro-do-cliente'])
            ->get('api/v1/clientes/' . $this->criarCliente());

        $this->assertSame('rastro-do-cliente', $resposta->response()->getHeaderLine('X-Trace-Id'));
    }
}
