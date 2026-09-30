<?php
/**
 * Helpers de path estilo Laravel (base_path/app_path/database_path/storage_path).
 *
 * O illuminate/database (ORM) chama base_path()/database_path() para resolver
 * caminhos relativos — ex.: SQLiteConnector::connect() faz
 *   realpath($path) ?: realpath(base_path($path))
 * Fora do Laravel essas funções não existem → "Call to undefined function
 * Illuminate\Database\Connectors\base_path()" (fatal) sempre que o realpath
 * falha (DB inexistente, cwd diferente da raiz, etc.).
 *
 * Aqui definimos os helpers ancorados na RAIZ do projeto (pasta que contém o
 * composer.json), guardados por function_exists para não colidir se um dia o
 * Laravel/helper real for carregado.
 */

if (!defined('MAD_BASE_PATH')) {
    // lib/mad/helpers-path.php → dirname(__DIR__, 2) = raiz do projeto.
    define('MAD_BASE_PATH', dirname(__DIR__, 2));
}

if (!function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        return MAD_BASE_PATH . ($path !== '' ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : '');
    }
}

if (!function_exists('app_path')) {
    function app_path(string $path = ''): string
    {
        return base_path('app' . ($path !== '' ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : ''));
    }
}

if (!function_exists('database_path')) {
    function database_path(string $path = ''): string
    {
        return base_path('app/database' . ($path !== '' ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : ''));
    }
}

if (!function_exists('storage_path')) {
    function storage_path(string $path = ''): string
    {
        return base_path('tmp' . ($path !== '' ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : ''));
    }
}
