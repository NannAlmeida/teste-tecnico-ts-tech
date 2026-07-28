<?php

declare(strict_types=1);

use App\Entities\Cliente;
use App\Entities\Proposta;
use App\Enums\PropostaOrigem;
use App\Enums\PropostaStatus;
use App\Exceptions\DuplicateResourceException;
use App\Exceptions\ResourceNotFoundException;
use App\Exceptions\VersionConflictException;
use App\Models\ClienteModel;
use App\Models\PropostaModel;
use App\Repositories\ClienteRepository;
use App\Repositories\PropostaRepository;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class OptimisticLockTest extends CIUnitTestCase
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

    private function novoCliente(array $override = []): Cliente
    {
        $cliente = new Cliente();
        $cliente->fill($override + [
            'nome' => 'Ana Carolina Souza',
            'email' => uniqid('ana', true) . '@exemplo.com',
            'documento' => (string) random_int(10000000000, 99999999999),
            'documento_tipo' => 'CPF',
        ]);

        return $this->clientes->insert($cliente);
    }

    private function novaProposta(array $override = []): Proposta
    {
        $proposta = new Proposta();
        $proposta->fill($override + [
            'cliente_id' => $this->novoCliente()->id,
            'produto' => 'Plano Ouro',
            'valor_mensal' => '1250.00',
            'status' => PropostaStatus::DRAFT,
            'origem' => PropostaOrigem::API,
            'versao' => 1,
        ]);

        return $this->propostas->insert($proposta);
    }

    public function testUpdateComVersaoCorretaAplicaEIncrementa(): void
    {
        $proposta = $this->novaProposta();

        $atualizada = $this->propostas->updateWithVersion($proposta->id, 1, ['produto' => 'Plano Platina']);

        $this->assertSame('Plano Platina', $atualizada->produto);
        $this->assertSame(2, $atualizada->versao);
    }

    public function testSegundaEscritaComAMesmaVersaoPerde(): void
    {
        $proposta = $this->novaProposta();

        $this->propostas->updateWithVersion($proposta->id, 1, ['produto' => 'Primeira']);

        try {
            $this->propostas->updateWithVersion($proposta->id, 1, ['produto' => 'Segunda']);
            $this->fail('esperava VersionConflictException');
        } catch (VersionConflictException $e) {
            $this->assertSame(409, $e->getCode());
            $this->assertSame(['versao_enviada' => 1, 'versao_atual' => 2], $e->details());
        }

        $this->assertSame('Primeira', $this->propostas->findOrFail($proposta->id)->produto);
        $this->assertSame(2, $this->propostas->findOrFail($proposta->id)->versao);
    }

    public function testUpdateQueNaoMudaNadaAindaAssimSeguraOLock(): void
    {
        $proposta = $this->novaProposta();

        $inalterada = $this->propostas->updateWithVersion($proposta->id, 1, ['produto' => 'Plano Ouro']);

        $this->assertSame(2, $inalterada->versao);
    }

    public function testUpdateEmPropostaInexistenteDaNotFound(): void
    {
        $this->expectException(ResourceNotFoundException::class);

        $this->propostas->updateWithVersion(9999, 1, ['produto' => 'X']);
    }

    public function testUpdateEmPropostaExcluidaDaNotFound(): void
    {
        $proposta = $this->novaProposta();
        $this->propostas->softDelete($proposta->id, 1);

        $this->expectException(ResourceNotFoundException::class);

        $this->propostas->updateWithVersion($proposta->id, 2, ['produto' => 'X']);
    }

    public function testSoftDeleteRespeitaAVersaoEIncrementa(): void
    {
        $proposta = $this->novaProposta();

        $this->propostas->softDelete($proposta->id, 1);

        $this->assertNull($this->propostas->find($proposta->id));
        $this->seeInDatabase('propostas', ['id' => $proposta->id, 'versao' => 2]);
        $this->dontSeeInDatabase('propostas', ['id' => $proposta->id, 'deleted_at' => null]);
    }

    public function testSoftDeleteComVersaoDefasadaFalha(): void
    {
        $proposta = $this->novaProposta();
        $this->propostas->updateWithVersion($proposta->id, 1, ['produto' => 'Outro']);

        $this->expectException(VersionConflictException::class);

        $this->propostas->softDelete($proposta->id, 1);
    }

    public function testEmailDuplicadoViraDuplicateResource(): void
    {
        $existente = $this->novoCliente();

        try {
            $this->novoCliente(['email' => $existente->email]);
            $this->fail('esperava DuplicateResourceException');
        } catch (DuplicateResourceException $e) {
            $this->assertSame(409, $e->getCode());
            $this->assertSame(['campo' => 'email', 'valor' => $existente->email], $e->details());
        }
    }

    public function testDocumentoDuplicadoViraDuplicateResource(): void
    {
        $existente = $this->novoCliente();

        try {
            $this->novoCliente(['documento' => $existente->documento]);
            $this->fail('esperava DuplicateResourceException');
        } catch (DuplicateResourceException $e) {
            $this->assertSame(['campo' => 'documento', 'valor' => $existente->documento], $e->details());
        }
    }

    public function testBuscaPorChaveDeIdempotenciaEncontraPropostaExcluida(): void
    {
        $proposta = $this->novaProposta(['idempotency_key' => 'chave-abc']);
        $this->propostas->softDelete($proposta->id, 1);

        $this->assertNull($this->propostas->find($proposta->id));
        $this->assertNotNull($this->propostas->findByIdempotencyKey('chave-abc'));
    }

    public function testFindOrFailDescreveORecursoQueFaltou(): void
    {
        try {
            $this->propostas->findOrFail(4242);
            $this->fail('esperava ResourceNotFoundException');
        } catch (ResourceNotFoundException $e) {
            $this->assertSame(404, $e->getCode());
            $this->assertSame(['recurso' => 'proposta', 'id' => 4242], $e->details());
        }
    }
}
