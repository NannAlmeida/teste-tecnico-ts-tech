<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Http\ErrorCode;

final class InvalidStatusTransitionException extends ApiException
{
    /**
     * @param list<string> $allowed
     */
    public static function from(string $current, string $target, array $allowed): self
    {
        return new self(
            ErrorCode::INVALID_STATUS_TRANSITION,
            details: [
                'de' => $current,
                'para' => $target,
                'transicoes_permitidas' => $allowed,
            ],
        );
    }
}
