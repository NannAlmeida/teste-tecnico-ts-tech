<?php

declare(strict_types=1);

use App\DTO\PropostaQuery;
use App\Enums\PropostaOrigem;
use App\Enums\PropostaStatus;
use App\Exceptions\ValidationException;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class PropostaQueryTest extends CIUnitTestCase
{
    /**
     * @return array<string, list<string>>
     */
    private function errosDe(array $params): array
    {
        try {
            PropostaQuery::fromArray($params);
            $this->fail('esperava ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(422, $e->getCode());

            return $e->details();
        }
    }

    public function testSemParametrosAplicaOsPadroes(): void
    {
        $query = PropostaQuery::fromArray([]);

        $this->assertSame(1, $query->page);
        $this->assertSame(20, $query->perPage);
        $this->assertSame(0, $query->offset());
        $this->assertSame([['campo' => 'created_at', 'direcao' => 'DESC']], $query->sort);
        $this->assertFalse($query->incluirExcluidas);
        $this->assertSame([], $query->status);
        $this->assertNull($query->produto);
    }

    public function testStatusEOrigemViramEnumIgnorandoCaixaEEspaco(): void
    {
        $query = PropostaQuery::fromArray(['status' => ' draft , SUBMITTED ', 'origem' => 'api']);

        $this->assertSame([PropostaStatus::DRAFT, PropostaStatus::SUBMITTED], $query->status);
        $this->assertSame([PropostaOrigem::API], $query->origem);
    }

    public function testStatusRepetidoNaoDuplicaFiltro(): void
    {
        $query = PropostaQuery::fromArray(['status' => 'DRAFT,DRAFT,draft']);

        $this->assertSame([PropostaStatus::DRAFT], $query->status);
    }

    public function testStatusInvalidoListaOsAceitos(): void
    {
        $erros = $this->errosDe(['status' => 'PENDENTE']);

        $this->assertArrayHasKey('status', $erros);
        $this->assertStringContainsString('PENDENTE', $erros['status'][0]);
        $this->assertStringContainsString('DRAFT', $erros['status'][0]);
    }

    public function testOrdenacaoAceitaPrefixoDeDirecaoEMultiplasColunas(): void
    {
        $query = PropostaQuery::fromArray(['sort' => '-valor_mensal,created_at']);

        $this->assertSame([
            ['campo' => 'valor_mensal', 'direcao' => 'DESC'],
            ['campo' => 'created_at', 'direcao' => 'ASC'],
        ], $query->sort);
    }

    public function testColunaForaDaWhitelistEhRecusada(): void
    {
        $erros = $this->errosDe(['sort' => 'produto,(SELECT 1)']);

        $this->assertArrayHasKey('sort', $erros);
        $this->assertStringContainsString('valor_mensal', $erros['sort'][0]);
    }

    public function testPerPageRespeitaOTeto(): void
    {
        $this->assertSame(100, PropostaQuery::fromArray(['per_page' => '100'])->perPage);
        $this->assertArrayHasKey('per_page', $this->errosDe(['per_page' => '101']));
        $this->assertArrayHasKey('per_page', $this->errosDe(['per_page' => '0']));
    }

    public function testOffsetAcompanhaAPagina(): void
    {
        $query = PropostaQuery::fromArray(['page' => '4', 'per_page' => '25']);

        $this->assertSame(75, $query->offset());
    }

    public function testDataSemHoraViraIntervaloFechadoDoDia(): void
    {
        $query = PropostaQuery::fromArray(['created_from' => '2026-07-01', 'created_to' => '2026-07-31']);

        $this->assertSame('2026-07-01 00:00:00', $query->criadoDe);
        $this->assertSame('2026-07-31 23:59:59', $query->criadoAte);
    }

    public function testDataComHoraEhPreservada(): void
    {
        $query = PropostaQuery::fromArray(['created_from' => '2026-07-01 08:30:00']);

        $this->assertSame('2026-07-01 08:30:00', $query->criadoDe);
    }

    public function testDataInexistenteEhRecusada(): void
    {
        $this->assertArrayHasKey('created_from', $this->errosDe(['created_from' => '2026-02-30']));
        $this->assertArrayHasKey('created_to', $this->errosDe(['created_to' => '31/07/2026']));
    }

    public function testValorAceitaAteDuasCasasENaoAceitaNegativo(): void
    {
        $this->assertSame('199.90', PropostaQuery::fromArray(['valor_min' => '199.90'])->valorMin);
        $this->assertArrayHasKey('valor_min', $this->errosDe(['valor_min' => '-10']));
        $this->assertArrayHasKey('valor_max', $this->errosDe(['valor_max' => '10.999']));
    }

    public function testIntervaloDeValorInvertidoEhRecusado(): void
    {
        $erros = $this->errosDe(['valor_min' => '900.00', 'valor_max' => '199.90']);

        $this->assertArrayHasKey('valor_min', $erros);
    }

    public function testIntervaloDeValorNoLimiteEhAceito(): void
    {
        $query = PropostaQuery::fromArray(['valor_min' => '199.90', 'valor_max' => '199.90']);

        $this->assertSame('199.90', $query->valorMin);
    }

    public function testIntervaloDeDataInvertidoEhRecusado(): void
    {
        $erros = $this->errosDe(['created_from' => '2026-07-31', 'created_to' => '2026-07-01']);

        $this->assertArrayHasKey('created_from', $erros);
    }

    public function testIncluirExcluidasAceitaAsFormasUsuais(): void
    {
        $this->assertTrue(PropostaQuery::fromArray(['incluir_excluidas' => 'true'])->incluirExcluidas);
        $this->assertTrue(PropostaQuery::fromArray(['incluir_excluidas' => '1'])->incluirExcluidas);
        $this->assertFalse(PropostaQuery::fromArray(['incluir_excluidas' => 'false'])->incluirExcluidas);
        $this->assertArrayHasKey('incluir_excluidas', $this->errosDe(['incluir_excluidas' => 'sim']));
    }

    public function testParametroDesconhecidoEhRecusado(): void
    {
        $erros = $this->errosDe(['stauts' => 'DRAFT']);

        $this->assertArrayHasKey('query', $erros);
        $this->assertStringContainsString('stauts', $erros['query'][0]);
    }

    public function testTodosOsErrosVoltamDeUmaVez(): void
    {
        $erros = $this->errosDe([
            'status' => 'PENDENTE',
            'sort' => 'produto',
            'per_page' => '500',
            'page' => '0',
            'cliente_id' => 'abc',
        ]);

        $this->assertSame(
            ['status', 'sort', 'cliente_id', 'page', 'per_page'],
            array_values(array_intersect(['status', 'sort', 'cliente_id', 'page', 'per_page'], array_keys($erros))),
        );
    }

    public function testStringVaziaEhTratadaComoAusente(): void
    {
        $query = PropostaQuery::fromArray(['produto' => '  ', 'status' => '', 'q' => '']);

        $this->assertNull($query->produto);
        $this->assertSame([], $query->status);
        $this->assertFalse($query->temBuscaTextual());
    }
}
