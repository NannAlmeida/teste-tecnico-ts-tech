<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Http\ErrorCode;

final class DuplicateResourceException extends ApiException
{
    public static function onField(string $field, string $value): self
    {
        return new self(
            ErrorCode::DUPLICATE_RESOURCE,
            details: ['campo' => $field, 'valor' => $value],
        );
    }
}
