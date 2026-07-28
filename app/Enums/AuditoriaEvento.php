<?php

declare(strict_types=1);

namespace App\Enums;

enum AuditoriaEvento: string
{
    case CREATED = 'CREATED';
    case UPDATED_FIELDS = 'UPDATED_FIELDS';
    case STATUS_CHANGED = 'STATUS_CHANGED';
    case DELETED_LOGICAL = 'DELETED_LOGICAL';
}
