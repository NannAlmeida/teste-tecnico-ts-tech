<?php

declare(strict_types=1);

use App\DTO\CreateClienteDTO;
use App\Enums\DocumentoTipo;
use App\Exceptions\ValidationException;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class CreateClienteDTOTest extends CIUnitTestCase
{
    private function validos(array $override = []): array
    {
        return $override + [
            'nome' => 'Ana Carolina Souza',
            'email' => 'ana@exemplo.com',
            'documento' => '529.982.247-25',
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    private function errosDe(array $dados): array
    {
        try {
            CreateClienteDTO::fromArray($dados);
            $this->fail('esperava ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(422, $e->getCode());

            return $e->details();
        }
    }

    public function testNormalizaDocumentoEEmail(): void
    {
        $dto = CreateClienteDTO::fromArray($this->validos(['email' => 'Ana@Exemplo.COM']));

        $this->assertSame('ana@exemplo.com', $dto->email);
        $this->assertSame('52998224725', $dto->documento->valor);
        $this->assertSame(DocumentoTipo::CPF, $dto->documento->tipo);
    }

    public function testAceitaCnpjAlfanumerico(): void
    {
        $dto = CreateClienteDTO::fromArray($this->validos(['documento' => '12.ABC.345/01DE-35']));

        $this->assertSame('12ABC34501DE35', $dto->documento->valor);
        $this->assertSame(DocumentoTipo::CNPJ, $dto->documento->tipo);
    }

    public function testCamposObrigatoriosFaltandoVoltamJuntos(): void
    {
        $erros = $this->errosDe([]);

        $this->assertArrayHasKey('nome', $erros);
        $this->assertArrayHasKey('email', $erros);
        $this->assertArrayHasKey('documento', $erros);
    }

    public function testEmailInvalidoEhRecusado(): void
    {
        $this->assertArrayHasKey('email', $this->errosDe($this->validos(['email' => 'ana@'])));
        $this->assertArrayHasKey('email', $this->errosDe($this->validos(['email' => 'sem-arroba'])));
    }

    public function testDocumentoInvalidoEhRecusado(): void
    {
        $this->assertArrayHasKey('documento', $this->errosDe($this->validos(['documento' => '11111111111'])));
        $this->assertArrayHasKey('documento', $this->errosDe($this->validos(['documento' => '12ABC34501DE36'])));
    }

    public function testNomeForaDoTamanhoEhRecusado(): void
    {
        $this->assertArrayHasKey('nome', $this->errosDe($this->validos(['nome' => 'Ab'])));
        $this->assertArrayHasKey('nome', $this->errosDe($this->validos(['nome' => str_repeat('a', 151)])));
    }

    public function testStringVaziaContaComoAusente(): void
    {
        $erros = $this->errosDe($this->validos(['nome' => '   ']));

        $this->assertSame(['Campo obrigatorio.'], $erros['nome']);
    }

    public function testCampoDesconhecidoEhRecusado(): void
    {
        $erros = $this->errosDe($this->validos(['telefone' => '11999999999']));

        $this->assertArrayHasKey('corpo', $erros);
        $this->assertStringContainsString('telefone', $erros['corpo'][0]);
    }

    public function testPayloadDeIdempotenciaUsaValoresNormalizados(): void
    {
        $comMascara = CreateClienteDTO::fromArray($this->validos(['documento' => '529.982.247-25', 'email' => 'ANA@exemplo.com']));
        $semMascara = CreateClienteDTO::fromArray($this->validos(['documento' => '52998224725', 'email' => 'ana@exemplo.com']));

        $this->assertSame($comMascara->toArray(), $semMascara->toArray());
    }
}
