<?php

declare(strict_types=1);

namespace App\Enums;

enum PropostaOrigem: string
{
    case APP = 'APP';
    case SITE = 'SITE';
    case API = 'API';
}
