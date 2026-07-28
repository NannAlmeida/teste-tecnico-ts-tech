<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class ClienteSeeder extends Seeder
{
    private const CLIENTES = [
        ['Ana Carolina Souza', 'ana.souza@exemplo.com', '52998224725', 'CPF'],
        ['Bruno Ferreira Lima', 'bruno.lima@exemplo.com', '11144477735', 'CPF'],
        ['Carla Mendes Rocha', 'carla.rocha@exemplo.com', '39053344705', 'CPF'],
        ['Diego Almeida Pinto', 'diego.pinto@exemplo.com', '12852760002', 'CPF'],
        ['Eduarda Nunes Braga', 'eduarda.braga@exemplo.com', '24681957011', 'CPF'],
        ['Fabio Teixeira Moraes', 'fabio.moraes@exemplo.com', '87536142919', 'CPF'],
        ['Horizonte Servicos LTDA', 'contato@horizonte.exemplo.com', '11339752000106', 'CNPJ'],
        ['Aurora Tecnologia SA', 'financeiro@aurora.exemplo.com', '34528927000110', 'CNPJ'],
        ['Vertice Consultoria ME', 'contato@vertice.exemplo.com', '12ABC34501DE35', 'CNPJ'],
        ['Meridiano Logistica LTDA', 'comercial@meridiano.exemplo.com', 'RS7XY2W8000138', 'CNPJ'],
    ];

    public function run(): void
    {
        $agora = date('Y-m-d H:i:s');
        $linhas = [];

        foreach (self::CLIENTES as $indice => [$nome, $email, $documento, $tipo]) {
            $linhas[] = [
                'nome' => $nome,
                'email' => $email,
                'documento' => $documento,
                'documento_tipo' => $tipo,
                'created_at' => date('Y-m-d H:i:s', strtotime("-{$indice} days", strtotime($agora))),
                'updated_at' => $agora,
            ];
        }

        $this->db->table('clientes')->insertBatch($linhas);
    }
}
