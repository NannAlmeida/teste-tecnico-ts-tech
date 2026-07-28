<?php

declare(strict_types=1);

namespace App\Http;

use App\Exceptions\ApiException;
use CodeIgniter\Debug\ExceptionHandlerInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

final class ApiExceptionHandler implements ExceptionHandlerInterface
{
    public function handle(
        Throwable $exception,
        RequestInterface $request,
        ResponseInterface $response,
        int $statusCode,
        int $exitCode,
    ): void {
        $responder = new ApiResponder($response);
        $traceId = TraceId::fromRequest($request);

        $rendered = $exception instanceof ApiException
            ? $responder->error($exception, $traceId)
            : $responder->failure($this->errorCodeFor($statusCode), $traceId);

        $rendered->send();

        if (ENVIRONMENT !== 'testing') {
            exit($exitCode);
        }
    }

    private function errorCodeFor(int $statusCode): ErrorCode
    {
        return $statusCode === 404 ? ErrorCode::RESOURCE_NOT_FOUND : ErrorCode::INTERNAL_ERROR;
    }
}
