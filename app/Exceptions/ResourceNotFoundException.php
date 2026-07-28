<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Http\ErrorCode;

final class ResourceNotFoundException extends ApiException
{
    public static function of(string $resource, int|string $id): self
    {
        return new self(
            ErrorCode::RESOURCE_NOT_FOUND,
            details: ['recurso' => $resource, 'id' => $id],
        );
    }
}
