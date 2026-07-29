<?php

declare(strict_types=1);

use CodeIgniter\Config\Factories;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\Mock\MockCache;
use CodeIgniter\Throttle\Throttler;
use Config\RateLimit;
use Config\Services;

/**
 * O limite de producao e alto demais para exercitar por HTTP, e o CIUnitTestCase
 * injeta um MockCache que persiste entre os testes do processo. Aqui a
 * configuracao e o throttler sao injetados, tornando o limite deterministico e
 * isolado — e desfeitos no tearDown para nao vazar para as outras classes.
 *
 * @internal
 */
final class RateLimitTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private const LIMITE = 3;
    private const JANELA = 60;

    protected function setUp(): void
    {
        parent::setUp();

        $config = new RateLimit();
        $config->limite = self::LIMITE;
        $config->janela = self::JANELA;

        Factories::injectMock('config', RateLimit::class, $config);
        Services::injectMock('throttler', new Throttler(new MockCache()));
    }

    protected function tearDown(): void
    {
        Services::resetSingle('throttler');
        Factories::reset('config');

        parent::tearDown();
    }

    private function erroDe($resposta): array
    {
        return json_decode($resposta->getJSON(), true)['error'];
    }

    private function esgotarLimite(): void
    {
        foreach (range(1, self::LIMITE) as $i) {
            $this->get('api/v1/health')->assertStatus(200);
        }
    }

    public function testRequisicoesDentroDoLimitePassam(): void
    {
        $this->esgotarLimite();
    }

    public function testAcimaDoLimiteResponde429NoEnvelopePadrao(): void
    {
        $this->esgotarLimite();

        $resposta = $this->get('api/v1/health');

        $resposta->assertStatus(429);

        $erro = $this->erroDe($resposta);

        $this->assertSame('RATE_LIMITED', $erro['code']);
        $this->assertNotEmpty($erro['message']);
        $this->assertNotEmpty($erro['trace_id']);
    }

    public function testRespostaDeLimiteTrazOsHeadersDeControle(): void
    {
        $this->esgotarLimite();

        $headers = $this->get('api/v1/health')->response();

        $this->assertSame((string) self::LIMITE, $headers->getHeaderLine('X-RateLimit-Limit'));
        $this->assertSame((string) self::JANELA, $headers->getHeaderLine('X-RateLimit-Window'));
        $this->assertGreaterThan(0, (int) $headers->getHeaderLine('Retry-After'));
    }

    public function testOLimiteEhPorClienteENaoPorRota(): void
    {
        $this->esgotarLimite();

        $this->get('api/v1/propostas')->assertStatus(429);
        $this->get('api/v1/clientes/1')->assertStatus(429);
    }

    public function testDocsNaoEhLimitado(): void
    {
        $this->esgotarLimite();
        $this->get('api/v1/health')->assertStatus(429);

        $this->get('docs')->assertStatus(200);
    }

    public function testLimiteDeProducaoVemDaConfiguracao(): void
    {
        Factories::reset('config');

        $padrao = new RateLimit();

        $this->assertSame(60, (new \ReflectionProperty(RateLimit::class, 'limite'))->getDefaultValue());
        $this->assertSame(60, (new \ReflectionProperty(RateLimit::class, 'janela'))->getDefaultValue());
        $this->assertGreaterThan(60, $padrao->limite, 'em teste o limite e elevado de proposito');
    }
}
