<?php

declare(strict_types=1);

namespace App\Models;

use App\Entities\Proposta;
use CodeIgniter\Model;

class PropostaModel extends Model
{
    protected $table = 'propostas';
    protected $primaryKey = 'id';
    protected $returnType = Proposta::class;
    protected $useSoftDeletes = true;
    protected $deletedField = 'deleted_at';

    protected $allowedFields = [
        'cliente_id',
        'produto',
        'valor_mensal',
        'status',
        'origem',
        'versao',
        'idempotency_key',
        'request_hash',
    ];

    protected $useTimestamps = true;
    protected $dateFormat = 'datetime';
}
