<?php

namespace Mad\Routing;

use Mad\Core\AppConfig;

/**
 * RoutingDriver — roteamento web do framework.
 *
 * O modo de navegação é ÚNICO: web ESTRITO. A UI busca conteúdo e o ciclo
 * reativo (wire) no arquivo de rotas app/routes/web.php (prefixo /app,
 * configurável em [routing] prefix do config/mad.php), com middleware de
 * autenticação + permissão. O dispatch direto por class/method nos entry
 * points antigos é BLOQUEADO (403) via legacyDispatchBlocked() — só rotas
 * declaradas valem. Os modos "classic"/"hybrid" foram REMOVIDOS.
 *
 * URLs em formato interno class=&method= ainda circulam como TOKEN entre
 * PHP e client — a tradução para /app/* acontece na borda AJAX, no client
 * (lib/mad/mad-web-routing.js), e no endpoint reativo dos MadComponents
 * (MadComponent::_wireEndpoint).
 */
class RoutingDriver
{
    /** Cache da config [routing] resolvida (por request). */
    private static ?array $cache = null;

    /** Retorna a seção routing do config/mad.php (array, possivelmente vazio). */
    public static function config(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $ini = \Mad\Core\AppConfig::get();
        $routing = (is_array($ini) && isset($ini['routing']) && is_array($ini['routing']))
            ? $ini['routing']
            : [];

        return self::$cache = $routing;
    }

    /** Limpa o cache (testes). */
    public static function reset(): void
    {
        self::$cache = null;
    }

    /**
     * Driver ativo: sempre 'web' (ESTRITO — só rotas declaradas em
     * app/routes/web.php). Os modos 'classic' e 'hybrid' foram removidos;
     * qualquer valor em [routing] driver é ignorado.
     */
    public static function driver(): string
    {
        return 'web';
    }

    /** Sempre true — roteamento web (rewrite /app/* + wire) é o único modo. */
    public static function isWeb(): bool
    {
        return true;
    }

    /** Sempre true — web ESTRITO é o único modo (dispatch antigo bloqueado). */
    public static function isStrictWeb(): bool
    {
        return true;
    }

    /**
     * Guarda do dispatch por query (?class=): qualquer request
     * carregando `class` é recusado (403) — só rotas declaradas valem. Retorna
     * true se bloqueou (já emitiu o 403); o caller deve então parar
     * (exit/return).
     */
    public static function legacyDispatchBlocked(): bool
    {
        if (!self::isStrictWeb()) {
            return false;
        }
        if (!isset($_REQUEST['class']) || $_REQUEST['class'] === '') {
            return false;
        }
        if (!headers_sent()) {
            http_response_code(403);
            header('Content-Type: text/html; charset=utf-8');
        }
        echo self::legacyBlockedHtml();
        return true;
    }

    /** HTML 403 do guard legado. */
    private static function legacyBlockedHtml(): string
    {
        $base = htmlspecialchars(self::appBase(), ENT_QUOTES);
        return '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8">'
             . '<title>403</title></head>'
             . '<body style="font-family:system-ui;max-width:680px;margin:80px auto;padding:0 16px;">'
             . '<h1 style="color:#ef4444;margin-bottom:4px">403</h1>'
             . '<h2 style="margin-top:0">Dispatch direto por class/method bloqueado</h2>'
             . '<p>O modo de navegação <code>web</code> aceita <b>apenas rotas declaradas</b> '
             . '(<code>' . $base . '/…</code> em <code>app/routes/web.php</code>). '
             . 'Chamar uma URL com <code>?class=…</code> direto não é permitido.</p>'
             . '<p>Use a rota amigável correspondente declarada em '
             . '<code>app/routes/web.php</code>.</p>'
             . '</body></html>';
    }

    /** Prefixo das rotas web, com leading slash e sem trailing slash. Default '/app'. */
    public static function prefix(): string
    {
        $cfg = self::config();
        $prefix = trim((string) ($cfg['prefix'] ?? '/app'));
        if ($prefix === '') {
            $prefix = '/app';
        }
        return '/' . trim($prefix, '/');
    }

    /**
     * Diretório base da aplicação (para funcionar em subpastas em dev e prod).
     * Mesmo truque do MadSitePage::siteWireEndpoint — usa SCRIPT_NAME.
     */
    public static function basePath(): string
    {
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $dir = str_replace('\\', '/', dirname($script));
        if ($dir === '/' || $dir === '.' || $dir === '\\') {
            $dir = '';
        }
        return rtrim($dir, '/');
    }

    /** Base absoluta das rotas web: <basePath><prefix> (ex: '/app' ou '/erp/app'). */
    public static function appBase(): string
    {
        return self::basePath() . self::prefix();
    }

    /** Endpoint reativo (wire) do modo web: <appBase>/_mad-wire. */
    public static function wireEndpoint(): string
    {
        return self::appBase() . '/_mad-wire';
    }

    /**
     * Tags <script> injetadas no casco em modo web: flag routingDriver +
     * appBase + o carregador mad-web-routing.js (que reescreve as URLs AJAX
     * para /app/* e carimba o header X-Mad-Partial). Usado por index.php e
     * pelo shell renderizado em navegação direta (AppRouteResolver).
     */
    public static function bootScripts(): string
    {
        $appBase    = self::appBase();
        $scriptBase = self::basePath();

        return '<script>window.MadShell=window.MadShell||{};'
             . 'window.MadShell.routingDriver="web";'
             . 'window.MadShell.appBase=' . json_encode($appBase) . ';</script>'
             . '<script src="' . htmlspecialchars(
                 \Mad\Support\MadFramework::asset($scriptBase . '/lib/mad/mad-web-routing.js'),
                 ENT_QUOTES
             ) . '"></script>';
    }
}
