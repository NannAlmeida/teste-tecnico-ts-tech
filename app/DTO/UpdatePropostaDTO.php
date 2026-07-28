<?php

declare(strict_types=1);

namespace App\DTO;

use App\Exceptions\MalformedRequestException;
use App\Exceptions\ValidationException;

final readonly class UpdatePropostaDTO
{
    public const ALTERAVEIS = ['produto', 'valor_mensal', 'origem'];
    public const CAMPOS = ['versao', 'produto', 'valor_mensal', 'origem'];

    /**
     * @param array<string, string> $alteracoes apenas os campos presentes no corpo
     */
    private function __construct(
        public int $versao,
        public array $alteracoes,
    ) {
    }

    /**
     * @param array<string, mixed> $dados
     *
     * @throws MalformedRequestException quando a versao nao foi informada
     * @throws ValidationException
     */
    public static function fromArray(array $dados): self
    {
        $erros = [];

        PropostaCampos::rejeitarDesconhecidos($dados, self::CAMPOS, $erros);

        if (! array_key_exists('versao', $dados)) {
            throw MalformedRequestException::missingVersion();
        }

        $versao = PropostaCampos::inteiroPositivo($dados, 'versao', $erros, obrigatorio: true);
        $alteracoes = self::alteracoes($dados, $erros);

        if ($alteracoes === [] && $erros === []) {
            $erros['corpo'] = sprintf('Informe ao menos um campo para alterar: %s.', implode(', ', self::ALTERAVEIS));
        }

        if ($erros !== [] || $versao === null) {
            throw ValidationException::withErrors($erros);
        }

        return new self($versao, $alteracoes);
    }

    /**
     * @param array<string, mixed> $dados
     * @param array<string, mixed> $erros
     *
     * @return array<string, string>
     */
    private static function alteracoes(array $dados, array &$erros): array
    {
        $alteracoes = [];

        if (array_key_exists('produto', $dados)) {
            $produto = PropostaCampos::texto(
                $dados,
                'produto',
                CreatePropostaDTO::PRODUTO_MIN,
                CreatePropostaDTO::PRODUTO_MAX,
                $erros,
                obrigatorio: true,
            );

            if ($produto !== null) {
                $alteracoes['produto'] = $produto;
            }
        }

        if (array_key_exists('valor_mensal', $dados)) {
            $valor = PropostaCampos::valor($dados, 'valor_mensal', $erros, obrigatorio: true);

            if ($valor !== null) {
                $alteracoes['valor_mensal'] = $valor;
            }
        }

        if (array_key_exists('origem', $dados)) {
            $origem = PropostaCampos::origem($dados, $erros, obrigatorio: true);

            if ($origem !== null) {
                $alteracoes['origem'] = $origem->value;
            }
        }

        return $alteracoes;
    }
}
