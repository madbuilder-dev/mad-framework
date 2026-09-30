<?php

declare(strict_types=1);

namespace Mad\Util;

/**
 * MenuPaths — ponto ÚNICO de resolução dos XMLs de menu (issue #62).
 *
 * Os menus (menu.xml, top_menu.xml, menu-navbar-dropdown.xml, + variantes) saíram
 * da raiz do projeto para `resources/menus/` (fonte versionada). Antes o caminho
 * era montado à mão em 4+ lugares (`PATH . '/menu.xml'`, `base_path('menu.xml')`);
 * agora todos passam por aqui. O diretório é overridável por config
 * (`mad.menu.path`), então consuming projects / o MadBuilder podem mudar sem
 * hardcode.
 *
 *   - dir()       — diretório dos menus (absoluto).
 *   - file($n)    — caminho canônico de UM arquivo (alvo de ESCRITA + leitura default).
 *   - resolve($n) — caminho de LEITURA com compat: o canônico se existir, senão o
 *                   legado na raiz (apps ainda não migrados), senão o canônico.
 *   - relativeDir() — o dir relativo ao base_path (p/ writers base-relative).
 */
final class MenuPaths
{
    /** Diretório dos XMLs de menu. Config-overridável; default resources/menus. */
    public static function dir(): string
    {
        $path = config('mad.menu.path');

        return $path ? (string) $path : resource_path('menus');
    }

    /** Caminho canônico de um arquivo de menu (alvo de escrita / leitura default). */
    public static function file(string $name): string
    {
        return rtrim(self::dir(), '/\\') . '/' . ltrim($name, '/\\');
    }

    /**
     * Resolve p/ LEITURA: o canônico (resources/menus) se existir, senão o legado
     * na raiz do projeto (compat com apps ainda não migrados), senão o canônico
     * (mesmo ausente — caminho coerente p/ a mensagem de erro do chamador).
     */
    public static function resolve(string $name): string
    {
        $canonical = self::file($name);
        if (is_file($canonical)) {
            return $canonical;
        }

        $legacy = base_path(ltrim($name, '/\\'));

        return is_file($legacy) ? $legacy : $canonical;
    }

    /**
     * Diretório dos menus relativo ao base_path (ex.: `resources/menus`) — para
     * writers que gravam por caminho base-relativo (BuilderCodeSyncService). Cai
     * pro valor cru se o dir estiver fora do projeto.
     */
    public static function relativeDir(): string
    {
        $dir  = str_replace('\\', '/', self::dir());
        $base = str_replace('\\', '/', base_path());

        if (str_starts_with($dir, $base)) {
            return ltrim(substr($dir, \strlen($base)), '/');
        }

        return $dir;
    }
}
