<?php

declare(strict_types=1);

namespace App\DTO;

use App\Enums\PropostaOrigem;

/**
 * Regras de campo compartilhadas entre a criacao e a alteracao de proposta,
 * para que o mesmo campo nao seja validado de dois jeitos diferentes.
 */
final class PropostaCampos
{
    /**
     * @param array<string, mixed> $dados
     * @param list<string>         $aceitos
     * @param array<string, mixed> $erros
     */
    public static function rejeitarDesconhecidos(array $dados, array $aceitos, array &$erros): void
    {
        $desconhecidos = array_diff(array_keys($dados), $aceitos);

        if ($desconhecidos !== []) {
            $erros['corpo'] = sprintf(
                'Campo nao reconhecido: %s. Aceitos: %s.',
                implode(', ', $desconhecidos),
                implode(', ', $aceitos),
            );
        }
    }

    /**
     * @param array<string, mixed> $dados
     * @param array<string, mixed> $erros
     */
    public static function inteiroPositivo(array $dados, string $campo, array &$erros, bool $obrigatorio = false): ?int
    {
        $valor = self::bruto($dados, $campo);

        if ($valor === null) {
            if ($obrigatorio) {
                $erros[$campo] = 'Campo obrigatorio.';
            }

            return null;
        }

        if (! ctype_digit($valor) || (int) $valor < 1) {
            $erros[$campo] = 'Deve ser um inteiro positivo.';

            return null;
        }

        return (int) $valor;
    }

    /**
     * @param array<string, mixed> $dados
     * @param array<string, mixed> $erros
     */
    public static function texto(array $dados, string $campo, int $minimo, int $maximo, array &$erros, bool $obrigatorio = false): ?string
    {
        $valor = self::bruto($dados, $campo);

        if ($valor === null) {
            if ($obrigatorio) {
                $erros[$campo] = 'Campo obrigatorio.';
            }

            return null;
        }

        $tamanho = mb_strlen($valor);

        if ($tamanho < $minimo || $tamanho > $maximo) {
            $erros[$campo] = sprintf('Deve ter entre %d e %d caracteres.', $minimo, $maximo);

            return null;
        }

        return $valor;
    }

    /**
     * Normaliza para duas casas: 199.9 e 199.90 sao o mesmo valor, e precisam
     * gerar o mesmo hash de idempotencia e a mesma string gravada.
     *
     * @param array<string, mixed> $dados
     * @param array<string, mixed> $erros
     */
    public static function valor(array $dados, string $campo, array &$erros, bool $obrigatorio = false): ?string
    {
        $valor = self::bruto($dados, $campo);

        if ($valor === null) {
            if ($obrigatorio) {
                $erros[$campo] = 'Campo obrigatorio.';
            }

            return null;
        }

        if (preg_match('/^\d{1,10}(\.\d{1,2})?$/', $valor) !== 1) {
            $erros[$campo] = 'Deve ser um decimal nao negativo com ate duas casas.';

            return null;
        }

        if ((float) $valor <= 0) {
            $erros[$campo] = 'Deve ser maior que zero.';

            return null;
        }

        [$inteiro, $decimal] = array_pad(explode('.', $valor, 2), 2, '0');

        return $inteiro . '.' . str_pad($decimal, 2, '0');
    }

    /**
     * @param array<string, mixed> $dados
     * @param array<string, mixed> $erros
     */
    public static function origem(array $dados, array &$erros, bool $obrigatorio = false): ?PropostaOrigem
    {
        $valor = self::bruto($dados, 'origem');

        if ($valor === null) {
            if ($obrigatorio) {
                $erros['origem'] = 'Campo obrigatorio.';
            }

            return null;
        }

        $origem = PropostaOrigem::tryFrom(strtoupper($valor));

        if ($origem === null) {
            $erros['origem'] = sprintf(
                'Valor invalido. Aceitos: %s.',
                implode(', ', array_column(PropostaOrigem::cases(), 'value')),
            );
        }

        return $origem;
    }

    /**
     * @param array<string, mixed> $dados
     */
    private static function bruto(array $dados, string $campo): ?string
    {
        if (! array_key_exists($campo, $dados) || ! is_scalar($dados[$campo])) {
            return null;
        }

        $valor = trim((string) $dados[$campo]);

        return $valor === '' ? null : $valor;
    }
}
