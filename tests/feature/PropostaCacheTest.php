<?php

declare(strict_types=1);

use App\DTO\CreateClienteDTO;
use App\DTO\CreatePropostaDTO;
use App\DTO\UpdatePropostaDTO;
use App\Entities\Proposta;
use App\Enums\PropostaStatus;
use CodeIgniter\Config\Factories;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\Mock\MockCache;
use Config\ReadCache;
use Config\Services;

/**
 * O cache de leitura fica desligado em ambiente de teste para o MockCache, que
 * persiste entre os testes do processo, nao vazar proposta de um caso para o
 * outro. Aqui ele e ligado explicitamente.
 *
 * @internal
 */
final class PropostaCacheTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = true;
    protected $refresh = true;
    protected $namespace = 'App';

    private int $clienteId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ligarCache(true);
        Services::injectMock('cache', new MockCache());

        $this->clienteId = service('clienteService')->criar(
            CreateClienteDTO::fromArray([
                'nome' => 'Ana Carolina Souza',
                'email' => 'ana@exemplo.com',
                'documento' => '52998224725',
            ]),
            null,
        )['recurso']->id;
    }

    protected function tearDown(): void
    {
        Factories::reset('config');
        Services::resetSingle('cache');

        parent::tearDown();
    }

    private function ligarCache(bool $habilitado): void
    {
        $config = new ReadCache();
        $config->habilitado = $habilitado;

        Factories::injectMock('config', ReadCache::class, $config);
    }

    private function repositorio()
    {
        return service('propostaRepository');
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
            'user:1',
            null,
        )['recurso'];
    }

    /**
     * @return list<string>
     */
    private function selectsDurante(callable $acao): array
    {
        $queries = [];

        Events::on('DBQuery', static function ($query) use (&$queries): void {
            $sql = trim(preg_replace('/\s+/', ' ', (string) $query));

            if (str_starts_with(strtoupper($sql), 'SELECT') && str_contains($sql, 'propostas')) {
                $queries[] = $sql;
            }
        });

        $acao();

        Events::removeAllListeners('DBQuery');

        return $queries;
    }

    public function testSegundaLeituraNaoConsultaOBanco(): void
    {
        $proposta = $this->novaProposta();

        // criar() termina relendo pelo findOrFail, entao a proposta ja sai
        // cacheada; a medicao precisa comecar do zero.
        service('cache')->clean();

        $primeira = $this->selectsDurante(fn () => $this->repositorio()->find($proposta->id));
        $segunda = $this->selectsDurante(fn () => $this->repositorio()->find($proposta->id));

        $this->assertCount(1, $primeira);
        $this->assertCount(0, $segunda, 'a segunda leitura deveria vir do cache');
    }

    public function testLeituraCacheadaDevolveOsMesmosDados(): void
    {
        $proposta = $this->novaProposta();

        $doBanco = $this->repositorio()->find($proposta->id);
        $doCache = $this->repositorio()->find($proposta->id);

        $this->assertSame($doBanco->id, $doCache->id);
        $this->assertSame($doBanco->produto, $doCache->produto);
        $this->assertSame($doBanco->valor_mensal, $doCache->valor_mensal);
        $this->assertSame($doBanco->status, $doCache->status);
        $this->assertSame($doBanco->versao, $doCache->versao);
        $this->assertSame($doBanco->cliente_nome, $doCache->cliente_nome);
    }

    public function testAlteracaoInvalidaOCache(): void
    {
        $proposta = $this->novaProposta();
        $this->repositorio()->find($proposta->id);

        service('propostaService')->alterar(
            $proposta->id,
            UpdatePropostaDTO::fromArray(['versao' => '1', 'produto' => 'Plano Platina']),
            'user:1',
        );

        $depois = $this->repositorio()->find($proposta->id);

        $this->assertSame('Plano Platina', $depois->produto);
        $this->assertSame(2, $depois->versao);
    }

    public function testTransicaoInvalidaOCache(): void
    {
        $proposta = $this->novaProposta();
        $this->repositorio()->find($proposta->id);

        service('propostaService')->submeter($proposta->id, 1, 'user:1');

        $depois = $this->repositorio()->find($proposta->id);

        $this->assertSame(PropostaStatus::SUBMITTED, $depois->status);
        $this->assertSame(2, $depois->versao);
    }

    public function testExclusaoInvalidaOCache(): void
    {
        $proposta = $this->novaProposta();
        $this->repositorio()->find($proposta->id);

        service('propostaService')->excluir($proposta->id, 1, 'user:1');

        $this->assertNull($this->repositorio()->find($proposta->id));
    }

    public function testAusenciaNaoEhCacheada(): void
    {
        $inexistente = 4242;

        $primeira = $this->selectsDurante(fn () => $this->repositorio()->find($inexistente));
        $segunda = $this->selectsDurante(fn () => $this->repositorio()->find($inexistente));

        $this->assertCount(1, $primeira);
        $this->assertCount(1, $segunda, 'negativo cacheado sobreviveria a criacao do id');
    }

    public function testDesabilitadoSempreConsultaOBanco(): void
    {
        $proposta = $this->novaProposta();
        $this->ligarCache(false);

        $primeira = $this->selectsDurante(fn () => $this->repositorio()->find($proposta->id));
        $segunda = $this->selectsDurante(fn () => $this->repositorio()->find($proposta->id));

        $this->assertCount(1, $primeira);
        $this->assertCount(1, $segunda);
    }

    public function testCacheEhIsoladoPorProposta(): void
    {
        $uma = $this->novaProposta();
        $outra = service('propostaService')->criar(
            CreatePropostaDTO::fromArray([
                'cliente_id' => (string) $this->clienteId,
                'produto' => 'Plano Platina',
                'valor_mensal' => '1899.90',
                'origem' => 'SITE',
            ]),
            'user:1',
            null,
        )['recurso'];

        $this->repositorio()->find($uma->id);
        $this->repositorio()->find($outra->id);

        service('propostaService')->submeter($uma->id, 1, 'user:1');

        $this->assertSame(PropostaStatus::SUBMITTED, $this->repositorio()->find($uma->id)->status);
        $this->assertSame(PropostaStatus::DRAFT, $this->repositorio()->find($outra->id)->status);
    }
}
