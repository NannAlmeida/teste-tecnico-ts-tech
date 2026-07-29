<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

class RateLimit extends BaseConfig
{
    public int $limite = 60;
    public int $janela = 60;

    public function __construct()
    {
        parent::__construct();

        // A suite compartilha um unico IP e o CIUnitTestCase injeta um MockCache
        // que persiste entre os testes do processo. Com o limite de producao o
        // bucket esgotaria no meio da suite e derrubaria casos que nada tem a
        // ver com throttle. RateLimitTest injeta a propria configuracao e o
        // proprio throttler para exercitar o limite de verdade.
        if (ENVIRONMENT === 'testing') {
            $this->limite = 1000000;
        }
    }
}
