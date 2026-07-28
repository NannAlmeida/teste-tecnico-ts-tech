<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\DTO\CreateClienteDTO;
use App\Resources\ClienteResource;
use App\Services\ClienteService;
use CodeIgniter\HTTP\ResponseInterface;

final class ClienteController extends BaseApiController
{
    private ClienteService $servico;

    public function initController($request, $response, $logger): void
    {
        parent::initController($request, $response, $logger);

        $this->servico = service('clienteService');
    }

    public function create(): ResponseInterface
    {
        $resultado = $this->servico->criar(
            CreateClienteDTO::fromArray($this->corpo()),
            $this->idempotencyKeyObrigatoria(),
        );

        $cliente = $resultado['recurso'];

        return $this->comMarcaDeReplay(
            $this->responder->created(ClienteResource::item($cliente))
                ->setHeader('Location', '/api/v1/clientes/' . $cliente->id),
            $resultado['replay'],
        );
    }
}
