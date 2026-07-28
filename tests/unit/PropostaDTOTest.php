<?php

declare(strict_types=1);

use App\DTO\CreatePropostaDTO;
use App\DTO\UpdatePropostaDTO;
use App\Enums\PropostaOrigem;
use App\Exceptions\MalformedRequestException;
use App\Exceptions\ValidationException;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class PropostaDTOTest extends CIUnitTestCase
{
    private function criacao(array $override = []): array
    {
        return $override + [
            'cliente_id' => '7',
            'produto' => 'Plano Ouro',
            'valor_mensal' => '1250.00',
            'origem' => 'API',
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    private function errosDe(callable $construcao): array
    {
        try {
            $construcao();
            $this->fail('esperava ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(422, $e->getCode());

            return $e->details();
        }
    }

    public function testCriacaoNormalizaValorEOrigem(): void
    {
        $dto = CreatePropostaDTO::fromArray($this->criacao(['valor_mensal' => '199.9', 'origem' => 'app']));

        $this->assertSame('199.90', $dto->valorMensal);
        $this->assertSame(PropostaOrigem::APP, $dto->origem);
        $this->assertSame(7, $dto->clienteId);
    }

    public function testCriacaoExigeTodosOsCampos(): void
    {
        $erros = $this->errosDe(static fn () => CreatePropostaDTO::fromArray([]));

        $this->assertArrayHasKey('cliente_id', $erros);
        $this->assertArrayHasKey('produto', $erros);
        $this->assertArrayHasKey('valor_mensal', $erros);
        $this->assertArrayHasKey('origem', $erros);
    }

    public function testValorPrecisaSerPositivoEComDuasCasas(): void
    {
        foreach (['0', '-5', '10.999', 'abc'] as $invalido) {
            $erros = $this->errosDe(fn () => CreatePropostaDTO::fromArray($this->criacao(['valor_mensal' => $invalido])));

            $this->assertArrayHasKey('valor_mensal', $erros, $invalido);
        }
    }

    public function testOrigemForaDoEnumEhRecusada(): void
    {
        $erros = $this->errosDe(fn () => CreatePropostaDTO::fromArray($this->criacao(['origem' => 'WHATSAPP'])));

        $this->assertStringContainsString('APP', $erros['origem'][0]);
    }

    public function testPayloadDeIdempotenciaUsaValoresNormalizados(): void
    {
        $a = CreatePropostaDTO::fromArray($this->criacao(['valor_mensal' => '199.9', 'origem' => 'app']));
        $b = CreatePropostaDTO::fromArray($this->criacao(['valor_mensal' => '199.90', 'origem' => 'APP']));

        $this->assertSame($a->toArray(), $b->toArray());
    }

    public function testAlteracaoSoLevaOsCamposPresentes(): void
    {
        $dto = UpdatePropostaDTO::fromArray(['versao' => '2', 'produto' => 'Plano Platina']);

        $this->assertSame(2, $dto->versao);
        $this->assertSame(['produto' => 'Plano Platina'], $dto->alteracoes);
    }

    public function testAlteracaoNormalizaValor(): void
    {
        $dto = UpdatePropostaDTO::fromArray(['versao' => '1', 'valor_mensal' => '349.9']);

        $this->assertSame(['valor_mensal' => '349.90'], $dto->alteracoes);
    }

    public function testAlteracaoSemVersaoEhMalformedRequest(): void
    {
        try {
            UpdatePropostaDTO::fromArray(['produto' => 'Plano Platina']);
            $this->fail('esperava MalformedRequestException');
        } catch (MalformedRequestException $e) {
            $this->assertSame(422, $e->getCode());
            $this->assertSame('MISSING_VERSION', $e->errorCode()->value);
        }
    }

    public function testAlteracaoSemNenhumCampoAlteravelEhRecusada(): void
    {
        $erros = $this->errosDe(static fn () => UpdatePropostaDTO::fromArray(['versao' => '1']));

        $this->assertArrayHasKey('corpo', $erros);
        $this->assertStringContainsString('produto', $erros['corpo'][0]);
    }

    public function testAlteracaoNaoAceitaCampoNaoAlteravel(): void
    {
        $erros = $this->errosDe(static fn () => UpdatePropostaDTO::fromArray(['versao' => '1', 'status' => 'APPROVED']));

        $this->assertArrayHasKey('corpo', $erros);
        $this->assertStringContainsString('status', $erros['corpo'][0]);
    }
}
