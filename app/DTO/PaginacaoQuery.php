<?php

declare(strict_types=1);

namespace App\DTO;

use App\Exceptions\ValidationException;

final readonly class PaginacaoQuery
{
    public const CAMPOS = ['page', 'per_page'];
    public const PER_PAGE_DEFAULT = 20;
    public const PER_PAGE_MAX = 100;

    private function __construct(
        public int $page,
        public int $perPage,
    ) {
    }

    /**
     * @param array<string, mixed> $params
     *
     * @throws ValidationException
     */
    public static function fromArray(array $params): self
    {
        $erros = [];

        PropostaCampos::rejeitarDesconhecidos($params, self::CAMPOS, $erros);

        $page = self::inteiroNaFaixa($params, 'page', 1, PHP_INT_MAX, 1, $erros);
        $perPage = self::inteiroNaFaixa($params, 'per_page', 1, self::PER_PAGE_MAX, self::PER_PAGE_DEFAULT, $erros);

        if ($erros !== []) {
            throw ValidationException::withErrors($erros);
        }

        return new self($page, $perPage);
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $erros
     */
    private static function inteiroNaFaixa(array $params, string $campo, int $minimo, int $maximo, int $padrao, array &$erros): int
    {
        if (! array_key_exists($campo, $params) || ! is_scalar($params[$campo])) {
            return $padrao;
        }

        $valor = trim((string) $params[$campo]);

        if ($valor === '') {
            return $padrao;
        }

        if (! ctype_digit($valor) || (int) $valor < $minimo || (int) $valor > $maximo) {
            $erros[$campo] = $maximo === PHP_INT_MAX
                ? "Deve ser um inteiro maior ou igual a {$minimo}."
                : "Deve ser um inteiro entre {$minimo} e {$maximo}.";

            return $padrao;
        }

        return (int) $valor;
    }
}
