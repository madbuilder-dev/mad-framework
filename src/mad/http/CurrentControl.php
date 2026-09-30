<?php

namespace Mad\Http;

/**
 * Control (MadComponent) que está atendendo a requisição corrente.
 *
 * Em GET /app/<slug> a classe vem do default 'class' da rota; em POST
 * /app/_mad-wire ela vem de dentro do mad_state criptografado — só o
 * MadComponentHandler sabe. Ele registra aqui para os logs de auditoria
 * (change log, SQL log) conseguirem gravar `class_name` sem abrir o token.
 * Basename, sem namespace (mesmo formato do legado $_REQUEST['class']).
 */
final class CurrentControl
{
    private static ?string $class = null;

    public static function set(?string $class): void
    {
        self::$class = $class ? class_basename($class) : null;
    }

    public static function get(): ?string
    {
        return self::$class;
    }

    /** Defensivo p/ worker/queue/testes reutilizando o processo. */
    public static function reset(): void
    {
        self::$class = null;
    }
}
