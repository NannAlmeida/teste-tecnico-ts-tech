<?php

declare(strict_types=1);

namespace App\Http;

use App\Exceptions\ApiException;
use CodeIgniter\HTTP\ResponseInterface;

final class ApiResponder
{
    public function __construct(private readonly ResponseInterface $response)
    {
    }

    public function success(mixed $data, int $status = 200): ResponseInterface
    {
        return $this->envelope(['data' => $data], $status);
    }

    public function created(mixed $data): ResponseInterface
    {
        return $this->success($data, 201);
    }

    public function noContent(): ResponseInterface
    {
        return $this->response->setStatusCode(204)->setBody('')->removeHeader('Content-Type');
    }

    /**
     * @param list<mixed> $items
     */
    public function paginated(array $items, Pagination $pagination): ResponseInterface
    {
        return $this->envelope(['data' => $items, 'meta' => $pagination->toArray()], 200);
    }

    public function error(ApiException $exception, string $traceId): ResponseInterface
    {
        return $this->failure(
            $exception->errorCode(),
            $traceId,
            $exception->getMessage(),
            $exception->details(),
        );
    }

    /**
     * @param array<string, mixed> $details
     */
    public function failure(ErrorCode $code, string $traceId, ?string $message = null, array $details = []): ResponseInterface
    {
        return $this->envelope([
            'error' => [
                'code' => $code->value,
                'message' => $message ?? $code->defaultMessage(),
                'details' => (object) $details,
                'trace_id' => $traceId,
            ],
        ], $code->httpStatus());
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function envelope(array $payload, int $status): ResponseInterface
    {
        return $this->response->setStatusCode($status)->setJSON($payload);
    }
}
