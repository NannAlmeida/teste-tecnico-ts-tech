<?php

declare(strict_types=1);

namespace App\Repositories;

use CodeIgniter\Entity\Entity;

interface IdempotentRepository
{
    public function findByIdempotencyKey(string $key): ?Entity;
}
