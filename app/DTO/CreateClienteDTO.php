<?php

declare(strict_types=1);

namespace App\DTO;

use App\Exceptions\ValidationException;
use App\ValueObjects\Documento;

final readonly class CreateClienteDTO
{
    public const CAMPOS = ['nome', 'email', 'documento'];
    public const NOME_MIN = 3;
    public const NOME_MAX = 150;
    public const EMAIL_MAX = 255;

    private function __construct(
        public string $nome,
        public string $email,
        public Documento $documento,
    ) {
    }

    /**
     * @param array<string, mixed> $dados
     *
     * @throws ValidationException com todos os campos invalidos de uma vez
     */
    public static function fromArray(array $dados): self
    {
        $erros = [];

        $desconhecidos = array_diff(array_keys($dados), self::CAMPOS);

        if ($desconhecidos !== []) {
            $erros['corpo'] = sprintf(
                'Campo nao reconhecido: %s. Aceitos: %s.',
                implode(', ', $desconhecidos),
                implode(', ', self::CAMPOS),
            );
        }

        $nome = self::nome($dados, $erros);
        $email = self::email($dados, $erros);
        $documento = self::documento($dados, $erros);

        if ($erros !== [] || $nome === null || $email === null || $documento === null) {
            throw ValidationException::withErrors($erros);
        }

        return new self($nome, $email, $documento);
    }

    /**
     * Payload canonico para o hash de idempotencia. Usa os valores ja
     * normalizados, entao a mesma requisicao com o documento mascarado ou o
     * email em outra caixa produz a mesma chave de comparacao.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'nome' => $this->nome,
            'email' => $this->email,
            'documento' => $this->documento->valor,
        ];
    }

    /**
     * @param array<string, mixed> $dados
     * @param array<string, mixed> $erros
     */
    private static function nome(array $dados, array &$erros): ?string
    {
        $valor = self::bruto($dados, 'nome');

        if ($valor === null) {
            $erros['nome'] = 'Campo obrigatorio.';

            return null;
        }

        $tamanho = mb_strlen($valor);

        if ($tamanho < self::NOME_MIN || $tamanho > self::NOME_MAX) {
            $erros['nome'] = sprintf('Deve ter entre %d e %d caracteres.', self::NOME_MIN, self::NOME_MAX);

            return null;
        }

        return $valor;
    }

    /**
     * @param array<string, mixed> $dados
     * @param array<string, mixed> $erros
     */
    private static function email(array $dados, array &$erros): ?string
    {
        $valor = self::bruto($dados, 'email');

        if ($valor === null) {
            $erros['email'] = 'Campo obrigatorio.';

            return null;
        }

        $valor = mb_strtolower($valor);

        if (mb_strlen($valor) > self::EMAIL_MAX) {
            $erros['email'] = 'Deve ter no maximo ' . self::EMAIL_MAX . ' caracteres.';

            return null;
        }

        if (filter_var($valor, FILTER_VALIDATE_EMAIL) === false) {
            $erros['email'] = 'Informe um endereco de email valido.';

            return null;
        }

        return $valor;
    }

    /**
     * @param array<string, mixed> $dados
     * @param array<string, mixed> $erros
     */
    private static function documento(array $dados, array &$erros): ?Documento
    {
        $valor = self::bruto($dados, 'documento');

        if ($valor === null) {
            $erros['documento'] = 'Campo obrigatorio.';

            return null;
        }

        $documento = Documento::tryDe($valor);

        if ($documento === null) {
            $erros['documento'] = 'Informe um CPF ou CNPJ valido.';
        }

        return $documento;
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
