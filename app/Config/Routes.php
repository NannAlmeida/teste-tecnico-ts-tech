<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Home::index');

$routes->set404Override('App\Controllers\NotFoundController::index');

$routes->group('api/v1', ['namespace' => 'App\Controllers\Api\V1'], static function (RouteCollection $routes): void {
    $routes->get('health', 'HealthController::index');

    $routes->post('clientes', 'ClienteController::create');
    $routes->get('clientes/(:num)', 'ClienteController::show/$1');

    $routes->get('propostas', 'PropostaController::index');
    $routes->post('propostas', 'PropostaController::create');
    $routes->get('propostas/(:num)', 'PropostaController::show/$1');
    $routes->patch('propostas/(:num)', 'PropostaController::update/$1');
    $routes->delete('propostas/(:num)', 'PropostaController::delete/$1');

    $routes->post('propostas/(:num)/submit', 'PropostaController::submit/$1');
    $routes->post('propostas/(:num)/approve', 'PropostaController::approve/$1');
    $routes->post('propostas/(:num)/reject', 'PropostaController::reject/$1');
    $routes->post('propostas/(:num)/cancel', 'PropostaController::cancel/$1');

    $routes->get('propostas/(:num)/auditoria', 'PropostaAuditoriaController::index/$1');
});
