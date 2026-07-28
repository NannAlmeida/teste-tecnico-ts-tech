<?php

declare(strict_types=1);

use App\Entities\Cliente;
use App\Entities\Proposta;
use App\Entities\PropostaAuditoria;
use App\Enums\AuditoriaEvento;
use App\Enums\PropostaOrigem;
use App\Enums\PropostaStatus;
use App\Models\ClienteModel;
use App\Models\PropostaAuditoriaModel;
use App\Models\PropostaModel;
use CodeIgniter\I18n\Time;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class ModelHydrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = true;
    protected $refresh = true;
    protected $namespace = 'App';

    private ClienteModel $clientes;
    private PropostaModel $propostas;
    private PropostaAuditoriaModel $auditorias;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clientes = new ClienteModel();
        $this->propostas = new PropostaModel();
        $this->auditorias = new PropostaAuditoriaModel();
    }

    private function novoClienteId(): int
    {
        $cliente = new Cliente();
        $cliente->fill([
            'nome' => 'Ana Carolina Souza',
            'email' => uniqid('ana', true) . '@exemplo.com',
            'documento' => (string) random_int(10000000000, 99999999999),
            'documento_tipo' => 'CPF',
        ]);

        return (int) $this->clientes->insert($cliente, true);
    }

    private function novaPropostaId(array $override = []): int
    {
        $proposta = new Proposta();
        $proposta->fill($override + [
            'cliente_id' => $this->novoClienteId(),
            'produto' => 'Plano Ouro',
            'valor_mensal' => '1250.00',
            'status' => PropostaStatus::DRAFT,
            'origem' => PropostaOrigem::API,
            'versao' => 1,
        ]);

        return (int) $this->propostas->insert($proposta, true);
    }

    public function testFindDevolveEntityComCastsAplicados(): void
    {
        $proposta = $this->propostas->find($this->novaPropostaId());

        $this->assertInstanceOf(Proposta::class, $proposta);
        $this->assertSame(PropostaStatus::DRAFT, $proposta->status);
        $this->assertSame(PropostaOrigem::API, $proposta->origem);
        $this->assertSame(1, $proposta->versao);
        $this->assertIsString($proposta->valor_mensal);
        $this->assertSame('1250.00', $proposta->valor_mensal);
    }

    public function testEnumEnviadoComoObjetoChegaAoBancoComoString(): void
    {
        $id = $this->novaPropostaId(['status' => PropostaStatus::SUBMITTED, 'origem' => PropostaOrigem::SITE]);

        $this->seeInDatabase('propostas', ['id' => $id, 'status' => 'SUBMITTED', 'origem' => 'SITE']);
        $this->assertSame(PropostaStatus::SUBMITTED, $this->propostas->find($id)->status);
    }

    public function testModelPreencheTimestampsNaInsercao(): void
    {
        $proposta = $this->propostas->find($this->novaPropostaId());

        $this->assertInstanceOf(Time::class, $proposta->created_at);
        $this->assertInstanceOf(Time::class, $proposta->updated_at);
        $this->assertNull($proposta->deleted_at);
    }

    public function testAuditoriaGravaSemUpdatedAt(): void
    {
        $auditoria = new PropostaAuditoria();
        $auditoria->fill([
            'proposta_id' => $this->novaPropostaId(),
            'actor' => 'user:123',
            'evento' => AuditoriaEvento::STATUS_CHANGED,
            'payload' => ['de' => 'DRAFT', 'para' => 'SUBMITTED'],
        ]);

        $id = (int) $this->auditorias->insert($auditoria, true);
        $gravada = $this->auditorias->find($id);

        $this->assertInstanceOf(Time::class, $gravada->created_at);
        $this->assertSame(AuditoriaEvento::STATUS_CHANGED, $gravada->evento);
    }

    public function testPayloadSobreviveAIdaEVoltaDoBanco(): void
    {
        $payload = [
            'produto' => ['de' => 'Consórcio Imóvel', 'para' => 'Seguro Residencial'],
            'valor_mensal' => ['de' => '199.90', 'para' => '349.90'],
        ];

        $auditoria = new PropostaAuditoria();
        $auditoria->fill([
            'proposta_id' => $this->novaPropostaId(),
            'actor' => 'system',
            'evento' => AuditoriaEvento::UPDATED_FIELDS,
            'payload' => $payload,
        ]);

        $id = (int) $this->auditorias->insert($auditoria, true);

        $this->assertSame($payload, $this->auditorias->find($id)->payload);
    }

    public function testSaveDeEntityAlteradaPersisteONovoStatus(): void
    {
        $id = $this->novaPropostaId();

        $proposta = $this->propostas->find($id);
        $proposta->status = PropostaStatus::SUBMITTED;
        $this->propostas->save($proposta);

        $this->seeInDatabase('propostas', ['id' => $id, 'status' => 'SUBMITTED']);
    }

    public function testSoftDeleteEscondeAPropostaSemApagarALinha(): void
    {
        $id = $this->novaPropostaId();

        $this->propostas->delete($id);

        $this->assertNull($this->propostas->find($id));
        $this->assertNotNull($this->propostas->withDeleted()->find($id));
        $this->assertInstanceOf(Time::class, $this->propostas->withDeleted()->find($id)->deleted_at);
        $this->assertSame(1, $this->db->table('propostas')->where('id', $id)->countAllResults());
    }

    public function testCampoForaDoAllowedFieldsNaoEhGravado(): void
    {
        $id = $this->novaPropostaId();

        $this->propostas->update($id, ['produto' => 'Plano Platina', 'deleted_at' => '2020-01-01 00:00:00']);

        $proposta = $this->propostas->find($id);

        $this->assertSame('Plano Platina', $proposta->produto);
        $this->assertNull($proposta->deleted_at);
    }
}
