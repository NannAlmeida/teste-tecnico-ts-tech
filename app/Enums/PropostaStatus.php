<?php

declare(strict_types=1);

namespace App\Enums;

enum PropostaStatus: string
{
    case DRAFT = 'DRAFT';
    case SUBMITTED = 'SUBMITTED';
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';
    case CANCELED = 'CANCELED';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::DRAFT => [self::SUBMITTED, self::CANCELED],
            self::SUBMITTED => [self::APPROVED, self::REJECTED, self::CANCELED],
            self::APPROVED, self::REJECTED, self::CANCELED => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isFinal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /**
     * @return list<string>
     */
    public function allowedTransitionValues(): array
    {
        return array_map(static fn (self $status): string => $status->value, $this->allowedTransitions());
    }
}
