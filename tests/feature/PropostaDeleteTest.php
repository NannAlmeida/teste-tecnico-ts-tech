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
final class PropostaDeleteTest extends CIUnitTestCase
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

    private function excluir(int $id, array $headers = ['If-Match' => '"1"'], array $corpo = [])
    {
        return $this->withHeaders($headers)->withBodyFormat('json')->call('delete', 'api/v1/propostas/' . $id, $corpo);
    }

    private function erroDe($resposta): array
    {
        return json_decode($resposta->getJSON(), true)['error'];
    }

    public function testExcluiRespondendo204SemCorpo(): void
    {
        $proposta = $this->novaProposta();

        $resposta = $this->excluir($proposta->id, ['If-Match' => '"1"', 'X-Actor' => 'user:7']);

        $resposta->assertStatus(204);
        $this->assertSame('', trim($resposta->getBody()));
        $this->assertSame('', $resposta->response()->getHeaderLine('Content-Type'));
    }

    public function testALinhaPermaneceNoBancoComDeletedAtEVersaoIncrementada(): void
    {
        $proposta = $this->novaProposta();

        $this->excluir($proposta->id);

        $this->seeInDatabase('propostas', ['id' => $proposta->id, 'versao' => 2]);
        $this->dontSeeInDatabase('propostas', ['id' => $proposta->id, 'deleted_at' => null]);
    }

    public function testRegistraAuditoriaComOEstadoDoMomento(): void
    {
        $proposta = $this->novaProposta();

        $this->excluir($proposta->id, ['If-Match' => '"1"', 'X-Actor' => 'user:7']);

        $registro = $this->db->table('proposta_auditorias')
            ->where('proposta_id', $proposta->id)
            ->where('evento', 'DELETED_LOGICAL')
            ->get()
            ->getRowArray();

        $this->assertNotNull($registro);
        $this->assertSame('user:7', $registro['actor']);
        $this->assertSame('DRAFT', json_decode($registro['payload'], true)['status_no_momento']);
    }

    public function testDepoisDeExcluidaOGetResponde404(): void
    {
        $proposta = $this->novaProposta();

        $this->excluir($proposta->id);

        $this->get('api/v1/propostas/' . $proposta->id)->assertStatus(404);
    }

    public function testExcluirDuasVezesRespondeNotFound(): void
    {
        $proposta = $this->novaProposta();

        $this->excluir($proposta->id)->assertStatus(204);
        $this->excluir($proposta->id, ['If-Match' => '"2"'])->assertStatus(404);
    }

    public function testAceitaAVersaoNoCorpoTambem(): void
    {
        $proposta = $this->novaProposta();

        $this->excluir($proposta->id, [], ['versao' => 1])->assertStatus(204);
    }

    public function testSemVersaoResponde422(): void
    {
        $proposta = $this->novaProposta();

        $resposta = $this->excluir($proposta->id, []);

        $resposta->assertStatus(422);
        $this->assertSame('MISSING_VERSION', $this->erroDe($resposta)['code']);
        $this->seeInDatabase('propostas', ['id' => $proposta->id, 'deleted_at' => null]);
    }

    public function testVersaoDefasadaResponde409(): void
    {
        $proposta = $this->novaProposta();
        service('propostaService')->submeter($proposta->id, 1, 'user:1');

        $resposta = $this->excluir($proposta->id, ['If-Match' => '"1"']);

        $resposta->assertStatus(409);
        $this->assertSame('VERSION_CONFLICT', $this->erroDe($resposta)['code']);
        $this->dontSeeInDatabase('propostas', ['id' => $proposta->id, 'deleted_at !=' => null]);
    }

    public function testEstadoFinalNaoPodeSerExcluido(): void
    {
        $proposta = $this->novaProposta();
        service('propostaService')->cancelar($proposta->id, 1, 'user:1');

        $resposta = $this->excluir($proposta->id, ['If-Match' => '"2"']);

        $resposta->assertStatus(409);
        $this->assertSame('IMMUTABLE_RESOURCE', $this->erroDe($resposta)['code']);
    }

    public function testPropostaInexistenteResponde404(): void
    {
        $this->excluir(9999)->assertStatus(404);
    }
}
