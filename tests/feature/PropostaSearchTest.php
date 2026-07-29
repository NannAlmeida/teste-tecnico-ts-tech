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
final class PropostaSearchTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate = true;
    protected $refresh = true;
    protected $namespace = 'App';

    private int $ana;
    private int $bruno;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ana = $this->novoCliente('Ana Carolina Souza', 'ana@exemplo.com', '52998224725');
        $this->bruno = $this->novoCliente('Bruno Ferreira Lima', 'bruno@exemplo.com', '11144477735');
    }

    private function novoCliente(string $nome, string $email, string $documento): int
    {
        return service('clienteService')->criar(
            CreateClienteDTO::fromArray(compact('nome', 'email', 'documento')),
            null,
        )['recurso']->id;
    }

    private function novaProposta(int $clienteId, string $produto, string $valor, string $origem): Proposta
    {
        return service('propostaService')->criar(
            CreatePropostaDTO::fromArray([
                'cliente_id' => (string) $clienteId,
                'produto' => $produto,
                'valor_mensal' => $valor,
                'origem' => $origem,
            ]),
            'user:1',
            null,
        )['recurso'];
    }

    private function cenario(): array
    {
        return [
            'essencial' => $this->novaProposta($this->ana, 'Plano Essencial', '199.90', 'APP'),
            'ouro' => $this->novaProposta($this->ana, 'Plano Ouro', '899.90', 'SITE'),
            'platina' => $this->novaProposta($this->bruno, 'Plano Platina', '1899.90', 'API'),
            'seguro' => $this->novaProposta($this->bruno, 'Seguro Residencial', '349.90', 'APP'),
        ];
    }

    private function buscar(string $query = '')
    {
        return $this->get('api/v1/propostas' . ($query === '' ? '' : '?' . $query));
    }

    private function corpoDe($resposta): array
    {
        return json_decode($resposta->getJSON(), true);
    }

    /**
     * @return list<string>
     */
    private function produtosDe($resposta): array
    {
        return array_column($this->corpoDe($resposta)['data'], 'produto');
    }

    private function erroDe($resposta): array
    {
        return $this->corpoDe($resposta)['error'];
    }

    public function testListaComMetaDePaginacao(): void
    {
        $this->cenario();

        $resposta = $this->buscar();

        $resposta->assertStatus(200);

        $corpo = $this->corpoDe($resposta);

        $this->assertCount(4, $corpo['data']);
        $this->assertSame(['page' => 1, 'per_page' => 20, 'total' => 4, 'total_pages' => 1], $corpo['meta']);
    }

    public function testCadaItemTrazOClienteAninhado(): void
    {
        $this->cenario();

        $item = $this->corpoDe($this->buscar())['data'][0];

        $this->assertArrayHasKey('cliente', $item);
        $this->assertContains($item['cliente']['nome'], ['Ana Carolina Souza', 'Bruno Ferreira Lima']);
        $this->assertArrayNotHasKey('cliente_nome', $item);
        $this->assertArrayNotHasKey('request_hash', $item);
    }

    public function testFiltraPorStatus(): void
    {
        $cenario = $this->cenario();
        service('propostaService')->submeter($cenario['ouro']->id, 1, 'user:1');

        $this->assertSame(['Plano Ouro'], $this->produtosDe($this->buscar('status=SUBMITTED')));
        $this->assertSame(3, $this->corpoDe($this->buscar('status=DRAFT'))['meta']['total']);
    }

    public function testFiltraPorOrigemEAceitaListaSeparadaPorVirgula(): void
    {
        $this->cenario();

        $this->assertSame(2, $this->corpoDe($this->buscar('origem=APP'))['meta']['total']);
        $this->assertSame(3, $this->corpoDe($this->buscar('origem=APP,API'))['meta']['total']);
    }

    public function testCombinaFiltrosComAnd(): void
    {
        $this->cenario();

        $this->assertSame(['Plano Essencial'], $this->produtosDe($this->buscar('origem=APP&valor_max=200.00')));
    }

    public function testFiltraPorFaixaDeValor(): void
    {
        $this->cenario();

        $this->assertSame(2, $this->corpoDe($this->buscar('valor_min=349.90&valor_max=899.90'))['meta']['total']);
    }

    public function testFiltraPorClienteEPorProdutoParcial(): void
    {
        $this->cenario();

        $this->assertSame(2, $this->corpoDe($this->buscar('cliente_id=' . $this->ana))['meta']['total']);
        $this->assertSame(3, $this->corpoDe($this->buscar('produto=Plano'))['meta']['total']);
    }

    public function testBuscaTextualAlcancaProdutoENomeDoCliente(): void
    {
        $this->cenario();

        $this->assertSame(2, $this->corpoDe($this->buscar('q=Bruno'))['meta']['total']);
        $this->assertSame(1, $this->corpoDe($this->buscar('q=Residencial'))['meta']['total']);
    }

    public function testOrdenaNasDuasDirecoes(): void
    {
        $this->cenario();

        $this->assertSame(
            ['Plano Essencial', 'Seguro Residencial', 'Plano Ouro', 'Plano Platina'],
            $this->produtosDe($this->buscar('sort=valor_mensal')),
        );
        $this->assertSame(
            ['Plano Platina', 'Plano Ouro', 'Seguro Residencial', 'Plano Essencial'],
            $this->produtosDe($this->buscar('sort=-valor_mensal')),
        );
    }

    public function testPaginaMantemOTotalEMudaOsItens(): void
    {
        $this->cenario();

        $primeira = $this->corpoDe($this->buscar('sort=valor_mensal&per_page=3&page=1'));
        $segunda = $this->corpoDe($this->buscar('sort=valor_mensal&per_page=3&page=2'));

        $this->assertSame(['page' => 1, 'per_page' => 3, 'total' => 4, 'total_pages' => 2], $primeira['meta']);
        $this->assertSame(['page' => 2, 'per_page' => 3, 'total' => 4, 'total_pages' => 2], $segunda['meta']);
        $this->assertCount(3, $primeira['data']);
        $this->assertCount(1, $segunda['data']);
        $this->assertSame(['Plano Platina'], array_column($segunda['data'], 'produto'));
    }

    public function testPaginaAlemDoFimDevolveListaVaziaComTotalIntacto(): void
    {
        $this->cenario();

        $corpo = $this->corpoDe($this->buscar('page=9'));

        $this->assertSame([], $corpo['data']);
        $this->assertSame(4, $corpo['meta']['total']);
    }

    public function testExcluidasFicamDeForaPorPadrao(): void
    {
        $cenario = $this->cenario();
        service('propostaService')->excluir($cenario['seguro']->id, 1, 'user:1');

        $this->assertSame(3, $this->corpoDe($this->buscar())['meta']['total']);
        $this->assertSame(4, $this->corpoDe($this->buscar('incluir_excluidas=true'))['meta']['total']);
    }

    public function testSemResultadoDevolveListaVaziaEMetaZerado(): void
    {
        $this->cenario();

        $corpo = $this->corpoDe($this->buscar('produto=Consorcio'));

        $this->assertSame([], $corpo['data']);
        $this->assertSame(['page' => 1, 'per_page' => 20, 'total' => 0, 'total_pages' => 0], $corpo['meta']);
    }

    public function testPerPageAcimaDoTetoResponde422(): void
    {
        $resposta = $this->buscar('per_page=500');

        $resposta->assertStatus(422);
        $this->assertArrayHasKey('per_page', $this->erroDe($resposta)['details']);
    }

    public function testColunaDeOrdenacaoForaDaWhitelistResponde422(): void
    {
        $resposta = $this->buscar('sort=produto');

        $resposta->assertStatus(422);
        $this->assertStringContainsString('valor_mensal', $this->erroDe($resposta)['details']['sort'][0]);
    }

    public function testStatusInvalidoResponde422(): void
    {
        $resposta = $this->buscar('status=PENDENTE');

        $resposta->assertStatus(422);
        $this->assertArrayHasKey('status', $this->erroDe($resposta)['details']);
    }

    public function testParametroDesconhecidoResponde422(): void
    {
        $resposta = $this->buscar('stauts=DRAFT');

        $resposta->assertStatus(422);
        $this->assertStringContainsString('stauts', $this->erroDe($resposta)['details']['query'][0]);
    }

    public function testErrosDeFiltroVoltamJuntos(): void
    {
        $detalhes = $this->erroDe($this->buscar('status=PENDENTE&per_page=500&sort=produto'))['details'];

        $this->assertArrayHasKey('status', $detalhes);
        $this->assertArrayHasKey('per_page', $detalhes);
        $this->assertArrayHasKey('sort', $detalhes);
    }
}
