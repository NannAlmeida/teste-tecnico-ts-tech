<?php

declare(strict_types=1);

namespace App\Filters;

use App\Http\ErrorCode;
use App\Http\TraceId;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\RateLimit;

class ApiThrottleFilter implements FilterInterface
{
    /**
     * Devolve a resposta em vez de lancar: excecao em filter roda fora do catch
     * do _remap e escapa do pipeline de teste.
     *
     * @param list<string>|null $arguments limite e janela, sobrepondo Config\RateLimit
     */
    public function before(RequestInterface $request, $arguments = null): ?ResponseInterface
    {
        [$limite, $janela] = $this->limites($arguments);
        $throttler = service('throttler');

        if ($throttler->check($this->chave($request), $limite, $janela)) {
            return null;
        }

        return service('apiResponder')
            ->failure(ErrorCode::RATE_LIMITED, TraceId::fromRequest($request))
            ->setHeader('X-RateLimit-Limit', (string) $limite)
            ->setHeader('X-RateLimit-Window', (string) $janela)
            ->setHeader('Retry-After', (string) $throttler->getTokentime());
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): null
    {
        return null;
    }

    /**
     * O CodeIgniter reserva {}()/\@: em chave de cache, e endereco IPv6 e cheio
     * de dois-pontos. IPv4 continua legivel na chave.
     */
    private function chave(RequestInterface $request): string
    {
        return 'api_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $request->getIPAddress());
    }

    /**
     * @param list<string>|null $arguments
     *
     * @return array{0: int, 1: int}
     */
    private function limites(?array $arguments): array
    {
        $config = config(RateLimit::class);

        return [
            (int) ($arguments[0] ?? $config->limite),
            (int) ($arguments[1] ?? $config->janela),
        ];
    }
}
