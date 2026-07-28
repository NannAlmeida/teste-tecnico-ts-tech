<?php

declare(strict_types=1);

namespace App\Entities;

use App\Enums\PropostaOrigem;
use App\Enums\PropostaStatus;
use CodeIgniter\Entity\Entity;

class Proposta extends Entity
{
    protected $casts = [
        'id' => 'int',
        'cliente_id' => 'int',
        'status' => 'enum[' . PropostaStatus::class . ']',
        'origem' => 'enum[' . PropostaOrigem::class . ']',
        'versao' => 'int',
    ];

    public function isFinal(): bool
    {
        return $this->status->isFinal();
    }

    public function isDeleted(): bool
    {
        return $this->deleted_at !== null;
    }
}
