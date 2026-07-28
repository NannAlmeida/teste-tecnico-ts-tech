<?php

declare(strict_types=1);

use App\Entities\Cliente;
use App\Entities\Proposta;
use App\Entities\PropostaAuditoria;
use App\Enums\AuditoriaEvento;
use App\Enums\PropostaOrigem;
use App\Enums\PropostaStatus;
use CodeIgniter\Entity\Exceptions\CastException;
use CodeIgniter\I18n\Time;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class EntityCastTest extends CIUnitTestCase
{
    /**
     * Simula linha vinda do banco: o Model hidrata via injectRawData, nao pelo
     * construtor, entao os casts de set nao rodam sobre valores ja persistidos.
     */
    private function proposta(array $override = []): Proposta
    {
        return (new Proposta())->injectRawData($override + [
            'id' => '7',
            'cliente_id' => '3',
            'produto' => 'Plano Ouro',
            'valor_mensal' => '1250.00',
            'status' => 'SUBMITTED',
            'origem' => 'API',
            'versao' => '2',
            'created_at' => '2026-07-01 10:00:00',
            'updated_at' => '2026-07-02 11:30:00',
            'deleted_at' => null,
        ]);
    }

    public function testPropostaConverteStatusEOrigemParaEnum(): void
    {
        $proposta = $this->proposta();

        $this->assertSame(PropostaStatus::SUBMITTED, $proposta->status);
        $this->assertSame(PropostaOrigem::API, $proposta->origem);
    }

    public function testPropostaConverteIdentificadoresEVersaoParaInt(): void
    {
        $proposta = $this->proposta();

        $this->assertSame(7, $proposta->id);
        $this->assertSame(3, $proposta->cliente_id);
        $this->assertSame(2, $proposta->versao);
    }

    public function testValorMensalPermaneceStringDecimal(): void
    {
        $proposta = $this->proposta(['valor_mensal' => '1234567890.99']);

        $this->assertIsString($proposta->valor_mensal);
        $this->assertSame('1234567890.99', $proposta->valor_mensal);
    }

    public function testStatusVoltaParaStringAoPersistir(): void
    {
        $proposta = $this->proposta();
        $proposta->status = PropostaStatus::APPROVED;
        $proposta->origem = PropostaOrigem::SITE;

        $persistido = $proposta->toRawArray();

        $this->assertSame('APPROVED', $persistido['status']);
        $this->assertSame('SITE', $persistido['origem']);
    }

    public function testStatusForaDoEnumEhRejeitado(): void
    {
        $this->expectException(CastException::class);

        $proposta = $this->proposta(['status' => 'PENDENTE']);
        $proposta->status;
    }

    public function testTimestampsViramTime(): void
    {
        $proposta = $this->proposta();

        $this->assertInstanceOf(Time::class, $proposta->created_at);
        $this->assertSame('2026-07-01 10:00:00', $proposta->created_at->toDateTimeString());
        $this->assertNull($proposta->deleted_at);
    }

    public function testPropostaSabeSeEstaEncerradaOuExcluida(): void
    {
        $this->assertFalse($this->proposta()->isFinal());
        $this->assertTrue($this->proposta(['status' => 'CANCELED'])->isFinal());

        $this->assertFalse($this->proposta()->isDeleted());
        $this->assertTrue($this->proposta(['deleted_at' => '2026-07-03 08:00:00'])->isDeleted());
    }

    public function testAuditoriaVindaDoBancoDecodificaPayload(): void
    {
        $auditoria = (new PropostaAuditoria())->injectRawData([
            'id' => '55',
            'proposta_id' => '7',
            'actor' => 'user:123',
            'evento' => 'STATUS_CHANGED',
            'payload' => '{"de":"DRAFT","para":"SUBMITTED"}',
            'created_at' => '2026-07-01 11:00:00',
        ]);

        $this->assertSame(AuditoriaEvento::STATUS_CHANGED, $auditoria->evento);
        $this->assertSame(['de' => 'DRAFT', 'para' => 'SUBMITTED'], $auditoria->payload);
        $this->assertSame(55, $auditoria->id);
    }

    public function testAuditoriaCriadaNaAplicacaoCodificaPayloadUmaVezSo(): void
    {
        $auditoria = new PropostaAuditoria();
        $auditoria->payload = ['de' => 'DRAFT', 'para' => 'SUBMITTED'];

        $this->assertSame('{"de":"DRAFT","para":"SUBMITTED"}', $auditoria->toRawArray()['payload']);
        $this->assertSame(['de' => 'DRAFT', 'para' => 'SUBMITTED'], $auditoria->payload);
    }

    public function testAuditoriaNaoEscapaAcentoAoCodificar(): void
    {
        $auditoria = new PropostaAuditoria();
        $auditoria->payload = ['produto' => ['de' => 'Consórcio Imóvel', 'para' => 'Seguro Residencial']];

        $this->assertStringNotContainsString('\\u', $auditoria->toRawArray()['payload']);
        $this->assertStringContainsString('Consórcio Imóvel', $auditoria->toRawArray()['payload']);
    }

    public function testClienteConverteIdParaInt(): void
    {
        $cliente = (new Cliente())->injectRawData([
            'id' => '10',
            'nome' => 'Ana',
            'documento' => '52998224725',
        ]);

        $this->assertSame(10, $cliente->id);
        $this->assertSame('52998224725', $cliente->documento);
    }
}
