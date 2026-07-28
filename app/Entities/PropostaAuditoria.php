<?php

declare(strict_types=1);

namespace App\Entities;

use App\Enums\AuditoriaEvento;
use CodeIgniter\Entity\Entity;

class PropostaAuditoria extends Entity
{
    protected $casts = [
        'id' => 'int',
        'proposta_id' => 'int',
        'evento' => 'enum[' . AuditoriaEvento::class . ']',
        'payload' => 'json[array]',
    ];

    protected $dates = ['created_at'];
}
