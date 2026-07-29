<?php

declare(strict_types=1);

namespace App\Filters;

use App\Http\TraceId;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class ApiFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null): null
    {
        $request->setHeader(TraceId::HEADER, TraceId::fromRequest($request));

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): null
    {
        $response->setHeader(TraceId::HEADER, $request->getHeaderLine(TraceId::HEADER));

        return null;
    }
}
