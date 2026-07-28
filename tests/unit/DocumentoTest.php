<?php

declare(strict_types=1);

use App\Enums\DocumentoTipo;
use App\Exceptions\ValidationException;
use App\ValueObjects\Documento;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class DocumentoTest extends CIUnitTestCase
{
    public static function documentosValidos(): array
    {
        return [
            'CPF' => ['52998224725', DocumentoTipo::CPF],
            'CPF com DV zero' => ['12852760002', DocumentoTipo::CPF],
            'CNPJ numerico legado' => ['11339752000106', DocumentoTipo::CNPJ],
            'CNPJ numerico legado 2' => ['34528927000110', DocumentoTipo::CNPJ],
            'CNPJ alfanumerico do exemplo oficial' => ['12ABC34501DE35', DocumentoTipo::CNPJ],
            'CNPJ alfanumerico com raiz mista' => ['RS7XY2W8000138', DocumentoTipo::CNPJ],
        ];
    }

    /**
     * @dataProvider documentosValidos
     */
    public function testAceitaDocumentoValido(string $bruto, DocumentoTipo $tipoEsperado): void
    {
        $documento = Documento::de($bruto);

        $this->assertSame($bruto, $documento->valor);
        $this->assertSame($tipoEsperado, $documento->tipo);
    }

    public static function documentosInvalidos(): array
    {
        return [
            'CPF com DV errado' => ['52998224726'],
            'CPF curto' => ['5299822472'],
            'CPF longo' => ['529982247250'],
            'CPF com letra' => ['5299822472A'],
            'CPF todo igual' => ['11111111111'],
            'CNPJ com DV errado' => ['11339752000107'],
            'CNPJ alfanumerico com DV errado' => ['12ABC34501DE36'],
            'CNPJ com letra no DV' => ['12ABC34501DE3A'],
            'CNPJ curto' => ['1133975200010'],
            'CNPJ todo igual' => ['AAAAAAAAAAAAAA'],
            'vazio' => [''],
            'so mascara' => ['../-'],
            'texto' => ['nao sou documento'],
        ];
    }

    /**
     * @dataProvider documentosInvalidos
     */
    public function testRecusaDocumentoInvalido(string $bruto): void
    {
        $this->assertFalse(Documento::ehValido($bruto), $bruto);
        $this->assertNull(Documento::tryDe($bruto), $bruto);
    }

    public function testRemoveMascaraAoNormalizar(): void
    {
        $this->assertSame('52998224725', Documento::de('529.982.247-25')->valor);
        $this->assertSame('11339752000106', Documento::de('11.339.752/0001-06')->valor);
        $this->assertSame('12ABC34501DE35', Documento::de('12.ABC.345/01DE-35')->valor);
    }

    public function testNormalizaLetraMinusculaParaMaiuscula(): void
    {
        $documento = Documento::de('12abc34501de35');

        $this->assertSame('12ABC34501DE35', $documento->valor);
        $this->assertSame(DocumentoTipo::CNPJ, $documento->tipo);
    }

    public function testAplicaMascaraNaFormatacao(): void
    {
        $this->assertSame('529.982.247-25', Documento::de('52998224725')->formatado());
        $this->assertSame('11.339.752/0001-06', Documento::de('11339752000106')->formatado());
        $this->assertSame('12.ABC.345/01DE-35', Documento::de('12ABC34501DE35')->formatado());
    }

    public function testDoisDocumentosIguaisSaoEquivalentesMesmoComMascaraDiferente(): void
    {
        $comMascara = Documento::de('12.ABC.345/01DE-35');
        $semMascara = Documento::de('12abc34501de35');

        $this->assertTrue($comMascara->equals($semMascara));
        $this->assertFalse($comMascara->equals(Documento::de('11339752000106')));
    }

    public function testDeLancaValidationExceptionComOCampoCerto(): void
    {
        try {
            Documento::de('11111111111');
            $this->fail('esperava ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(422, $e->getCode());
            $this->assertArrayHasKey('documento', $e->details());
        }
    }

    public function testLetrasSeguemATabelaAsciiMenos48(): void
    {
        $this->assertTrue(Documento::ehValido('12ABC34501DE35'));
        $this->assertFalse(Documento::ehValido('12ABD34501DE35'));
    }

    public function testCnpjAlfanumericoAceitaAsLetrasDesaconselhadas(): void
    {
        $comLetraDesaconselhada = Documento::tryDe('IO0Q0F00000127');

        $this->assertNotNull(
            $comLetraDesaconselhada,
            'I, O, Q e F sao desaconselhadas na emissao, mas continuam validas',
        );
    }
}
