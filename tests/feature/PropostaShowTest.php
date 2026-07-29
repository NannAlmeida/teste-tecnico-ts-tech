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
final class PropostaShowTest extends CIUnitTestCase
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

    private function novaProposta(array $override = []): Proposta
    {
        return service('propostaService')->criar(
            CreatePropostaDTO::fromArray($override + [
                'cliente_id' => (string) $this->clienteId,
                'produto' => 'Plano Ouro',
                'valor_mensal' => '1250.00',
                'origem' => 'API',
            ]),
            'user:123',
            null,
        )['recurso'];
    }

    private function dadosDe($resposta): array
    {
        return json_decode($resposta->getJSON(), true)['data'];
    }

    public function testDevolveAPropostaComClienteAninhado(): void
    {
        $proposta = $this->novaProposta();

        $resposta = $this->get('api/v1/propostas/' . $proposta->id);

        $resposta->assertStatus(200);

        $dados = $this->dadosDe($resposta);

        $this->assertSame($proposta->id, $dados['id']);
        $this->assertSame('Plano Ouro', $dados['produto']);
        $this->assertSame('DRAFT', $dados['status']);
        $this->assertSame(1, $dados['versao']);
        $this->assertSame('Ana Carolina Souza', $dados['cliente']['nome']);
    }

    public function testContratoEhIdenticoAoDaCriacao(): void
    {
        $proposta = $this->novaProposta();

        $criado = $this->dadosDe(
            $this->withHeaders(['Idempotency-Key' => 'outra'])->withBodyFormat('json')->post('api/v1/propostas', [
                'cliente_id' => $this->clienteId,
                'produto' => 'Plano Platina',
                'valor_mensal' => '1899.90',
                'origem' => 'SITE',
            ]),
        );

        $consultado = $this->dadosDe($this->get('api/v1/propostas/' . $criado['id']));

        $this->assertSame(array_keys($criado), array_keys($consultado));
        $this->assertSame($criado, $consultado);
    }

    public function testEtagCarregaAVersaoAtual(): void
    {
        $proposta = $this->novaProposta();
        service('propostaService')->submeter($proposta->id, 1, 'user:1');

        $resposta = $this->get('api/v1/propostas/' . $proposta->id);

        $this->assertSame('"2"', $resposta->response()->getHeaderLine('ETag'));
        $this->assertSame(2, $this->dadosDe($resposta)['versao']);
        $this->assertSame('SUBMITTED', $this->dadosDe($resposta)['status']);
    }

    public function testNaoVazaCampoInterno(): void
    {
        $dados = $this->dadosDe($this->get('api/v1/propostas/' . $this->novaProposta()->id));

        $this->assertArrayNotHasKey('idempotency_key', $dados);
        $this->assertArrayNotHasKey('request_hash', $dados);
        $this->assertArrayNotHasKey('cliente_nome', $dados);
        $this->assertArrayNotHasKey('cliente_id', $dados);
    }

    public function testPropostaInexistenteResponde404(): void
    {
        $resposta = $this->get('api/v1/propostas/9999');

        $resposta->assertStatus(404);

        $erro = json_decode($resposta->getJSON(), true)['error'];

        $this->assertSame('RESOURCE_NOT_FOUND', $erro['code']);
        $this->assertSame(['recurso' => 'proposta', 'id' => 9999], $erro['details']);
    }

    public function testPropostaExcluidaLogicamenteResponde404(): void
    {
        $proposta = $this->novaProposta();
        service('propostaService')->excluir($proposta->id, 1, 'user:1');

        $this->get('api/v1/propostas/' . $proposta->id)->assertStatus(404);
        $this->seeInDatabase('propostas', ['id' => $proposta->id]);
    }
}
