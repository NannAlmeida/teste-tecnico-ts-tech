<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Exceptions\MalformedRequestException;
use App\Exceptions\ValidationException;
use App\Http\ApiResponder;
use App\Http\ErrorCode;
use App\Http\TraceId;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;
use Throwable;

abstract class BaseApiController extends Controller
{
    protected const HEADER_IDEMPOTENCY_KEY = 'Idempotency-Key';
    protected const HEADER_IDEMPOTENCY_REPLAYED = 'Idempotency-Replayed';
    protected const HEADER_ACTOR = 'X-Actor';
    protected const HEADER_IF_MATCH = 'If-Match';

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

    /**
     * A checagem vive aqui, e nao no ApiFilter, para acontecer dentro do catch
     * do _remap: excecao lancada em filter escapa do pipeline de teste.
     *
     * @return array<string, mixed>
     */
    protected function corpo(): array
    {
        try {
            $corpo = $this->request->getJSON(true);
        } catch (HTTPException) {
            throw MalformedRequestException::invalidJson();
        }

        if ($corpo === null) {
            return [];
        }

        if (! is_array($corpo)) {
            throw MalformedRequestException::invalidJson();
        }

        return $corpo;
    }

    /**
     * A versao pode chegar no corpo ou no header If-Match. O corpo tem
     * precedencia: se o cliente mandou os dois, o que ele escreveu na requisicao
     * e mais explicito que o header ecoado do ETag anterior.
     *
     * @return array<string, mixed>
     */
    protected function corpoComVersao(): array
    {
        $corpo = $this->corpo();
        $ifMatch = $this->versaoDoIfMatch();

        if (! array_key_exists('versao', $corpo) && $ifMatch !== null) {
            $corpo['versao'] = $ifMatch;
        }

        return $corpo;
    }

    /**
     * Para rotas que exigem a versao mas nao tem corpo de alteracao, como DELETE
     * e as transicoes de status.
     */
    protected function versaoObrigatoria(): int
    {
        $corpo = $this->corpoComVersao();

        if (! array_key_exists('versao', $corpo) || ! is_scalar($corpo['versao'])) {
            throw MalformedRequestException::missingVersion();
        }

        $valor = trim((string) $corpo['versao']);

        if (! ctype_digit($valor) || (int) $valor < 1) {
            throw ValidationException::onField('versao', 'Deve ser um inteiro positivo.');
        }

        return (int) $valor;
    }

    protected function versaoDoIfMatch(): ?string
    {
        $valor = $this->request->getHeaderLine(self::HEADER_IF_MATCH);
        $valor = trim(preg_replace('/^W\//', '', trim($valor)), '"');

        return $valor === '' ? null : $valor;
    }

    protected function actor(): string
    {
        return trim($this->request->getHeaderLine(self::HEADER_ACTOR));
    }

    protected function idempotencyKey(): ?string
    {
        $chave = trim($this->request->getHeaderLine(self::HEADER_IDEMPOTENCY_KEY));

        return $chave === '' ? null : $chave;
    }

    protected function idempotencyKeyObrigatoria(): string
    {
        return $this->idempotencyKey() ?? throw MalformedRequestException::missingIdempotencyKey();
    }

    /**
     * O replay devolve o mesmo status da resposta original, para o cliente que
     * repetiu nao precisar tratar dois codigos para a mesma operacao. O header
     * e quem sinaliza que nada foi criado desta vez.
     */
    protected function comMarcaDeReplay(ResponseInterface $resposta, bool $replay): ResponseInterface
    {
        return $replay
            ? $resposta->setHeader(self::HEADER_IDEMPOTENCY_REPLAYED, 'true')
            : $resposta->removeHeader(self::HEADER_IDEMPOTENCY_REPLAYED);
    }
}
