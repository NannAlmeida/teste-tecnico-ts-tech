<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Http\ErrorCode;

final class ImmutableResourceException extends ApiException
{
    public static function inFinalStatus(string $resource, string $status): self
    {
        return new self(
            ErrorCode::IMMUTABLE_RESOURCE,
            details: ['recurso' => $resource, 'status' => $status],
        );
    }
}
