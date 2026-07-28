<?php

declare(strict_types=1);

namespace App\Filters;

use App\Exceptions\MalformedRequestException;
use App\Http\TraceId;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class ApiFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null): null
    {
        $request->setHeader(TraceId::HEADER, TraceId::fromRequest($request));

        $this->assertJsonBody($request);

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): null
    {
        $response->setHeader(TraceId::HEADER, $request->getHeaderLine(TraceId::HEADER));

        return null;
    }

    private function assertJsonBody(RequestInterface $request): void
    {
        if (! $request instanceof IncomingRequest) {
            return;
        }

        $body = (string) $request->getBody();

        if ($body === '') {
            return;
        }

        json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw MalformedRequestException::invalidJson();
        }
    }
}
