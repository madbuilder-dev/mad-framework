<?php

namespace Mad\Routing;


/**
 * MadNavigation — navegação server-side ciente do chaveador de rotas.
 *
 * Substituto MAD para o loadPage/gotoPage do dispatcher legado nos entry
 * points (index.php). Monta a URL legada (class=&method=) via buildHttpQuery
 * e ASSA a rota amigável /app/* no servidor
 * (AppRouteResolver::toFriendlyUrl) antes de emitir o JS — assim o load
 * inicial (LoginForm, public_entry, deep-link) cai numa rota declarada e não
 * no dispatch legado bloqueado (403) do modo web ESTRITO.
 *
 * Sem AppRouteResolver (CLI/instalador), bake() é no-op e a URL passa
 * (URLs em formato de query ?class=… viram /app/* antes de chegar no client).
 *
 * No client, __mad_load_page recebe a URL já amigável e o shim do
 * mad-web-routing.js a roteia pro Mad.navigate (fetch X-Mad-Partial +
 * inject + pushState); __mad_goto_page faz window.location direto e o
 * AppRouteResolver devolve o shell completo na navegação direta.
 */
class MadNavigation
{
    /**
     * Converte uma URL em formato de query interna (?class=X&method=Y&…) na
     * rota amigável /app/*. URL sem `class=` passa intacta.
     */
    public static function bake(string $url): string
    {
        return \Mad\Routing\MadRoutes::toFriendlyUrl($url);
    }

    /**
     * Carrega uma página via AJAX (equivalente MAD do
     * loadPage do dispatcher legado), com a rota amigável assada em modo web.
     *
     * @param string      $class      Classe do controller.
     * @param string|null $method     Método (opcional).
     * @param array|null  $parameters Parâmetros extras (opcional).
     */
    public static function loadPage(string $class, ?string $method = null, ?array $parameters = null): void
    {
        $query = self::buildHttpQuery($class, $method, $parameters);
        $query = self::bake($query);

        // TScript legado era no-op no port — navegação dinâmica via MadResponse.
    }

    /**
     * Navegação completa (window.location) — equivalente MAD do
     * gotoPage do dispatcher legado, com a rota amigável assada em modo web.
     *
     * @param string      $class      Classe do controller.
     * @param string|null $method     Método (opcional).
     * @param array|null  $parameters Parâmetros extras (opcional).
     */
    public static function gotoPage(string $class, ?string $method = null, ?array $parameters = null): void
    {
        unset($parameters['static']);
        $query = self::buildHttpQuery($class, $method, $parameters);
        $query = self::bake($query);

        // Idem loadPage: navegação via MadResponse->redirect.
    }

    /** Gera URL legada index.php?class=... (ex-buildHttpQuery do dispatcher legado). */
    private static function buildHttpQuery($class, $method = null, $params = []): string
    {
        $query = ['class' => $class];
        if ($method) {
            $query['method'] = $method;
        }
        unset($params['class'], $params['method']);

        return 'index.php?' . http_build_query(array_merge($query, $params));
    }
}
