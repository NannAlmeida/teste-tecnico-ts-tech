<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Http\ErrorCode;

final class ValidationException extends ApiException
{
    /**
     * @param array<string, string|list<string>> $errors
     */
    public static function withErrors(array $errors): self
    {
        return new self(
            ErrorCode::VALIDATION_ERROR,
            details: array_map(static fn (string|array $messages): array => (array) $messages, $errors),
        );
    }

    public static function onField(string $field, string $message): self
    {
        return self::withErrors([$field => $message]);
    }
}
