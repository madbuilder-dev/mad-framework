<?php

namespace Mad\Rest;

use Illuminate\Support\Facades\Route;
use Mad\Http\Middleware\RestDriverHmac;

/**
 * Configuração/gate do Driver REST. Fail-closed: desabilitado por padrão. Lê
 * `config('mad.rest_driver.*')` com fallback p/ env (robusto em apps gerados
 * que ainda não publicaram o bloco no config/mad.php).
 */
class RestDriver
{
    /**
     * Registra as 4 rotas do driver (ping/introspect/run/plan) sob o path
     * configurado, atrás do HMAC + throttle. Chamado pelo MadServiceProvider
     * quando habilitado (e pelos testes). Idempotente o suficiente p/ uso único.
     */
    public static function registerRoutes(): void
    {
        Route::middleware([RestDriverHmac::class, 'throttle:mad-rest-driver'])
            ->prefix(self::path())
            ->name('mad.rest-driver.')
            ->group(function () {
                Route::post('ping', [RestDriverController::class, 'ping'])->name('ping');
                Route::post('introspect', [RestDriverController::class, 'introspect'])->name('introspect');
                Route::post('run', [RestDriverController::class, 'run'])->name('run');
                Route::post('update', [RestDriverController::class, 'update'])->name('update');
                Route::post('plan', [RestDriverController::class, 'plan'])->name('plan');
            });
    }

    public static function enabled(): bool
    {
        $cfg = config('mad.rest_driver.enabled');
        $val = $cfg ?? getenv('MAD_REST_DRIVER_ENABLED');
        return filter_var($val, FILTER_VALIDATE_BOOLEAN);
    }

    /** Path relativo do endpoint (sem barras nas pontas). Default `_mad/rest-driver`. */
    public static function path(): string
    {
        $p = config('mad.rest_driver.path') ?: (getenv('MAD_REST_DRIVER_PATH') ?: '_mad/rest-driver');
        return trim((string) $p, '/');
    }

    /** Janela de validade do timestamp (anti-replay), em segundos. */
    public static function windowSeconds(): int
    {
        return (int) (config('mad.rest_driver.window_seconds') ?: 300);
    }

    /** Limite de requisições por minuto, por chave. */
    public static function rateLimit(): int
    {
        return (int) (config('mad.rest_driver.rate_limit') ?: 120);
    }

    /** Conexão padrão quando a chave não fixa uma. */
    public static function defaultConnection(): string
    {
        return (string) (config('mad.rest_driver.connection') ?: config('database.default'));
    }
}
