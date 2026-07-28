<?php

declare(strict_types=1);

namespace App\Entities;

use CodeIgniter\Entity\Entity;

class Cliente extends Entity
{
    protected $casts = [
        'id' => 'int',
    ];

    protected $dates = ['created_at', 'updated_at'];
}
