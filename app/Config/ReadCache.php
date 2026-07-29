<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

class ReadCache extends BaseConfig
{
    public bool $habilitado = true;
    public int $ttl = 60;

    public function __construct()
    {
        parent::__construct();

        // O CIUnitTestCase injeta um MockCache que persiste entre os testes do
        // processo, e os ids reiniciam a cada refresh do banco: a proposta 1 de
        // um teste apareceria no teste seguinte. PropostaCacheTest habilita
        // explicitamente para exercitar o comportamento.
        if (ENVIRONMENT === 'testing') {
            $this->habilitado = false;
        }
    }
}
