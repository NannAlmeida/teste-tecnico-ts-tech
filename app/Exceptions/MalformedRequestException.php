<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Http\ErrorCode;

final class MalformedRequestException extends ApiException
{
    public static function invalidJson(): self
    {
        return new self(ErrorCode::MALFORMED_JSON);
    }

    public static function missingIdempotencyKey(): self
    {
        return new self(
            ErrorCode::MISSING_IDEMPOTENCY_KEY,
            details: ['header' => 'Idempotency-Key'],
        );
    }

    public static function missingVersion(): self
    {
        return new self(
            ErrorCode::MISSING_VERSION,
            details: ['aceita' => ['campo versao no corpo', 'header If-Match']],
        );
    }
}
