<?php

declare(strict_types=1);

namespace App\Http;

use CodeIgniter\HTTP\RequestInterface;

final class TraceId
{
    public const HEADER = 'X-Trace-Id';

    public static function generate(): string
    {
        return bin2hex(random_bytes(8));
    }

    public static function fromRequest(RequestInterface $request): string
    {
        $current = $request->getHeaderLine(self::HEADER);

        return $current !== '' ? $current : self::generate();
    }
}
