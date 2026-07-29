<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\DTO\PaginacaoQuery;
use App\Http\Pagination;
use App\Resources\AuditoriaResource;
use App\Services\PropostaService;
use CodeIgniter\HTTP\ResponseInterface;

final class PropostaAuditoriaController extends BaseApiController
{
    private PropostaService $servico;

    public function initController($request, $response, $logger): void
    {
        parent::initController($request, $response, $logger);

        $this->servico = service('propostaService');
    }

    public function index(string $propostaId): ResponseInterface
    {
        $paginacao = PaginacaoQuery::fromArray($this->request->getGet());
        $resultado = $this->servico->listarAuditoria((int) $propostaId, $paginacao);

        return $this->responder->paginated(
            AuditoriaResource::collection($resultado['items']),
            new Pagination($paginacao->page, $paginacao->perPage, $resultado['total']),
        );
    }
}
