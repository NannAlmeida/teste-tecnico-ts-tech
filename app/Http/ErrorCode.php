<?php

declare(strict_types=1);

namespace App\Http;

enum ErrorCode: string
{
    case VALIDATION_ERROR = 'VALIDATION_ERROR';
    case MALFORMED_JSON = 'MALFORMED_JSON';
    case MISSING_IDEMPOTENCY_KEY = 'MISSING_IDEMPOTENCY_KEY';
    case MISSING_VERSION = 'MISSING_VERSION';
    case RESOURCE_NOT_FOUND = 'RESOURCE_NOT_FOUND';
    case DUPLICATE_RESOURCE = 'DUPLICATE_RESOURCE';
    case VERSION_CONFLICT = 'VERSION_CONFLICT';
    case INVALID_STATUS_TRANSITION = 'INVALID_STATUS_TRANSITION';
    case IMMUTABLE_RESOURCE = 'IMMUTABLE_RESOURCE';
    case IDEMPOTENCY_KEY_REUSE = 'IDEMPOTENCY_KEY_REUSE';
    case RATE_LIMITED = 'RATE_LIMITED';
    case INTERNAL_ERROR = 'INTERNAL_ERROR';

    public function httpStatus(): int
    {
        return match ($this) {
            self::MALFORMED_JSON,
            self::MISSING_IDEMPOTENCY_KEY => 400,

            self::RESOURCE_NOT_FOUND => 404,

            self::DUPLICATE_RESOURCE,
            self::VERSION_CONFLICT,
            self::INVALID_STATUS_TRANSITION,
            self::IMMUTABLE_RESOURCE,
            self::IDEMPOTENCY_KEY_REUSE => 409,

            self::VALIDATION_ERROR,
            self::MISSING_VERSION => 422,

            self::RATE_LIMITED => 429,

            self::INTERNAL_ERROR => 500,
        };
    }

    public function defaultMessage(): string
    {
        return match ($this) {
            self::VALIDATION_ERROR => 'Os dados enviados são inválidos.',
            self::MALFORMED_JSON => 'O corpo da requisição não é um JSON válido.',
            self::MISSING_IDEMPOTENCY_KEY => 'O header Idempotency-Key é obrigatório nesta operação.',
            self::MISSING_VERSION => 'A versão do registro é obrigatória nesta operação.',
            self::RESOURCE_NOT_FOUND => 'Recurso não encontrado.',
            self::DUPLICATE_RESOURCE => 'Já existe um registro com os dados informados.',
            self::VERSION_CONFLICT => 'O registro foi alterado por outro processo.',
            self::INVALID_STATUS_TRANSITION => 'A transição de status solicitada não é permitida.',
            self::IMMUTABLE_RESOURCE => 'O registro está em um estado final e não pode ser alterado.',
            self::IDEMPOTENCY_KEY_REUSE => 'A Idempotency-Key informada já foi usada com outro conteúdo.',
            self::RATE_LIMITED => 'Limite de requisições excedido. Tente novamente em instantes.',
            self::INTERNAL_ERROR => 'Erro interno ao processar a requisição.',
        };
    }
}
