<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Enums\DocumentoTipo;
use App\Exceptions\ValidationException;

final readonly class Documento
{
    private const PESOS_CPF_1 = [10, 9, 8, 7, 6, 5, 4, 3, 2];
    private const PESOS_CPF_2 = [11, 10, 9, 8, 7, 6, 5, 4, 3, 2];
    private const PESOS_CNPJ_1 = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
    private const PESOS_CNPJ_2 = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

    private function __construct(
        public string $valor,
        public DocumentoTipo $tipo,
    ) {
    }

    /**
     * @throws ValidationException
     */
    public static function de(string $bruto): self
    {
        return self::tryDe($bruto) ?? throw ValidationException::onField(
            'documento',
            'Informe um CPF ou CNPJ valido.',
        );
    }

    public static function tryDe(string $bruto): ?self
    {
        $valor = self::normalizar($bruto);
        $tipo = self::tipoDe($valor);

        if ($tipo === null || self::temUmCaractereSo($valor) || ! self::digitosConferem($valor, $tipo)) {
            return null;
        }

        return new self($valor, $tipo);
    }

    public static function ehValido(string $bruto): bool
    {
        return self::tryDe($bruto) !== null;
    }

    public function formatado(): string
    {
        if ($this->tipo === DocumentoTipo::CPF) {
            return vsprintf('%s%s%s.%s%s%s.%s%s%s-%s%s', str_split($this->valor));
        }

        return vsprintf('%s%s.%s%s%s.%s%s%s/%s%s%s%s-%s%s', str_split($this->valor));
    }

    public function equals(self $outro): bool
    {
        return $this->valor === $outro->valor;
    }

    private static function normalizar(string $bruto): string
    {
        return strtoupper((string) preg_replace('/[^0-9A-Za-z]/', '', $bruto));
    }

    private static function tipoDe(string $valor): ?DocumentoTipo
    {
        if (preg_match('/^\d{11}$/', $valor) === 1) {
            return DocumentoTipo::CPF;
        }

        if (preg_match('/^[0-9A-Z]{12}\d{2}$/', $valor) === 1) {
            return DocumentoTipo::CNPJ;
        }

        return null;
    }

    private static function temUmCaractereSo(string $valor): bool
    {
        return count(array_unique(str_split($valor))) === 1;
    }

    private static function digitosConferem(string $valor, DocumentoTipo $tipo): bool
    {
        [$pesos1, $pesos2] = $tipo === DocumentoTipo::CPF
            ? [self::PESOS_CPF_1, self::PESOS_CPF_2]
            : [self::PESOS_CNPJ_1, self::PESOS_CNPJ_2];

        $base = substr($valor, 0, count($pesos1));
        $primeiro = self::digito($base, $pesos1);
        $segundo = self::digito($base . $primeiro, $pesos2);

        return substr($valor, -2) === $primeiro . $segundo;
    }

    /**
     * A conversao e ASCII menos 48, conforme a IN RFB 2.229/2024: digitos
     * mantem o proprio valor e letras seguem a partir de A = 17.
     *
     * @param list<int> $pesos
     */
    private static function digito(string $base, array $pesos): string
    {
        $soma = 0;

        foreach ($pesos as $posicao => $peso) {
            $soma += (ord($base[$posicao]) - 48) * $peso;
        }

        $resto = $soma % 11;

        return (string) ($resto < 2 ? 0 : 11 - $resto);
    }
}
