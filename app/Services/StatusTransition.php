<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PropostaStatus;
use App\Exceptions\ImmutableResourceException;
use App\Exceptions\InvalidStatusTransitionException;

final class StatusTransition
{
    public static function assert(PropostaStatus $current, PropostaStatus $target): void
    {
        if ($current->isFinal()) {
            throw ImmutableResourceException::inFinalStatus('proposta', $current->value);
        }

        if (! $current->canTransitionTo($target)) {
            throw InvalidStatusTransitionException::from(
                $current->value,
                $target->value,
                $current->allowedTransitionValues(),
            );
        }
    }

    public static function assertMutable(PropostaStatus $current): void
    {
        if ($current->isFinal()) {
            throw ImmutableResourceException::inFinalStatus('proposta', $current->value);
        }
    }
}
