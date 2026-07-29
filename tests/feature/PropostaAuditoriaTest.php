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
final class PropostaAuditoriaTest extends CIUnitTestCase
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

    private function trilha(int $id, string $query = '')
    {
        return $this->get("api/v1/propostas/{$id}/auditoria" . ($query === '' ? '' : '?' . $query));
    }

    private function corpoDe($resposta): array
    {
        return json_decode($resposta->getJSON(), true);
    }

    public function testTrilhaDeUmaPropostaRecemCriadaTemSoACriacao(): void
    {
        $proposta = $this->novaProposta();

        $resposta = $this->trilha($proposta->id);

        $resposta->assertStatus(200);

        $corpo = $this->corpoDe($resposta);

        $this->assertCount(1, $corpo['data']);
        $this->assertSame('CREATED', $corpo['data'][0]['evento']);
        $this->assertSame('user:123', $corpo['data'][0]['actor']);
        $this->assertSame('Plano Ouro', $corpo['data'][0]['payload']['produto']);
        $this->assertSame(['page' => 1, 'per_page' => 20, 'total' => 1, 'total_pages' => 1], $corpo['meta']);
    }

    public function testTrilhaContaAHistoriaEmOrdemCronologica(): void
    {
        $proposta = $this->novaProposta();
        $servico = service('propostaService');

        $servico->alterar(
            $proposta->id,
            App\DTO\UpdatePropostaDTO::fromArray(['versao' => '1', 'produto' => 'Plano Platina']),
            'user:1',
        );
        $servico->submeter($proposta->id, 2, 'user:2');
        $servico->aprovar($proposta->id, 3, 'user:3');

        $eventos = array_column($this->corpoDe($this->trilha($proposta->id))['data'], 'evento');

        $this->assertSame(['CREATED', 'UPDATED_FIELDS', 'STATUS_CHANGED', 'STATUS_CHANGED'], $eventos);
    }

    public function testPayloadDeAlteracaoTrazODiff(): void
    {
        $proposta = $this->novaProposta();
        service('propostaService')->alterar(
            $proposta->id,
            App\DTO\UpdatePropostaDTO::fromArray(['versao' => '1', 'produto' => 'Plano Platina']),
            'user:1',
        );

        $registro = $this->corpoDe($this->trilha($proposta->id))['data'][1];

        $this->assertSame('UPDATED_FIELDS', $registro['evento']);
        $this->assertSame(['de' => 'Plano Ouro', 'para' => 'Plano Platina'], $registro['payload']['produto']);
    }

    public function testPayloadDeTransicaoTrazOrigemEDestino(): void
    {
        $proposta = $this->novaProposta();
        service('propostaService')->submeter($proposta->id, 1, 'user:2');

        $registro = $this->corpoDe($this->trilha($proposta->id))['data'][1];

        $this->assertSame(['de' => 'DRAFT', 'para' => 'SUBMITTED'], $registro['payload']);
    }

    public function testNaoVazaChaveDeIdempotenciaNemPropostaId(): void
    {
        $proposta = $this->novaProposta();
        service('propostaService')->submeter($proposta->id, 1, 'user:2', 'chave-submit');

        $registro = $this->corpoDe($this->trilha($proposta->id))['data'][1];

        $this->assertArrayNotHasKey('idempotency_key', $registro);
        $this->assertArrayNotHasKey('request_hash', $registro);
        $this->assertArrayNotHasKey('proposta_id', $registro);
    }

    public function testTrilhaEhPaginada(): void
    {
        $proposta = $this->novaProposta();
        $servico = service('propostaService');
        $servico->submeter($proposta->id, 1, 'user:1');
        $servico->aprovar($proposta->id, 2, 'user:1');

        $primeira = $this->corpoDe($this->trilha($proposta->id, 'per_page=2&page=1'));
        $segunda = $this->corpoDe($this->trilha($proposta->id, 'per_page=2&page=2'));

        $this->assertCount(2, $primeira['data']);
        $this->assertCount(1, $segunda['data']);
        $this->assertSame(3, $primeira['meta']['total']);
        $this->assertSame(2, $primeira['meta']['total_pages']);
    }

    public function testTrilhaNaoMisturaPropostas(): void
    {
        $uma = $this->novaProposta();
        $outra = $this->novaProposta();
        service('propostaService')->submeter($outra->id, 1, 'user:1');

        $this->assertSame(1, $this->corpoDe($this->trilha($uma->id))['meta']['total']);
        $this->assertSame(2, $this->corpoDe($this->trilha($outra->id))['meta']['total']);
    }

    public function testPropostaInexistenteResponde404(): void
    {
        $resposta = $this->trilha(9999);

        $resposta->assertStatus(404);
        $this->assertSame('RESOURCE_NOT_FOUND', $this->corpoDe($resposta)['error']['code']);
    }

    public function testPropostaExcluidaResponde404MasATrilhaPermaneceNoBanco(): void
    {
        $proposta = $this->novaProposta();
        service('propostaService')->excluir($proposta->id, 1, 'user:1');

        $this->trilha($proposta->id)->assertStatus(404);
        $this->assertSame(
            2,
            $this->db->table('proposta_auditorias')->where('proposta_id', $proposta->id)->countAllResults(),
        );
    }

    public function testPerPageAcimaDoTetoResponde422(): void
    {
        $proposta = $this->novaProposta();

        $resposta = $this->trilha($proposta->id, 'per_page=500');

        $resposta->assertStatus(422);
        $this->assertArrayHasKey('per_page', $this->corpoDe($resposta)['error']['details']);
    }

    public function testParametroDesconhecidoResponde422(): void
    {
        $proposta = $this->novaProposta();

        $this->trilha($proposta->id, 'evento=CREATED')->assertStatus(422);
    }
}
