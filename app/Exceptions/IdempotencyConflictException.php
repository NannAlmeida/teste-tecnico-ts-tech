<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Http\ErrorCode;

final class IdempotencyConflictException extends ApiException
{
    public static function keyReuse(string $key): self
    {
        return new self(
            ErrorCode::IDEMPOTENCY_KEY_REUSE,
            details: ['idempotency_key' => $key],
        );
    }

    public static function inProgress(string $key): self
    {
        return new self(
            ErrorCode::IDEMPOTENCY_IN_PROGRESS,
            details: ['idempotency_key' => $key],
        );
    }
}
