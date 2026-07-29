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
final class PropostaUpdateTest extends CIUnitTestCase
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

    private function patch(int $id, array $corpo, array $headers = [])
    {
        return $this->withHeaders($headers)->withBodyFormat('json')->call('patch', 'api/v1/propostas/' . $id, $corpo);
    }

    private function dadosDe($resposta): array
    {
        return json_decode($resposta->getJSON(), true)['data'];
    }

    private function erroDe($resposta): array
    {
        return json_decode($resposta->getJSON(), true)['error'];
    }

    public function testAlteraComVersaoNoCorpo(): void
    {
        $proposta = $this->novaProposta();

        $resposta = $this->patch($proposta->id, ['versao' => 1, 'produto' => 'Plano Platina', 'valor_mensal' => '1899.90']);

        $resposta->assertStatus(200);

        $dados = $this->dadosDe($resposta);

        $this->assertSame('Plano Platina', $dados['produto']);
        $this->assertSame('1899.90', $dados['valor_mensal']);
        $this->assertSame(2, $dados['versao']);
        $this->assertSame('"2"', $resposta->response()->getHeaderLine('ETag'));
    }

    public function testAlteraComVersaoNoHeaderIfMatch(): void
    {
        $proposta = $this->novaProposta();

        $resposta = $this->patch($proposta->id, ['produto' => 'Plano Platina'], ['If-Match' => '"1"']);

        $resposta->assertStatus(200);
        $this->assertSame('Plano Platina', $this->dadosDe($resposta)['produto']);
        $this->assertSame(2, $this->dadosDe($resposta)['versao']);
    }

    public function testCorpoTemPrecedenciaSobreOIfMatch(): void
    {
        $proposta = $this->novaProposta();

        $resposta = $this->patch($proposta->id, ['versao' => 1, 'produto' => 'Plano Platina'], ['If-Match' => '"99"']);

        $resposta->assertStatus(200);
        $this->assertSame(2, $this->dadosDe($resposta)['versao']);
    }

    public function testSemVersaoNenhumaResponde422(): void
    {
        $proposta = $this->novaProposta();

        $resposta = $this->patch($proposta->id, ['produto' => 'Plano Platina']);

        $resposta->assertStatus(422);
        $this->assertSame('MISSING_VERSION', $this->erroDe($resposta)['code']);
        $this->seeInDatabase('propostas', ['id' => $proposta->id, 'produto' => 'Plano Ouro']);
    }

    public function testVersaoDefasadaResponde409ComAVersaoAtual(): void
    {
        $proposta = $this->novaProposta();
        $this->patch($proposta->id, ['versao' => 1, 'produto' => 'Primeira']);

        $resposta = $this->patch($proposta->id, ['versao' => 1, 'produto' => 'Segunda']);

        $resposta->assertStatus(409);

        $erro = $this->erroDe($resposta);

        $this->assertSame('VERSION_CONFLICT', $erro['code']);
        $this->assertSame(['versao_enviada' => 1, 'versao_atual' => 2], $erro['details']);
        $this->seeInDatabase('propostas', ['id' => $proposta->id, 'produto' => 'Primeira']);
    }

    public function testAlteracaoParcialNaoTocaOsDemaisCampos(): void
    {
        $proposta = $this->novaProposta();

        $dados = $this->dadosDe($this->patch($proposta->id, ['versao' => 1, 'produto' => 'Plano Platina']));

        $this->assertSame('1250.00', $dados['valor_mensal']);
        $this->assertSame('API', $dados['origem']);
    }

    public function testAlteracaoRegistraAuditoriaComODiff(): void
    {
        $proposta = $this->novaProposta();

        $this->patch($proposta->id, ['versao' => 1, 'produto' => 'Plano Platina'], ['X-Actor' => 'user:77']);

        $registro = $this->db->table('proposta_auditorias')
            ->where('proposta_id', $proposta->id)
            ->where('evento', 'UPDATED_FIELDS')
            ->get()
            ->getRowArray();

        $this->assertNotNull($registro);
        $this->assertSame('user:77', $registro['actor']);
        $this->assertSame(
            ['de' => 'Plano Ouro', 'para' => 'Plano Platina'],
            json_decode($registro['payload'], true)['produto'],
        );
    }

    public function testCorpoQueNaoMudaNadaNaoIncrementaVersao(): void
    {
        $proposta = $this->novaProposta();

        $dados = $this->dadosDe($this->patch($proposta->id, ['versao' => 1, 'produto' => 'Plano Ouro']));

        $this->assertSame(1, $dados['versao']);
        $this->assertSame(1, $this->db->table('proposta_auditorias')->where('proposta_id', $proposta->id)->countAllResults());
    }

    public function testEstadoFinalResponde409(): void
    {
        $proposta = $this->novaProposta();
        service('propostaService')->cancelar($proposta->id, 1, 'user:1');

        $resposta = $this->patch($proposta->id, ['versao' => 2, 'produto' => 'Plano Platina']);

        $resposta->assertStatus(409);
        $this->assertSame('IMMUTABLE_RESOURCE', $this->erroDe($resposta)['code']);
        $this->assertSame('CANCELED', $this->erroDe($resposta)['details']['status']);
    }

    public function testTentarMudarStatusPeloPatchResponde422(): void
    {
        $proposta = $this->novaProposta();

        $resposta = $this->patch($proposta->id, ['versao' => 1, 'status' => 'APPROVED']);

        $resposta->assertStatus(422);
        $this->assertStringContainsString('status', $this->erroDe($resposta)['details']['corpo'][0]);
    }

    public function testSemNenhumCampoAlteravelResponde422(): void
    {
        $proposta = $this->novaProposta();

        $this->patch($proposta->id, ['versao' => 1])->assertStatus(422);
    }

    public function testPropostaInexistenteResponde404(): void
    {
        $this->patch(9999, ['versao' => 1, 'produto' => 'Plano Platina'])->assertStatus(404);
    }
}
