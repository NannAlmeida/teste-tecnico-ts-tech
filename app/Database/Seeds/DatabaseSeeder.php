<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->limpar();

        $this->call(ClienteSeeder::class);
        $this->call(PropostaSeeder::class);
    }

    private function limpar(): void
    {
        foreach (['proposta_auditorias', 'propostas', 'clientes'] as $tabela) {
            $this->db->table($tabela)->emptyTable();
        }
    }
}
