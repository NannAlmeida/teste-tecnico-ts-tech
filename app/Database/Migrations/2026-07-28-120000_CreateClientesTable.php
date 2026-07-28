<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateClientesTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'nome' => ['type' => 'VARCHAR', 'constraint' => 150],
            'email' => ['type' => 'VARCHAR', 'constraint' => 255],
            'documento' => ['type' => 'VARCHAR', 'constraint' => 14],
            'documento_tipo' => ['type' => 'ENUM', 'constraint' => ['CPF', 'CNPJ']],
            'idempotency_key' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'request_hash' => ['type' => 'CHAR', 'constraint' => 64, 'null' => true],
            'created_at' => ['type' => 'DATETIME'],
            'updated_at' => ['type' => 'DATETIME'],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('email', 'uq_clientes_email');
        $this->forge->addUniqueKey('documento', 'uq_clientes_documento');
        $this->forge->addUniqueKey('idempotency_key', 'uq_clientes_idempotency');

        $this->forge->createTable('clientes', false, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('clientes');
    }
}
