<?php

declare(strict_types=1);

namespace App\Models;

use App\Entities\Cliente;
use CodeIgniter\Model;

class ClienteModel extends Model
{
    protected $table = 'clientes';
    protected $primaryKey = 'id';
    protected $returnType = Cliente::class;
    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'nome',
        'email',
        'documento',
        'documento_tipo',
        'idempotency_key',
        'request_hash',
    ];

    protected $useTimestamps = true;
    protected $dateFormat = 'datetime';
}
