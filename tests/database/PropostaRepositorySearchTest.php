<?php

declare(strict_types=1);

use App\DTO\PropostaQuery;
use App\Entities\Cliente;
use App\Entities\Proposta;
use App\Enums\PropostaOrigem;
use App\Enums\PropostaStatus;
use App\Models\ClienteModel;
use App\Models\PropostaModel;
use App\Repositories\ClienteRepository;
use App\Repositories\PropostaRepository;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class PropostaRepositorySearchTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = true;
    protected $refresh = true;
    protected $namespace = 'App';

    private ClienteRepository $clientes;
    private PropostaRepository $propostas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clientes = new ClienteRepository(new ClienteModel());
        $this->propostas = new PropostaRepository(new PropostaModel());
    }

    private function cliente(string $nome, string $email): Cliente
    {
        $cliente = new Cliente();
        $cliente->fill([
            'nome' => $nome,
            'email' => $email,
            'documento' => (string) random_int(10000000000, 99999999999),
            'documento_tipo' => 'CPF',
        ]);

        return $this->clientes->insert($cliente);
    }

    private function proposta(int $clienteId, array $override = []): Proposta
    {
        $proposta = new Proposta();
        $proposta->fill($override + [
            'cliente_id' => $clienteId,
            'produto' => 'Plano Ouro',
            'valor_mensal' => '1250.00',
            'status' => PropostaStatus::DRAFT,
            'origem' => PropostaOrigem::API,
            'versao' => 1,
        ]);

        return $this->propostas->insert($proposta);
    }

    private function buscar(array $params): array
    {
        return $this->propostas->search(PropostaQuery::fromArray($params));
    }

    /**
     * @return list<string>
     */
    private function produtosDe(array $resultado): array
    {
        return array_map(static fn (Proposta $p): string => $p->produto, $resultado['items']);
    }

    private function cenario(): void
    {
        $ana = $this->cliente('Ana Carolina Souza', 'ana@exemplo.com')->id;
        $bruno = $this->cliente('Bruno Ferreira Lima', 'bruno@exemplo.com')->id;

        $this->proposta($ana, ['produto' => 'Plano Essencial', 'valor_mensal' => '199.90', 'status' => PropostaStatus::DRAFT, 'origem' => PropostaOrigem::APP]);
        $this->proposta($ana, ['produto' => 'Plano Ouro', 'valor_mensal' => '899.90', 'status' => PropostaStatus::SUBMITTED, 'origem' => PropostaOrigem::SITE]);
        $this->proposta($bruno, ['produto' => 'Plano Platina', 'valor_mensal' => '1899.90', 'status' => PropostaStatus::APPROVED, 'origem' => PropostaOrigem::API]);
        $this->proposta($bruno, ['produto' => 'Seguro Residencial', 'valor_mensal' => '349.90', 'status' => PropostaStatus::CANCELED, 'origem' => PropostaOrigem::APP]);
    }

    public function testTrazTodasComOClienteEmbutido(): void
    {
        $this->cenario();

        $resultado = $this->buscar([]);

        $this->assertSame(4, $resultado['total']);
        $this->assertCount(4, $resultado['items']);
        $this->assertNotNull($resultado['items'][0]->cliente_nome);
        $this->assertNotNull($resultado['items'][0]->cliente_email);
    }

    public function testFiltraPorStatusEOrigem(): void
    {
        $this->cenario();

        $this->assertSame(['Plano Ouro'], $this->produtosDe($this->buscar(['status' => 'SUBMITTED'])));
        $this->assertSame(2, $this->buscar(['origem' => 'APP'])['total']);
        $this->assertSame(3, $this->buscar(['status' => 'DRAFT,SUBMITTED,APPROVED'])['total']);
    }

    public function testCombinaFiltrosComAnd(): void
    {
        $this->cenario();

        $resultado = $this->buscar(['status' => 'DRAFT,SUBMITTED', 'origem' => 'SITE']);

        $this->assertSame(['Plano Ouro'], $this->produtosDe($resultado));
    }

    public function testFiltraPorFaixaDeValor(): void
    {
        $this->cenario();

        $this->assertSame(2, $this->buscar(['valor_min' => '349.90', 'valor_max' => '899.90'])['total']);
        $this->assertSame(1, $this->buscar(['valor_min' => '1000.00'])['total']);
    }

    public function testFiltraPorProdutoParcial(): void
    {
        $this->cenario();

        $this->assertSame(3, $this->buscar(['produto' => 'Plano'])['total']);
        $this->assertSame(1, $this->buscar(['produto' => 'Seguro'])['total']);
    }

    public function testBuscaTextualAlcancaProdutoENomeDoCliente(): void
    {
        $this->cenario();

        $this->assertSame(2, $this->buscar(['q' => 'Bruno'])['total']);
        $this->assertSame(1, $this->buscar(['q' => 'Residencial'])['total']);
        $this->assertSame(2, $this->buscar(['q' => 'ana@exemplo.com'])['total']);
    }

    public function testBuscaTextualNaoVazaOOrParaForaDosOutrosFiltros(): void
    {
        $this->cenario();

        $resultado = $this->buscar(['q' => 'Bruno', 'status' => 'APPROVED']);

        $this->assertSame(['Plano Platina'], $this->produtosDe($resultado));
    }

    public function testOrdenaPorValorNasDuasDirecoes(): void
    {
        $this->cenario();

        $this->assertSame(
            ['Plano Essencial', 'Seguro Residencial', 'Plano Ouro', 'Plano Platina'],
            $this->produtosDe($this->buscar(['sort' => 'valor_mensal'])),
        );

        $this->assertSame(
            ['Plano Platina', 'Plano Ouro', 'Seguro Residencial', 'Plano Essencial'],
            $this->produtosDe($this->buscar(['sort' => '-valor_mensal'])),
        );
    }

    public function testPaginaLimitaOsItensMasNaoOTotal(): void
    {
        $this->cenario();

        $primeira = $this->buscar(['sort' => 'valor_mensal', 'per_page' => '3', 'page' => '1']);
        $segunda = $this->buscar(['sort' => 'valor_mensal', 'per_page' => '3', 'page' => '2']);

        $this->assertSame(4, $primeira['total']);
        $this->assertSame(4, $segunda['total']);
        $this->assertCount(3, $primeira['items']);
        $this->assertCount(1, $segunda['items']);
        $this->assertSame(['Plano Platina'], $this->produtosDe($segunda));
    }

    public function testExcluidasFicamDeForaPorPadrao(): void
    {
        $this->cenario();
        $alvo = $this->buscar(['produto' => 'Seguro'])['items'][0];
        $this->propostas->softDelete($alvo->id, $alvo->versao);

        $this->assertSame(3, $this->buscar([])['total']);
        $this->assertSame(4, $this->buscar(['incluir_excluidas' => 'true'])['total']);
    }

    public function testFiltroSemResultadoDevolveListaVazia(): void
    {
        $this->cenario();

        $resultado = $this->buscar(['produto' => 'Consorcio']);

        $this->assertSame(0, $resultado['total']);
        $this->assertSame([], $resultado['items']);
    }

    public function testCustoEmQueriesNaoCresceComONumeroDeLinhas(): void
    {
        $ana = $this->cliente('Ana Carolina Souza', 'ana@exemplo.com')->id;
        $bruno = $this->cliente('Bruno Ferreira Lima', 'bruno@exemplo.com')->id;

        foreach (range(1, 30) as $i) {
            $this->proposta($i % 2 === 0 ? $ana : $bruno, ['produto' => "Plano {$i}"]);
        }

        $queries = [];
        Events::on('DBQuery', static function ($query) use (&$queries): void {
            $queries[] = trim(preg_replace('/\s+/', ' ', (string) $query));
        });

        $resultado = $this->propostas->search(PropostaQuery::fromArray(['per_page' => '30']));

        $selects = array_values(array_filter(
            $queries,
            static fn (string $sql): bool => str_starts_with(strtoupper($sql), 'SELECT'),
        ));

        $this->assertCount(30, $resultado['items']);
        $this->assertSame(30, $resultado['total']);
        $this->assertCount(2, $selects, "esperava COUNT + SELECT, veio:\n" . implode("\n", $selects));

        foreach ($resultado['items'] as $proposta) {
            $this->assertContains($proposta->cliente_nome, ['Ana Carolina Souza', 'Bruno Ferreira Lima']);
        }
    }
}
