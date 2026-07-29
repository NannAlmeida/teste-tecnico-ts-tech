<?php

declare(strict_types=1);

use App\DTO\PaginacaoQuery;
use App\Enums\PropostaOrigem;
use App\Enums\PropostaStatus;
use App\Http\ErrorCode;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * @internal
 */
final class OpenApiSpecTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private const VERBOS = ['GET', 'POST', 'PATCH', 'DELETE'];

    private array $spec;

    protected function setUp(): void
    {
        parent::setUp();

        $conteudo = file_get_contents(FCPATH . 'openapi.json');

        $this->assertNotFalse($conteudo, 'openapi.json nao encontrado em public/');

        $this->spec = json_decode($conteudo, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, list<string>> [caminho => verbos]
     */
    private function rotasRegistradas(): array
    {
        $routes = service('routes');
        $routes->loadRoutes();

        $registradas = [];

        foreach (self::VERBOS as $verbo) {
            foreach (array_keys($routes->getRoutes($verbo)) as $rota) {
                if (! str_starts_with($rota, 'api/')) {
                    continue;
                }

                $caminho = '/' . str_replace('([0-9]+)', '{id}', $rota);
                $registradas[$caminho][] = $verbo;
            }
        }

        return $registradas;
    }

    /**
     * @return array<string, list<string>> [caminho => verbos]
     */
    private function rotasDocumentadas(): array
    {
        $documentadas = [];

        foreach ($this->spec['paths'] as $caminho => $operacoes) {
            $documentadas[$caminho] = array_map('strtoupper', array_keys($operacoes));
        }

        return $documentadas;
    }

    /**
     * @return list<string>
     */
    private function referencias(array $no): array
    {
        $encontradas = [];

        foreach ($no as $chave => $valor) {
            if ($chave === '$ref' && is_string($valor)) {
                $encontradas[] = $valor;
            } elseif (is_array($valor)) {
                $encontradas = array_merge($encontradas, $this->referencias($valor));
            }
        }

        return $encontradas;
    }

    private function resolve(string $ref): mixed
    {
        $no = $this->spec;

        foreach (array_slice(explode('/', $ref), 1) as $segmento) {
            if (! is_array($no) || ! array_key_exists($segmento, $no)) {
                return null;
            }

            $no = $no[$segmento];
        }

        return $no;
    }

    public function testSpecEhJsonValidoEDeclaraVersaoDoOpenApi(): void
    {
        $this->assertSame('3.1.0', $this->spec['openapi']);
        $this->assertNotEmpty($this->spec['info']['title']);
        $this->assertNotEmpty($this->spec['info']['version']);
    }

    public function testTodaRotaRegistradaEstaDocumentada(): void
    {
        $documentadas = $this->rotasDocumentadas();

        foreach ($this->rotasRegistradas() as $caminho => $verbos) {
            $this->assertArrayHasKey($caminho, $documentadas, "rota {$caminho} nao esta na spec");

            foreach ($verbos as $verbo) {
                $this->assertContains($verbo, $documentadas[$caminho], "{$verbo} {$caminho} nao esta na spec");
            }
        }
    }

    public function testSpecNaoDocumentaRotaInexistente(): void
    {
        $registradas = $this->rotasRegistradas();

        foreach ($this->rotasDocumentadas() as $caminho => $verbos) {
            $this->assertArrayHasKey($caminho, $registradas, "spec documenta {$caminho}, que nao existe");

            foreach ($verbos as $verbo) {
                $this->assertContains($verbo, $registradas[$caminho], "spec documenta {$verbo} {$caminho}, que nao existe");
            }
        }
    }

    public function testTodaReferenciaApontaParaAlgoQueExiste(): void
    {
        $refs = array_unique($this->referencias($this->spec));

        $this->assertNotEmpty($refs);

        foreach ($refs as $ref) {
            $this->assertNotNull($this->resolve($ref), "referencia quebrada: {$ref}");
        }
    }

    public function testCodigosDeErroDaSpecBatemComOEnum(): void
    {
        $naSpec = $this->spec['components']['schemas']['Erro']['properties']['error']['properties']['code']['enum'];

        $this->assertEqualsCanonicalizing(array_column(ErrorCode::cases(), 'value'), $naSpec);
    }

    public function testStatusEOrigemDaSpecBatemComOsEnums(): void
    {
        $status = $this->spec['components']['schemas']['Proposta']['properties']['status']['enum'];
        $origem = $this->spec['components']['schemas']['Proposta']['properties']['origem']['enum'];

        $this->assertEqualsCanonicalizing(array_column(PropostaStatus::cases(), 'value'), $status);
        $this->assertEqualsCanonicalizing(array_column(PropostaOrigem::cases(), 'value'), $origem);
    }

    public function testTetoDePaginacaoDaSpecBateComOCodigo(): void
    {
        $perPage = $this->spec['components']['parameters']['PerPage']['schema'];

        $this->assertSame(PaginacaoQuery::PER_PAGE_MAX, $perPage['maximum']);
        $this->assertSame(PaginacaoQuery::PER_PAGE_DEFAULT, $perPage['default']);
    }

    public function testDocsRenderizaOSwaggerApontandoParaASpec(): void
    {
        $resposta = $this->get('docs');

        $resposta->assertStatus(200);

        $html = $resposta->getBody();

        $this->assertStringContainsString('swagger-ui-bundle.js', $html);
        $this->assertStringContainsString('id="swagger"', $html);
        $this->assertStringContainsString('url: "/openapi.json"', $html);
        $this->assertStringNotContainsString('&#x2F;', $html, 'entidade HTML nao e decodificada dentro de script');
    }

    public function testRaizServeADocumentacaoNoLugarDaPaginaDoStarter(): void
    {
        $resposta = $this->get('/');

        $resposta->assertStatus(200);

        $html = $resposta->getBody();

        $this->assertStringContainsString('swagger-ui-bundle.js', $html);
        $this->assertStringContainsString('url: "/openapi.json"', $html);
        $this->assertStringNotContainsString('Welcome to CodeIgniter', $html);
    }

    public function testTodaOperacaoTemResumoERespostaDeSucesso(): void
    {
        foreach ($this->spec['paths'] as $caminho => $operacoes) {
            foreach ($operacoes as $verbo => $operacao) {
                $rotulo = strtoupper($verbo) . ' ' . $caminho;

                $this->assertNotEmpty($operacao['summary'] ?? '', "{$rotulo} sem summary");

                $sucesso = array_filter(
                    array_keys($operacao['responses']),
                    static fn (string $codigo): bool => str_starts_with($codigo, '2'),
                );

                $this->assertNotEmpty($sucesso, "{$rotulo} sem resposta de sucesso");
            }
        }
    }
}
