<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\ApiResponder;
use App\Http\ErrorCode;
use App\Http\TraceId;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;
use Throwable;

abstract class BaseApiController extends Controller
{
    protected ApiResponder $responder;

    private ?string $traceId = null;

    public function initController(
        RequestInterface $request,
        ResponseInterface $response,
        LoggerInterface $logger,
    ): void {
        parent::initController($request, $response, $logger);

        $this->responder = service('apiResponder');
    }

    /**
     * Ponto unico de traducao entre excecao e resposta HTTP para toda action da API.
     */
    public function _remap(string $method, mixed ...$params): ResponseInterface
    {
        try {
            return $this->{$method}(...$params);
        } catch (ApiException $exception) {
            return $this->responder->error($exception, $this->traceId());
        } catch (Throwable $exception) {
            log_message('critical', '{class}: {message} em {exFile}:{exLine}' . "\n{trace}", [
                'class' => $exception::class,
                'message' => $exception->getMessage(),
                'exFile' => clean_path($exception->getFile()),
                'exLine' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ]);

            return $this->responder->failure(ErrorCode::INTERNAL_ERROR, $this->traceId());
        }
    }

    protected function traceId(): string
    {
        return $this->traceId ??= TraceId::fromRequest($this->request);
    }
}
