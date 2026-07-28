<?php

declare(strict_types=1);

use App\Entities\Cliente;
use App\Exceptions\DuplicateResourceException;
use App\Exceptions\IdempotencyConflictException;
use App\Models\ClienteModel;
use App\Repositories\ClienteRepository;
use App\Repositories\IdempotentRepository;
use App\Services\IdempotencyService;
use App\Services\TransactionRunner;
use CodeIgniter\Entity\Entity;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class IdempotencyServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = true;
    protected $refresh = true;
    protected $namespace = 'App';

    private ClienteRepository $clientes;
    private IdempotencyService $idempotencia;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clientes = new ClienteRepository(new ClienteModel());
        $this->idempotencia = new IdempotencyService(new TransactionRunner($this->db));
    }

    /**
     * @return array{0: array<string, string>, 1: callable(): Cliente}
     */
    private function criacao(array $override = []): array
    {
        $payload = $override + [
            'nome' => 'Ana Carolina Souza',
            'email' => 'ana@exemplo.com',
            'documento' => '52998224725',
            'documento_tipo' => 'CPF',
        ];

        $criar = function () use ($payload): Cliente {
            $cliente = new Cliente();
            $cliente->fill($payload + [
                'idempotency_key' => $this->chaveAtual,
                'request_hash' => IdempotencyService::hash($payload),
            ]);

            return $this->clientes->insert($cliente);
        };

        return [$payload, $criar];
    }

    private ?string $chaveAtual = null;

    private function executar(?string $chave, array $override = []): array
    {
        $this->chaveAtual = $chave;
        [$payload, $criar] = $this->criacao($override);

        return $this->idempotencia->ensureOnce(
            $this->clientes,
            $chave,
            IdempotencyService::hash($payload),
            $criar,
        );
    }

    private function totalDeClientes(): int
    {
        return $this->db->table('clientes')->countAllResults();
    }

    public function testPrimeiraChamadaCriaENaoEhReplay(): void
    {
        $resultado = $this->executar('chave-abc');

        $this->assertFalse($resultado['replay']);
        $this->assertSame('ana@exemplo.com', $resultado['recurso']->email);
        $this->assertSame(1, $this->totalDeClientes());
    }

    public function testSegundaChamadaComMesmaChaveEPayloadDevolveOMesmoRecurso(): void
    {
        $primeira = $this->executar('chave-abc');
        $segunda = $this->executar('chave-abc');

        $this->assertFalse($primeira['replay']);
        $this->assertTrue($segunda['replay']);
        $this->assertSame($primeira['recurso']->id, $segunda['recurso']->id);
        $this->assertSame(1, $this->totalDeClientes());
    }

    public function testMesmaChaveComPayloadDiferenteEhConflito(): void
    {
        $this->executar('chave-abc');

        try {
            $this->executar('chave-abc', ['nome' => 'Outro Nome']);
            $this->fail('esperava IdempotencyConflictException');
        } catch (IdempotencyConflictException $e) {
            $this->assertSame(409, $e->getCode());
            $this->assertSame('IDEMPOTENCY_KEY_REUSE', $e->errorCode()->value);
            $this->assertSame(['idempotency_key' => 'chave-abc'], $e->details());
        }

        $this->assertSame(1, $this->totalDeClientes());
    }

    public function testChavesDiferentesCriamRecursosDiferentes(): void
    {
        $this->executar('chave-1');
        $this->executar('chave-2', ['email' => 'bruno@exemplo.com', 'documento' => '11144477735']);

        $this->assertSame(2, $this->totalDeClientes());
    }

    public function testSemChaveExecutaSempre(): void
    {
        $primeira = $this->executar(null);
        $segunda = $this->executar(null, ['email' => 'bruno@exemplo.com', 'documento' => '11144477735']);

        $this->assertFalse($primeira['replay']);
        $this->assertFalse($segunda['replay']);
        $this->assertSame(2, $this->totalDeClientes());
    }

    public function testCorridaEntreDuasRequisicoesResultaEmReplay(): void
    {
        $real = $this->clientes;

        $repositorioCego = new class ($real) implements IdempotentRepository {
            private bool $primeiraConsulta = true;

            public function __construct(private readonly ClienteRepository $real)
            {
            }

            public function findByIdempotencyKey(string $key): ?Entity
            {
                if ($this->primeiraConsulta) {
                    $this->primeiraConsulta = false;

                    return null;
                }

                return $this->real->findByIdempotencyKey($key);
            }
        };

        $payload = ['nome' => 'Ana Carolina Souza', 'email' => 'ana@exemplo.com', 'documento' => '52998224725', 'documento_tipo' => 'CPF'];
        $hash = IdempotencyService::hash($payload);

        $vencedor = new Cliente();
        $vencedor->fill($payload + ['idempotency_key' => 'chave-abc', 'request_hash' => $hash]);
        $this->clientes->insert($vencedor);

        $resultado = $this->idempotencia->ensureOnce(
            $repositorioCego,
            'chave-abc',
            $hash,
            function () use ($payload, $hash): Cliente {
                $perdedor = new Cliente();
                $perdedor->fill($payload + ['idempotency_key' => 'chave-abc', 'request_hash' => $hash]);

                return $this->clientes->insert($perdedor);
            },
        );

        $this->assertTrue($resultado['replay']);
        $this->assertSame(1, $this->totalDeClientes());
    }

    public function testDuplicidadeDeOutroCampoNaoEhTratadaComoIdempotencia(): void
    {
        $this->executar('chave-1');

        try {
            $this->executar('chave-2');
            $this->fail('esperava DuplicateResourceException de email');
        } catch (DuplicateResourceException $e) {
            $this->assertSame('email', $e->details()['campo']);
        }

        $this->assertSame(1, $this->totalDeClientes());
    }

    public function testHashIgnoraAOrdemDosCampos(): void
    {
        $this->assertSame(
            IdempotencyService::hash(['b' => 2, 'a' => 1]),
            IdempotencyService::hash(['a' => 1, 'b' => 2]),
        );
    }

    public function testHashIgnoraAOrdemEmProfundidade(): void
    {
        $this->assertSame(
            IdempotencyService::hash(['dados' => ['z' => 1, 'y' => 2]]),
            IdempotencyService::hash(['dados' => ['y' => 2, 'z' => 1]]),
        );
    }

    public function testHashMudaComOValor(): void
    {
        $this->assertNotSame(
            IdempotencyService::hash(['valor_mensal' => '199.90']),
            IdempotencyService::hash(['valor_mensal' => '199.91']),
        );
    }
}
