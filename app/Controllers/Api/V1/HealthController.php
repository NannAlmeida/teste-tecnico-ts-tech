<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use CodeIgniter\HTTP\ResponseInterface;

final class HealthController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        return $this->responder->success([
            'status' => 'ok',
            'timestamp' => date(DATE_ATOM),
        ]);
    }
}
