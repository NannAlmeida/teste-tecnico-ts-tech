<?php

declare(strict_types=1);

namespace App\Entities;

use App\Enums\DocumentoTipo;
use CodeIgniter\Entity\Entity;

class Cliente extends Entity
{
    protected $casts = [
        'id' => 'int',
        'documento_tipo' => 'enum[' . DocumentoTipo::class . ']',
    ];

    protected $dates = ['created_at', 'updated_at'];
}
