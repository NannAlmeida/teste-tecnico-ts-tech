<?php

declare(strict_types=1);

namespace App\Controllers;

use CodeIgniter\Controller;

final class DocsController extends Controller
{
    public function index(): string
    {
        return view('docs', ['spec' => '/openapi.json']);
    }
}
