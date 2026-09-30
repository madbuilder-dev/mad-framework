<?php

namespace Mad\Http;

/**
 * Id único do request corrente — correlaciona linhas de log (SQL, trace)
 * geradas durante o mesmo ciclo HTTP/CLI.
 */
class RequestId
{
    private static ?string $id = null;

    public static function get(): string
    {
        return self::$id ??= uniqid('req_');
    }
}
