<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreatePropostasTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'cliente_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'produto' => ['type' => 'VARCHAR', 'constraint' => 120],
            'valor_mensal' => ['type' => 'DECIMAL', 'constraint' => '12,2'],
            'status' => [
                'type' => 'ENUM',
                'constraint' => ['DRAFT', 'SUBMITTED', 'APPROVED', 'REJECTED', 'CANCELED'],
                'default' => 'DRAFT',
            ],
            'origem' => ['type' => 'ENUM', 'constraint' => ['APP', 'SITE', 'API']],
            'versao' => ['type' => 'INT', 'unsigned' => true, 'default' => 1],
            'idempotency_key' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'request_hash' => ['type' => 'CHAR', 'constraint' => 64, 'null' => true],
            'created_at' => ['type' => 'DATETIME'],
            'updated_at' => ['type' => 'DATETIME'],
            'deleted_at' => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('idempotency_key', 'uq_propostas_idempotency');
        $this->forge->addKey(['deleted_at', 'status', 'created_at'], false, false, 'idx_propostas_status_created');
        $this->forge->addKey(['cliente_id', 'deleted_at'], false, false, 'idx_propostas_cliente');
        $this->forge->addKey(['deleted_at', 'valor_mensal'], false, false, 'idx_propostas_valor');

        $this->forge->addForeignKey('cliente_id', 'clientes', 'id', 'CASCADE', 'RESTRICT', 'fk_propostas_cliente');

        $this->forge->createTable('propostas', false, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('propostas');
    }
}
