<?php

declare(strict_types=1);

namespace App\DTO;

use App\Enums\PropostaOrigem;
use App\Exceptions\ValidationException;

final readonly class CreatePropostaDTO
{
    public const CAMPOS = ['cliente_id', 'produto', 'valor_mensal', 'origem'];
    public const PRODUTO_MIN = 3;
    public const PRODUTO_MAX = 120;

    private function __construct(
        public int $clienteId,
        public string $produto,
        public string $valorMensal,
        public PropostaOrigem $origem,
    ) {
    }

    /**
     * @param array<string, mixed> $dados
     *
     * @throws ValidationException
     */
    public static function fromArray(array $dados): self
    {
        $erros = [];

        PropostaCampos::rejeitarDesconhecidos($dados, self::CAMPOS, $erros);

        $clienteId = PropostaCampos::inteiroPositivo($dados, 'cliente_id', $erros, obrigatorio: true);
        $produto = PropostaCampos::texto($dados, 'produto', self::PRODUTO_MIN, self::PRODUTO_MAX, $erros, obrigatorio: true);
        $valorMensal = PropostaCampos::valor($dados, 'valor_mensal', $erros, obrigatorio: true);
        $origem = PropostaCampos::origem($dados, $erros, obrigatorio: true);

        if ($erros !== [] || $clienteId === null || $produto === null || $valorMensal === null || $origem === null) {
            throw ValidationException::withErrors($erros);
        }

        return new self($clienteId, $produto, $valorMensal, $origem);
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'cliente_id' => (string) $this->clienteId,
            'produto' => $this->produto,
            'valor_mensal' => $this->valorMensal,
            'origem' => $this->origem->value,
        ];
    }
}
