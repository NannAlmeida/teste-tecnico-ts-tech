<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * @internal
 */
final class RotaDesconhecidaTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private function erroDe($resposta): array
    {
        return json_decode($resposta->getJSON(), true)['error'];
    }

    public function testRotaInexistenteSobApiRespondeJson(): void
    {
        $resposta = $this->get('api/v1/nao-existe');

        $resposta->assertStatus(404);

        $erro = $this->erroDe($resposta);

        $this->assertSame('RESOURCE_NOT_FOUND', $erro['code']);
        $this->assertSame('GET /api/v1/nao-existe', $erro['details']['rota']);
        $this->assertNotEmpty($erro['trace_id']);
    }

    public function testVersaoInexistenteDaApiTambemRespondeJson(): void
    {
        $this->get('api/v9/propostas')->assertStatus(404);
        $this->assertSame('RESOURCE_NOT_FOUND', $this->erroDe($this->get('api/v9/propostas'))['code']);
    }

    public function testPrefixoApiSozinhoRespondeJson(): void
    {
        $resposta = $this->get('api');

        $resposta->assertStatus(404);
        $this->assertSame('RESOURCE_NOT_FOUND', $this->erroDe($resposta)['code']);
    }

    public function testMetodoErradoEmRotaExistenteRespondeJson(): void
    {
        $resposta = $this->get('api/v1/propostas/1/submit');

        $resposta->assertStatus(404);
        $this->assertSame('GET /api/v1/propostas/1/submit', $this->erroDe($resposta)['details']['rota']);
    }

    public function testRotaForaDaApiTambemRespondeJson(): void
    {
        $resposta = $this->get('admin/dashboard');

        $resposta->assertStatus(404);
        $this->assertSame('RESOURCE_NOT_FOUND', $this->erroDe($resposta)['code']);
    }
}
