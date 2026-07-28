<?php

declare(strict_types=1);

use App\Services\AuditoriaService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class AuditoriaDiffTest extends CIUnitTestCase
{
    public function testSemMudancaDevolveVazio(): void
    {
        $antes = ['produto' => 'Plano Ouro', 'valor_mensal' => '1250.00'];

        $this->assertSame([], AuditoriaService::diff($antes, $antes));
    }

    public function testRegistraApenasOsCamposAlterados(): void
    {
        $diff = AuditoriaService::diff(
            ['produto' => 'Plano Ouro', 'valor_mensal' => '1250.00', 'origem' => 'API'],
            ['produto' => 'Plano Platina', 'valor_mensal' => '1250.00'],
        );

        $this->assertSame(['produto' => ['de' => 'Plano Ouro', 'para' => 'Plano Platina']], $diff);
    }

    public function testCampoAusenteNoEstadoAnteriorApareceComoNulo(): void
    {
        $diff = AuditoriaService::diff([], ['produto' => 'Plano Ouro']);

        $this->assertSame(['produto' => ['de' => null, 'para' => 'Plano Ouro']], $diff);
    }

    public function testCampoNaoEnviadoNoPatchNaoEntraNoDiff(): void
    {
        $diff = AuditoriaService::diff(
            ['produto' => 'Plano Ouro', 'valor_mensal' => '1250.00'],
            ['valor_mensal' => '1899.90'],
        );

        $this->assertArrayNotHasKey('produto', $diff);
        $this->assertSame(['valor_mensal' => ['de' => '1250.00', 'para' => '1899.90']], $diff);
    }

    public function testComparacaoEhEstritaEntreTipos(): void
    {
        $diff = AuditoriaService::diff(['versao' => 1], ['versao' => '1']);

        $this->assertSame(['versao' => ['de' => 1, 'para' => '1']], $diff);
    }
}
