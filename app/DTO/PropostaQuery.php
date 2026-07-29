<?php

declare(strict_types=1);

namespace App\DTO;

use App\Enums\PropostaOrigem;
use App\Enums\PropostaStatus;
use App\Exceptions\ValidationException;
use DateTimeImmutable;

final readonly class PropostaQuery
{
    public const SORTABLE = ['id', 'created_at', 'updated_at', 'valor_mensal', 'status'];
    public const FILTERS = [
        'cliente_id', 'status', 'origem', 'produto', 'valor_min', 'valor_max',
        'created_from', 'created_to', 'q', 'incluir_excluidas', 'sort', 'page', 'per_page',
    ];
    public const PER_PAGE_DEFAULT = PaginacaoQuery::PER_PAGE_DEFAULT;
    public const PER_PAGE_MAX = PaginacaoQuery::PER_PAGE_MAX;
    public const SORT_DEFAULT = '-created_at';

    /**
     * @param list<PropostaStatus>                        $status
     * @param list<PropostaOrigem>                        $origem
     * @param list<array{campo: string, direcao: string}> $sort
     */
    private function __construct(
        public ?int $clienteId,
        public array $status,
        public array $origem,
        public ?string $produto,
        public ?string $valorMin,
        public ?string $valorMax,
        public ?string $criadoDe,
        public ?string $criadoAte,
        public ?string $busca,
        public bool $incluirExcluidas,
        public array $sort,
        public int $page,
        public int $perPage,
    ) {
    }

    /**
     * @param array<string, mixed> $params
     *
     * @throws ValidationException com todos os parametros invalidos de uma vez
     */
    public static function fromArray(array $params): self
    {
        $erros = [];

        $desconhecidos = array_diff(array_keys($params), self::FILTERS);

        if ($desconhecidos !== []) {
            $erros['query'] = sprintf(
                'Parametro nao reconhecido: %s. Aceitos: %s.',
                implode(', ', $desconhecidos),
                implode(', ', self::FILTERS),
            );
        }

        $query = new self(
            self::inteiroPositivo($params, 'cliente_id', $erros),
            self::listaDeEnum($params, 'status', PropostaStatus::class, $erros),
            self::listaDeEnum($params, 'origem', PropostaOrigem::class, $erros),
            self::texto($params, 'produto', 120, $erros),
            self::decimal($params, 'valor_min', $erros),
            self::decimal($params, 'valor_max', $erros),
            self::dataLimite($params, 'created_from', ' 00:00:00', $erros),
            self::dataLimite($params, 'created_to', ' 23:59:59', $erros),
            self::texto($params, 'q', 120, $erros),
            self::booleano($params, 'incluir_excluidas', $erros),
            self::ordenacao($params, $erros),
            self::pagina($params, $erros),
            self::porPagina($params, $erros),
        );

        self::validarIntervalos($query, $erros);

        if ($erros !== []) {
            throw ValidationException::withErrors($erros);
        }

        return $query;
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    public function temBuscaTextual(): bool
    {
        return $this->busca !== null;
    }

    /**
     * @param array<string, mixed>          $erros
     * @param array<string, string|string[]> $params
     */
    private static function validarIntervalos(self $query, array &$erros): void
    {
        if ($query->valorMin !== null && $query->valorMax !== null
            && self::emCentavos($query->valorMin) > self::emCentavos($query->valorMax)) {
            $erros['valor_min'] = 'Deve ser menor ou igual a valor_max.';
        }

        if ($query->criadoDe !== null && $query->criadoAte !== null
            && $query->criadoDe > $query->criadoAte) {
            $erros['created_from'] = 'Deve ser anterior ou igual a created_to.';
        }
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $erros
     */
    private static function inteiroPositivo(array $params, string $campo, array &$erros): ?int
    {
        $valor = self::bruto($params, $campo);

        if ($valor === null) {
            return null;
        }

        if (! ctype_digit($valor) || (int) $valor < 1) {
            $erros[$campo] = 'Deve ser um inteiro positivo.';

            return null;
        }

        return (int) $valor;
    }

    /**
     * @param class-string<PropostaOrigem|PropostaStatus> $enum
     * @param array<string, mixed>                        $params
     * @param array<string, mixed>                        $erros
     *
     * @return list<PropostaOrigem|PropostaStatus>
     */
    private static function listaDeEnum(array $params, string $campo, string $enum, array &$erros): array
    {
        $valor = self::bruto($params, $campo);

        if ($valor === null) {
            return [];
        }

        $aceitos = array_column($enum::cases(), 'value');
        $itens = [];
        $invalidos = [];

        foreach (array_filter(array_map('trim', explode(',', $valor)), 'strlen') as $item) {
            $caso = $enum::tryFrom(strtoupper($item));

            if ($caso === null) {
                $invalidos[] = $item;

                continue;
            }

            $itens[$caso->value] = $caso;
        }

        if ($invalidos !== []) {
            $erros[$campo] = sprintf(
                'Valor invalido: %s. Aceitos: %s.',
                implode(', ', $invalidos),
                implode(', ', $aceitos),
            );
        }

        return array_values($itens);
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $erros
     */
    private static function texto(array $params, string $campo, int $maximo, array &$erros): ?string
    {
        $valor = self::bruto($params, $campo);

        if ($valor === null) {
            return null;
        }

        if (mb_strlen($valor) > $maximo) {
            $erros[$campo] = "Deve ter no maximo {$maximo} caracteres.";

            return null;
        }

        return $valor;
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $erros
     */
    private static function decimal(array $params, string $campo, array &$erros): ?string
    {
        $valor = self::bruto($params, $campo);

        if ($valor === null) {
            return null;
        }

        if (preg_match('/^\d{1,10}(\.\d{1,2})?$/', $valor) !== 1) {
            $erros[$campo] = 'Deve ser um decimal nao negativo com ate duas casas.';

            return null;
        }

        return $valor;
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $erros
     */
    private static function dataLimite(array $params, string $campo, string $complemento, array &$erros): ?string
    {
        $valor = self::bruto($params, $campo);

        if ($valor === null) {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) === 1) {
            $valor .= $complemento;
        }

        $data = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $valor);

        if ($data === false || $data->format('Y-m-d H:i:s') !== $valor) {
            $erros[$campo] = 'Deve estar no formato YYYY-MM-DD ou YYYY-MM-DD HH:MM:SS.';

            return null;
        }

        return $valor;
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $erros
     */
    private static function booleano(array $params, string $campo, array &$erros): bool
    {
        $valor = self::bruto($params, $campo);

        if ($valor === null) {
            return false;
        }

        if (! in_array(strtolower($valor), ['true', 'false', '1', '0'], true)) {
            $erros[$campo] = 'Deve ser true ou false.';

            return false;
        }

        return in_array(strtolower($valor), ['true', '1'], true);
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $erros
     *
     * @return list<array{campo: string, direcao: string}>
     */
    private static function ordenacao(array $params, array &$erros): array
    {
        $valor = self::bruto($params, 'sort') ?? self::SORT_DEFAULT;

        $ordens = [];
        $invalidos = [];

        foreach (array_filter(array_map('trim', explode(',', $valor)), 'strlen') as $item) {
            $descendente = str_starts_with($item, '-');
            $campo = ltrim($item, '-+');

            if (! in_array($campo, self::SORTABLE, true)) {
                $invalidos[] = $campo;

                continue;
            }

            $ordens[$campo] ??= ['campo' => $campo, 'direcao' => $descendente ? 'DESC' : 'ASC'];
        }

        if ($invalidos !== []) {
            $erros['sort'] = sprintf(
                'Coluna nao ordenavel: %s. Aceitas: %s.',
                implode(', ', $invalidos),
                implode(', ', self::SORTABLE),
            );
        }

        return array_values($ordens);
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $erros
     */
    private static function pagina(array $params, array &$erros): int
    {
        $valor = self::bruto($params, 'page');

        if ($valor === null) {
            return 1;
        }

        if (! ctype_digit($valor) || (int) $valor < 1) {
            $erros['page'] = 'Deve ser um inteiro maior ou igual a 1.';

            return 1;
        }

        return (int) $valor;
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $erros
     */
    private static function porPagina(array $params, array &$erros): int
    {
        $valor = self::bruto($params, 'per_page');

        if ($valor === null) {
            return self::PER_PAGE_DEFAULT;
        }

        if (! ctype_digit($valor) || (int) $valor < 1 || (int) $valor > self::PER_PAGE_MAX) {
            $erros['per_page'] = 'Deve ser um inteiro entre 1 e ' . self::PER_PAGE_MAX . '.';

            return self::PER_PAGE_DEFAULT;
        }

        return (int) $valor;
    }

    private static function emCentavos(string $valor): int
    {
        [$inteiro, $decimal] = array_pad(explode('.', $valor, 2), 2, '0');

        return (int) $inteiro * 100 + (int) str_pad($decimal, 2, '0');
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function bruto(array $params, string $campo): ?string
    {
        if (! array_key_exists($campo, $params) || ! is_scalar($params[$campo])) {
            return null;
        }

        $valor = trim((string) $params[$campo]);

        return $valor === '' ? null : $valor;
    }
}
