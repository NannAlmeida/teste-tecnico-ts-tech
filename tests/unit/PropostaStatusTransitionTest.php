<?php

declare(strict_types=1);

use App\Enums\PropostaStatus;
use App\Exceptions\ApiException;
use App\Exceptions\ImmutableResourceException;
use App\Exceptions\InvalidStatusTransitionException;
use App\Http\ErrorCode;
use App\Services\StatusTransition;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class PropostaStatusTransitionTest extends CIUnitTestCase
{
    /**
     * A matriz e escrita a mao de proposito: se derivasse do proprio enum,
     * o teste concordaria com qualquer regra que a implementacao tivesse.
     */
    public static function matrizDeTransicoes(): array
    {
        return [
            'DRAFT -> SUBMITTED' => ['DRAFT', 'SUBMITTED', null],
            'DRAFT -> CANCELED' => ['DRAFT', 'CANCELED', null],
            'DRAFT -> APPROVED' => ['DRAFT', 'APPROVED', InvalidStatusTransitionException::class],
            'DRAFT -> REJECTED' => ['DRAFT', 'REJECTED', InvalidStatusTransitionException::class],
            'DRAFT -> DRAFT' => ['DRAFT', 'DRAFT', InvalidStatusTransitionException::class],

            'SUBMITTED -> APPROVED' => ['SUBMITTED', 'APPROVED', null],
            'SUBMITTED -> REJECTED' => ['SUBMITTED', 'REJECTED', null],
            'SUBMITTED -> CANCELED' => ['SUBMITTED', 'CANCELED', null],
            'SUBMITTED -> DRAFT' => ['SUBMITTED', 'DRAFT', InvalidStatusTransitionException::class],
            'SUBMITTED -> SUBMITTED' => ['SUBMITTED', 'SUBMITTED', InvalidStatusTransitionException::class],

            'APPROVED -> DRAFT' => ['APPROVED', 'DRAFT', ImmutableResourceException::class],
            'APPROVED -> SUBMITTED' => ['APPROVED', 'SUBMITTED', ImmutableResourceException::class],
            'APPROVED -> APPROVED' => ['APPROVED', 'APPROVED', ImmutableResourceException::class],
            'APPROVED -> REJECTED' => ['APPROVED', 'REJECTED', ImmutableResourceException::class],
            'APPROVED -> CANCELED' => ['APPROVED', 'CANCELED', ImmutableResourceException::class],

            'REJECTED -> DRAFT' => ['REJECTED', 'DRAFT', ImmutableResourceException::class],
            'REJECTED -> SUBMITTED' => ['REJECTED', 'SUBMITTED', ImmutableResourceException::class],
            'REJECTED -> APPROVED' => ['REJECTED', 'APPROVED', ImmutableResourceException::class],
            'REJECTED -> REJECTED' => ['REJECTED', 'REJECTED', ImmutableResourceException::class],
            'REJECTED -> CANCELED' => ['REJECTED', 'CANCELED', ImmutableResourceException::class],

            'CANCELED -> DRAFT' => ['CANCELED', 'DRAFT', ImmutableResourceException::class],
            'CANCELED -> SUBMITTED' => ['CANCELED', 'SUBMITTED', ImmutableResourceException::class],
            'CANCELED -> APPROVED' => ['CANCELED', 'APPROVED', ImmutableResourceException::class],
            'CANCELED -> REJECTED' => ['CANCELED', 'REJECTED', ImmutableResourceException::class],
            'CANCELED -> CANCELED' => ['CANCELED', 'CANCELED', ImmutableResourceException::class],
        ];
    }

    /**
     * @dataProvider matrizDeTransicoes
     */
    public function testAssertCobreAMatrizInteira(string $de, string $para, ?string $excecaoEsperada): void
    {
        $origem = PropostaStatus::from($de);
        $destino = PropostaStatus::from($para);

        if ($excecaoEsperada === null) {
            StatusTransition::assert($origem, $destino);
            $this->assertTrue($origem->canTransitionTo($destino));

            return;
        }

        $this->expectException($excecaoEsperada);
        StatusTransition::assert($origem, $destino);
    }

    public function testApenasDraftESubmittedAceitamMudanca(): void
    {
        $finais = array_values(array_filter(
            PropostaStatus::cases(),
            static fn (PropostaStatus $status): bool => $status->isFinal(),
        ));

        $this->assertSame(
            [PropostaStatus::APPROVED, PropostaStatus::REJECTED, PropostaStatus::CANCELED],
            $finais,
        );
    }

    public function testEstadoFinalNaoOfereceSaida(): void
    {
        foreach (PropostaStatus::cases() as $status) {
            if ($status->isFinal()) {
                $this->assertSame([], $status->allowedTransitions(), $status->value);
            } else {
                $this->assertNotSame([], $status->allowedTransitions(), $status->value);
            }
        }
    }

    public function testAssertMutableBarraSomenteEstadoFinal(): void
    {
        StatusTransition::assertMutable(PropostaStatus::DRAFT);
        StatusTransition::assertMutable(PropostaStatus::SUBMITTED);

        $this->expectException(ImmutableResourceException::class);
        StatusTransition::assertMutable(PropostaStatus::APPROVED);
    }

    public function testErroDeTransicaoDizAoClienteOQueEraPermitido(): void
    {
        try {
            StatusTransition::assert(PropostaStatus::DRAFT, PropostaStatus::APPROVED);
            $this->fail('esperava InvalidStatusTransitionException');
        } catch (InvalidStatusTransitionException $e) {
            $this->assertSame(ErrorCode::INVALID_STATUS_TRANSITION, $e->errorCode());
            $this->assertSame(409, $e->getCode());
            $this->assertSame([
                'de' => 'DRAFT',
                'para' => 'APPROVED',
                'transicoes_permitidas' => ['SUBMITTED', 'CANCELED'],
            ], $e->details());
        }
    }

    public function testErroDeEstadoFinalIdentificaOStatusQueTravou(): void
    {
        try {
            StatusTransition::assert(PropostaStatus::CANCELED, PropostaStatus::APPROVED);
            $this->fail('esperava ImmutableResourceException');
        } catch (ImmutableResourceException $e) {
            $this->assertSame(ErrorCode::IMMUTABLE_RESOURCE, $e->errorCode());
            $this->assertSame(409, $e->getCode());
            $this->assertSame(['recurso' => 'proposta', 'status' => 'CANCELED'], $e->details());
        }
    }

    public function testTodaViolacaoDeFluxoRespondeConflito(): void
    {
        foreach (self::matrizDeTransicoes() as $rotulo => [$de, $para, $excecaoEsperada]) {
            if ($excecaoEsperada === null) {
                continue;
            }

            try {
                StatusTransition::assert(PropostaStatus::from($de), PropostaStatus::from($para));
                $this->fail("{$rotulo} deveria falhar");
            } catch (ApiException $e) {
                $this->assertSame(409, $e->getCode(), $rotulo);
            }
        }
    }
}
