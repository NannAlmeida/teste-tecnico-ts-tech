<?php

declare(strict_types=1);

namespace App\Models;

use App\Entities\PropostaAuditoria;
use CodeIgniter\Model;

class PropostaAuditoriaModel extends Model
{
    protected $table = 'proposta_auditorias';
    protected $primaryKey = 'id';
    protected $returnType = PropostaAuditoria::class;
    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'proposta_id',
        'actor',
        'evento',
        'payload',
        'idempotency_key',
        'request_hash',
    ];

    protected $useTimestamps = true;
    protected $updatedField = '';
    protected $dateFormat = 'datetime';
}
