<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreatePropostaAuditoriasTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'proposta_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'actor' => ['type' => 'VARCHAR', 'constraint' => 100],
            'evento' => [
                'type' => 'ENUM',
                'constraint' => ['CREATED', 'UPDATED_FIELDS', 'STATUS_CHANGED', 'DELETED_LOGICAL'],
            ],
            'payload' => ['type' => 'JSON', 'null' => true],
            'idempotency_key' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'request_hash' => ['type' => 'CHAR', 'constraint' => 64, 'null' => true],
            'created_at' => ['type' => 'DATETIME'],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['proposta_id', 'id'], false, false, 'idx_auditorias_proposta');
        $this->forge->addUniqueKey('idempotency_key', 'uq_auditorias_idempotency');

        $this->forge->addForeignKey('proposta_id', 'propostas', 'id', 'CASCADE', 'CASCADE', 'fk_auditorias_proposta');

        $this->forge->createTable('proposta_auditorias', false, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('proposta_auditorias');
    }
}
