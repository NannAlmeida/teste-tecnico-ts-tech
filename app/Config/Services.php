<?php

namespace Config;

use App\Http\ApiResponder;
use App\Models\ClienteModel;
use App\Models\PropostaAuditoriaModel;
use App\Models\PropostaModel;
use App\Repositories\ClienteRepository;
use App\Repositories\PropostaAuditoriaRepository;
use App\Repositories\PropostaRepository;
use App\Services\AuditoriaService;
use App\Services\ClienteService;
use App\Services\IdempotencyService;
use App\Services\PropostaService;
use App\Services\TransactionRunner;
use CodeIgniter\Config\BaseService;

/**
 * Services Configuration file.
 *
 * Services are simply other classes/libraries that the system uses
 * to do its job. This is used by CodeIgniter to allow the core of the
 * framework to be swapped out easily without affecting the usage within
 * the rest of your application.
 *
 * This file holds any application-specific services, or service overrides
 * that you might need. An example has been included with the general
 * method format you should use for your service methods. For more examples,
 * see the core Services file at system/Config/Services.php.
 */
class Services extends BaseService
{
    public static function apiResponder(bool $getShared = true): ApiResponder
    {
        if ($getShared) {
            return static::getSharedInstance('apiResponder');
        }

        return new ApiResponder(service('response'));
    }

    public static function transactionRunner(bool $getShared = true): TransactionRunner
    {
        if ($getShared) {
            return static::getSharedInstance('transactionRunner');
        }

        return new TransactionRunner(db_connect());
    }

    public static function idempotencyService(bool $getShared = true): IdempotencyService
    {
        if ($getShared) {
            return static::getSharedInstance('idempotencyService');
        }

        return new IdempotencyService(static::transactionRunner());
    }

    public static function clienteRepository(bool $getShared = true): ClienteRepository
    {
        if ($getShared) {
            return static::getSharedInstance('clienteRepository');
        }

        return new ClienteRepository(new ClienteModel());
    }

    public static function clienteService(bool $getShared = true): ClienteService
    {
        if ($getShared) {
            return static::getSharedInstance('clienteService');
        }

        return new ClienteService(static::clienteRepository(), static::idempotencyService());
    }

    public static function propostaRepository(bool $getShared = true): PropostaRepository
    {
        if ($getShared) {
            return static::getSharedInstance('propostaRepository');
        }

        return new PropostaRepository(new PropostaModel());
    }

    public static function propostaAuditoriaRepository(bool $getShared = true): PropostaAuditoriaRepository
    {
        if ($getShared) {
            return static::getSharedInstance('propostaAuditoriaRepository');
        }

        return new PropostaAuditoriaRepository(new PropostaAuditoriaModel());
    }

    public static function auditoriaService(bool $getShared = true): AuditoriaService
    {
        if ($getShared) {
            return static::getSharedInstance('auditoriaService');
        }

        return new AuditoriaService(static::propostaAuditoriaRepository());
    }

    public static function propostaService(bool $getShared = true): PropostaService
    {
        if ($getShared) {
            return static::getSharedInstance('propostaService');
        }

        return new PropostaService(
            static::propostaRepository(),
            static::clienteRepository(),
            static::auditoriaService(),
            static::propostaAuditoriaRepository(),
            static::idempotencyService(),
            static::transactionRunner(),
        );
    }
}
