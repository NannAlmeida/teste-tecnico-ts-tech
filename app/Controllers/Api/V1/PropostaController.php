<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\DTO\CreatePropostaDTO;
use App\DTO\PropostaQuery;
use App\DTO\UpdatePropostaDTO;
use App\Entities\Proposta;
use App\Http\Pagination;
use App\Resources\PropostaResource;
use App\Services\PropostaService;
use CodeIgniter\HTTP\ResponseInterface;

final class PropostaController extends BaseApiController
{
    private PropostaService $servico;

    public function initController($request, $response, $logger): void
    {
        parent::initController($request, $response, $logger);

        $this->servico = service('propostaService');
    }

    public function create(): ResponseInterface
    {
        $resultado = $this->servico->criar(
            CreatePropostaDTO::fromArray($this->corpo()),
            $this->actor(),
            $this->idempotencyKeyObrigatoria(),
        );

        $proposta = $resultado['recurso'];

        return $this->comMarcaDeReplay(
            $this->comVersao($this->responder->created(PropostaResource::item($proposta)), $proposta)
                ->setHeader('Location', '/api/v1/propostas/' . $proposta->id),
            $resultado['replay'],
        );
    }

    public function index(): ResponseInterface
    {
        $query = PropostaQuery::fromArray($this->request->getGet());
        $resultado = $this->servico->listar($query);

        return $this->responder->paginated(
            PropostaResource::collection($resultado['items']),
            new Pagination($query->page, $query->perPage, $resultado['total']),
        );
    }

    public function show(string $id): ResponseInterface
    {
        $proposta = $this->servico->buscar((int) $id);

        return $this->comVersao($this->responder->success(PropostaResource::item($proposta)), $proposta);
    }

    public function update(string $id): ResponseInterface
    {
        $proposta = $this->servico->alterar(
            (int) $id,
            UpdatePropostaDTO::fromArray($this->corpoComVersao()),
            $this->actor(),
        );

        return $this->comVersao($this->responder->success(PropostaResource::item($proposta)), $proposta);
    }

    public function submit(string $id): ResponseInterface
    {
        $chave = $this->idempotencyKeyObrigatoria();
        $versao = $this->versaoObrigatoria();

        return $this->responderTransicao($this->servico->submeter((int) $id, $versao, $this->actor(), $chave));
    }

    public function approve(string $id): ResponseInterface
    {
        return $this->responderTransicao(
            $this->servico->aprovar((int) $id, $this->versaoObrigatoria(), $this->actor(), $this->idempotencyKey()),
        );
    }

    public function reject(string $id): ResponseInterface
    {
        return $this->responderTransicao(
            $this->servico->rejeitar((int) $id, $this->versaoObrigatoria(), $this->actor(), $this->idempotencyKey()),
        );
    }

    public function cancel(string $id): ResponseInterface
    {
        return $this->responderTransicao(
            $this->servico->cancelar((int) $id, $this->versaoObrigatoria(), $this->actor(), $this->idempotencyKey()),
        );
    }

    public function delete(string $id): ResponseInterface
    {
        $this->servico->excluir((int) $id, $this->versaoObrigatoria(), $this->actor());

        return $this->responder->noContent();
    }

    /**
     * @param array{recurso: Proposta, replay: bool} $resultado
     */
    private function responderTransicao(array $resultado): ResponseInterface
    {
        $proposta = $resultado['recurso'];

        return $this->comMarcaDeReplay(
            $this->comVersao($this->responder->success(PropostaResource::item($proposta)), $proposta),
            $resultado['replay'],
        );
    }

    private function comVersao(ResponseInterface $resposta, Proposta $proposta): ResponseInterface
    {
        return $resposta->setHeader('ETag', '"' . $proposta->versao . '"');
    }
}
