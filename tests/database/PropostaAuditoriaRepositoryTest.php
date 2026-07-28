<?php

declare(strict_types=1);

use App\Entities\Cliente;
use App\Entities\Proposta;
use App\Entities\PropostaAuditoria;
use App\Enums\AuditoriaEvento;
use App\Enums\PropostaOrigem;
use App\Enums\PropostaStatus;
use App\Exceptions\DuplicateResourceException;
use App\Models\ClienteModel;
use App\Models\PropostaAuditoriaModel;
use App\Models\PropostaModel;
use App\Repositories\ClienteRepository;
use App\Repositories\PropostaAuditoriaRepository;
use App\Repositories\PropostaRepository;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class PropostaAuditoriaRepositoryTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = true;
    protected $refresh = true;
    protected $namespace = 'App';

    private PropostaRepository $propostas;
    private PropostaAuditoriaRepository $auditorias;

    protected function setUp(): void
    {
        parent::setUp();

        $this->propostas = new PropostaRepository(new PropostaModel());
        $this->auditorias = new PropostaAuditoriaRepository(new PropostaAuditoriaModel());
    }

    private function novaProposta(): Proposta
    {
        $cliente = new Cliente();
        $cliente->fill([
            'nome' => 'Ana Carolina Souza',
            'email' => uniqid('ana', true) . '@exemplo.com',
            'documento' => (string) random_int(10000000000, 99999999999),
            'documento_tipo' => 'CPF',
        ]);
        $cliente = (new ClienteRepository(new ClienteModel()))->insert($cliente);

        $proposta = new Proposta();
        $proposta->fill([
            'cliente_id' => $cliente->id,
            'produto' => 'Plano Ouro',
            'valor_mensal' => '1250.00',
            'status' => PropostaStatus::DRAFT,
            'origem' => PropostaOrigem::API,
            'versao' => 1,
        ]);

        return $this->propostas->insert($proposta);
    }

    private function registrar(int $propostaId, AuditoriaEvento $evento, array $payload, array $override = []): PropostaAuditoria
    {
        $auditoria = new PropostaAuditoria();
        $auditoria->fill($override + [
            'proposta_id' => $propostaId,
            'actor' => 'user:123',
            'evento' => $evento,
            'payload' => $payload,
        ]);

        return $this->auditorias->insert($auditoria);
    }

    public function testGravaERelePreservandoEventoEPayload(): void
    {
        $proposta = $this->novaProposta();

        $auditoria = $this->registrar($proposta->id, AuditoriaEvento::STATUS_CHANGED, ['de' => 'DRAFT', 'para' => 'SUBMITTED']);

        $this->assertSame(AuditoriaEvento::STATUS_CHANGED, $auditoria->evento);
        $this->assertSame(['de' => 'DRAFT', 'para' => 'SUBMITTED'], $auditoria->payload);
        $this->assertSame('user:123', $auditoria->actor);
        $this->assertSame($proposta->id, $auditoria->proposta_id);
    }

    public function testTrilhaVoltaEmOrdemCronologica(): void
    {
        $proposta = $this->novaProposta();

        $this->registrar($proposta->id, AuditoriaEvento::CREATED, ['produto' => 'Plano Ouro']);
        $this->registrar($proposta->id, AuditoriaEvento::STATUS_CHANGED, ['de' => 'DRAFT', 'para' => 'SUBMITTED']);
        $this->registrar($proposta->id, AuditoriaEvento::STATUS_CHANGED, ['de' => 'SUBMITTED', 'para' => 'APPROVED']);

        $resultado = $this->auditorias->paginateByProposta($proposta->id, 1, 20);

        $this->assertSame(3, $resultado['total']);
        $this->assertSame(
            [AuditoriaEvento::CREATED, AuditoriaEvento::STATUS_CHANGED, AuditoriaEvento::STATUS_CHANGED],
            array_map(static fn (PropostaAuditoria $a): AuditoriaEvento => $a->evento, $resultado['items']),
        );
        $this->assertSame(['de' => 'DRAFT', 'para' => 'SUBMITTED'], $resultado['items'][1]->payload);
    }

    public function testTrilhaNaoMisturaPropostas(): void
    {
        $uma = $this->novaProposta();
        $outra = $this->novaProposta();

        $this->registrar($uma->id, AuditoriaEvento::CREATED, ['x' => 1]);
        $this->registrar($uma->id, AuditoriaEvento::UPDATED_FIELDS, ['x' => 2]);
        $this->registrar($outra->id, AuditoriaEvento::CREATED, ['y' => 1]);

        $this->assertSame(2, $this->auditorias->paginateByProposta($uma->id, 1, 20)['total']);
        $this->assertSame(1, $this->auditorias->paginateByProposta($outra->id, 1, 20)['total']);
    }

    public function testPaginaLimitaOsItensMasNaoOTotal(): void
    {
        $proposta = $this->novaProposta();

        foreach (range(1, 7) as $i) {
            $this->registrar($proposta->id, AuditoriaEvento::UPDATED_FIELDS, ['passo' => $i]);
        }

        $primeira = $this->auditorias->paginateByProposta($proposta->id, 1, 5);
        $segunda = $this->auditorias->paginateByProposta($proposta->id, 2, 5);

        $this->assertSame(7, $primeira['total']);
        $this->assertSame(7, $segunda['total']);
        $this->assertCount(5, $primeira['items']);
        $this->assertCount(2, $segunda['items']);
        $this->assertSame(['passo' => 6], $segunda['items'][0]->payload);
    }

    public function testPropostaSemTrilhaDevolveListaVazia(): void
    {
        $proposta = $this->novaProposta();

        $resultado = $this->auditorias->paginateByProposta($proposta->id, 1, 20);

        $this->assertSame(0, $resultado['total']);
        $this->assertSame([], $resultado['items']);
    }

    public function testTrilhaSobreviveAExclusaoLogicaDaProposta(): void
    {
        $proposta = $this->novaProposta();
        $this->registrar($proposta->id, AuditoriaEvento::CREATED, ['produto' => 'Plano Ouro']);
        $this->registrar($proposta->id, AuditoriaEvento::DELETED_LOGICAL, ['status_no_momento' => 'DRAFT']);

        $this->propostas->softDelete($proposta->id, 1);

        $this->assertNull($this->propostas->find($proposta->id));
        $this->assertSame(2, $this->auditorias->paginateByProposta($proposta->id, 1, 20)['total']);
    }

    public function testMesmaChaveDeIdempotenciaNaoRegistraDuasVezes(): void
    {
        $proposta = $this->novaProposta();

        $this->registrar($proposta->id, AuditoriaEvento::STATUS_CHANGED, ['de' => 'DRAFT', 'para' => 'SUBMITTED'], ['idempotency_key' => 'submit-xyz']);

        try {
            $this->registrar($proposta->id, AuditoriaEvento::STATUS_CHANGED, ['de' => 'DRAFT', 'para' => 'SUBMITTED'], ['idempotency_key' => 'submit-xyz']);
            $this->fail('esperava DuplicateResourceException');
        } catch (DuplicateResourceException $e) {
            $this->assertSame(409, $e->getCode());
            $this->assertSame(['campo' => 'idempotency_key', 'valor' => 'submit-xyz'], $e->details());
        }

        $this->assertSame(1, $this->auditorias->paginateByProposta($proposta->id, 1, 20)['total']);
    }

    public function testChaveDeIdempotenciaLocalizaOEventoJaRegistrado(): void
    {
        $proposta = $this->novaProposta();
        $this->registrar($proposta->id, AuditoriaEvento::STATUS_CHANGED, ['de' => 'DRAFT', 'para' => 'SUBMITTED'], ['idempotency_key' => 'submit-xyz']);

        $encontrada = $this->auditorias->findByIdempotencyKey('submit-xyz');

        $this->assertNotNull($encontrada);
        $this->assertSame($proposta->id, $encontrada->proposta_id);
        $this->assertNull($this->auditorias->findByIdempotencyKey('nao-existe'));
    }

    public function testTrilhaCustaDuasQueriesIndependenteDoTamanho(): void
    {
        $proposta = $this->novaProposta();

        foreach (range(1, 25) as $i) {
            $this->registrar($proposta->id, AuditoriaEvento::UPDATED_FIELDS, ['passo' => $i]);
        }

        $queries = [];
        Events::on('DBQuery', static function ($query) use (&$queries): void {
            $queries[] = trim(preg_replace('/\s+/', ' ', (string) $query));
        });

        $resultado = $this->auditorias->paginateByProposta($proposta->id, 1, 25);

        $selects = array_values(array_filter(
            $queries,
            static fn (string $sql): bool => str_starts_with(strtoupper($sql), 'SELECT'),
        ));

        $this->assertCount(25, $resultado['items']);
        $this->assertCount(2, $selects, "esperava COUNT + SELECT, veio:\n" . implode("\n", $selects));
    }
}
