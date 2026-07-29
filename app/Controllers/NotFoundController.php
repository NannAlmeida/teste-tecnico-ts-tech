<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\ErrorCode;
use App\Http\TraceId;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Estende Controller, e nao BaseApiController, porque o _remap daquele espera
 * uma action de negocio e nao um handler de rota inexistente.
 */
final class NotFoundController extends Controller
{
    public function index(): ResponseInterface
    {
        $caminho = ltrim($this->request->getPath(), '/');

        return service('apiResponder')->failure(
            ErrorCode::RESOURCE_NOT_FOUND,
            TraceId::fromRequest($this->request),
            null,
            ['rota' => strtoupper($this->request->getMethod()) . ' /' . $caminho],
        );
    }
}
