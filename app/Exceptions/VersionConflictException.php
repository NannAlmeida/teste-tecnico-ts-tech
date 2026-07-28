<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Http\ErrorCode;

final class VersionConflictException extends ApiException
{
    public static function between(int $sent, int $current): self
    {
        return new self(
            ErrorCode::VERSION_CONFLICT,
            details: ['versao_enviada' => $sent, 'versao_atual' => $current],
        );
    }
}
