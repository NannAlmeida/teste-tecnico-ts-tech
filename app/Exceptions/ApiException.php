<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Http\ErrorCode;
use CodeIgniter\Exceptions\HTTPExceptionInterface;
use RuntimeException;
use Throwable;

abstract class ApiException extends RuntimeException implements HTTPExceptionInterface
{
    /**
     * @param array<string, mixed> $details Contexto estruturado devolvido ao cliente.
     */
    protected function __construct(
        private readonly ErrorCode $errorCode,
        ?string $message = null,
        private readonly array $details = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            $message ?? $errorCode->defaultMessage(),
            $errorCode->httpStatus(),
            $previous,
        );
    }

    public function errorCode(): ErrorCode
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return $this->details;
    }
}
