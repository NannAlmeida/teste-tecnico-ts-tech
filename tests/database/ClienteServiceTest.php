<?php

declare(strict_types=1);

use App\DTO\CreateClienteDTO;
use App\Enums\DocumentoTipo;
use App\Exceptions\DuplicateResourceException;
use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\ResourceNotFoundException;
use App\Models\ClienteModel;
use App\Repositories\ClienteRepository;
use App\Services\ClienteService;
use App\Services\IdempotencyService;
use App\Services\TransactionRunner;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class ClienteServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = true;
    protected $refresh = true;
    protected $namespace = 'App';

    private ClienteService $servico;

    protected function setUp(): void
    {
        parent::setUp();

        $this->servico = new ClienteService(
            new ClienteRepository(new ClienteModel()),
            new IdempotencyService(new TransactionRunner($this->db)),
        );
    }

    private function criar(array $override = [], ?string $chave = null): array
    {
        return $this->servico->criar(
            CreateClienteDTO::fromArray($override + [
                'nome' => 'Ana Carolina Souza',
                'email' => 'ana@exemplo.com',
                'documento' => '529.982.247-25',
            ]),
            $chave,
        );
    }

    private function total(): int
    {
        return $this->db->table('clientes')->countAllResults();
    }

    public function testCriaClienteComDocumentoNormalizadoETipoDetectado(): void
    {
        $cliente = $this->criar()['recurso'];

        $this->assertSame('52998224725', $cliente->documento);
        $this->assertSame(DocumentoTipo::CPF, $cliente->documento_tipo);
        $this->assertSame('ana@exemplo.com', $cliente->email);
        $this->assertNotNull($cliente->id);
        $this->seeInDatabase('clientes', ['id' => $cliente->id, 'documento' => '52998224725', 'documento_tipo' => 'CPF']);
    }

    public function testCriaComCnpjAlfanumerico(): void
    {
        $cliente = $this->criar(['documento' => '12.ABC.345/01DE-35'])['recurso'];

        $this->assertSame('12ABC34501DE35', $cliente->documento);
        $this->assertSame(DocumentoTipo::CNPJ, $cliente->documento_tipo);
    }

    public function testEmailRepetidoResponde409(): void
    {
        $this->criar();

        try {
            $this->criar(['documento' => '11144477735']);
            $this->fail('esperava DuplicateResourceException');
        } catch (DuplicateResourceException $e) {
            $this->assertSame(409, $e->getCode());
            $this->assertSame('email', $e->details()['campo']);
        }

        $this->assertSame(1, $this->total());
    }

    public function testDocumentoRepetidoResponde409(): void
    {
        $this->criar();

        try {
            $this->criar(['email' => 'bruno@exemplo.com']);
            $this->fail('esperava DuplicateResourceException');
        } catch (DuplicateResourceException $e) {
            $this->assertSame('documento', $e->details()['campo']);
        }

        $this->assertSame(1, $this->total());
    }

    public function testDocumentoMascaradoColideComOMesmoDocumentoSemMascara(): void
    {
        $this->criar(['documento' => '52998224725']);

        try {
            $this->criar(['email' => 'bruno@exemplo.com', 'documento' => '529.982.247-25']);
            $this->fail('esperava DuplicateResourceException');
        } catch (DuplicateResourceException $e) {
            $this->assertSame('documento', $e->details()['campo']);
        }
    }

    public function testRetryComMesmaChaveDevolveOMesmoCliente(): void
    {
        $primeira = $this->criar([], 'chave-abc');
        $segunda = $this->criar([], 'chave-abc');

        $this->assertFalse($primeira['replay']);
        $this->assertTrue($segunda['replay']);
        $this->assertSame($primeira['recurso']->id, $segunda['recurso']->id);
        $this->assertSame(1, $this->total());
    }

    public function testRetryComDocumentoMascaradoAindaEhOMesmoPayload(): void
    {
        $this->criar(['documento' => '52998224725'], 'chave-abc');
        $segunda = $this->criar(['documento' => '529.982.247-25'], 'chave-abc');

        $this->assertTrue($segunda['replay']);
        $this->assertSame(1, $this->total());
    }

    public function testMesmaChaveComOutroPayloadResponde409(): void
    {
        $this->criar([], 'chave-abc');

        $this->expectException(IdempotencyConflictException::class);

        $this->criar(['nome' => 'Outro Nome'], 'chave-abc');
    }

    public function testSemChaveNaoHaReplay(): void
    {
        $resultado = $this->criar();

        $this->assertFalse($resultado['replay']);
    }

    public function testBuscarDevolveOClienteEDaNotFoundQuandoNaoExiste(): void
    {
        $criado = $this->criar()['recurso'];

        $this->assertSame($criado->id, $this->servico->buscar($criado->id)->id);

        $this->expectException(ResourceNotFoundException::class);
        $this->servico->buscar(9999);
    }
}
