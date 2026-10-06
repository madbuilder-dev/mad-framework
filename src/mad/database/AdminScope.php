<?php

namespace Mad\Database;

/**
 * AdminScope — "o administrador do dono do app vê todas as empresas".
 *
 * Opção `mad.tenant.admin_scope` (env MAD_TENANT_ADMIN_SCOPE, painel Tenancy &
 * Banco do MadBuilder): 'own' (padrão) = o administrador é separado por empresa
 * e por unidade como qualquer usuário; 'all' = a LEITURA dele (listagens,
 * combos, filtros, dashboards) deixa de filtrar por empresa (`tenant_id`, Pool)
 * e por unidade (`unit_id`, Multi-unidade e o filtro por unidade gerado nos
 * models). Uma opção só cobre os dois eixos: a unidade pertence a uma empresa,
 * então tirar só o filtro de empresa deixaria o filtro da unidade ativa
 * escondendo as outras empresas do mesmo jeito.
 *
 * Só leitura amplia. A gravação continua carimbando a empresa/unidade ATIVA
 * (TenantContext::id() / UnitContext::id() não mudam), e um registro de outra
 * empresa editado por ele mantém a empresa e a unidade dele.
 *
 * ┌─ Quem é "o administrador do dono do app" ──────────────────────────────┐
 * │ login `admin` (o da Central de Comando) E membro do grupo Administrador │
 * │ (id 1) — os DOIS. Nenhum dos dois sozinho basta: o login é só um texto  │
 * │ do cadastro (único só depois da migration da 5.122.1), e o grupo é      │
 * │ configurável (o dono pode pô-lo nos grupos padrão do auto-cadastro).    │
 * │ Regra única em Mad\Security\OwnerAdmin.                                 │
 * │ E nunca `unit_admin` (administrador da unidade = administrador de um    │
 * │ CLIENTE no SaaS).                                                       │
 * │ Com o Licenciamento (SaaS) ligado a opção é IGNORADA (fail-closed): lá  │
 * │ clientes administram os próprios cadastros, e o app não tem como provar │
 * │ que uma conta da sessão é do dono e não de um cliente.                  │
 * └────────────────────────────────────────────────────────────────────────┘
 *
 * Continuam ESTRITOS (empresa/unidade ativa), de propósito:
 *  - todo request autenticado por token Bearer: MCP, Chat IA, Copilot, Central
 *    de Comando e API REST (o McpManifestAuthMiddleware ainda pede strict(); o
 *    gateway cru do MCP usa TenantContext::id()/UnitContext::id());
 *  - qualquer código que fixa o contexto (TenantContext::set() /
 *    UnitContext::set(): API REST, jobs, console, testes);
 *  - PDV (pede leitura estrita com strict());
 *  - filtros salvos da grid, painéis de conciliação, `apply-unit-filter`, SQL
 *    de widget do assistente — leem o id da empresa/unidade ATIVA.
 *
 * Com a opção desligada nada muda: readsAll() devolve false antes de olhar a
 * sessão, e SQL, carimbo e chaves de cache ficam byte a byte os de antes.
 */
final class AdminScope
{
    public const OWN = 'own';
    public const ALL = 'all';

    /** Login da conta do dono — a regra mora em {@see \Mad\Security\OwnerAdmin}. */
    public const ADMIN_LOGIN = \Mad\Security\OwnerAdmin::LOGIN;

    /** Grupo "Administrador" do IAM — idem. */
    public const ADMIN_GROUP_ID = \Mad\Security\OwnerAdmin::GROUP_ID;

    private const STRICT_BINDING = 'mad.admin_scope.strict';

    /** 'all' só com o valor exato; qualquer outro (inclusive vazio) = 'own'. */
    public static function mode(): string
    {
        try {
            $v = function_exists('config') ? config('mad.tenant.admin_scope', self::OWN) : self::OWN;
        } catch (\Throwable) {
            $v = self::OWN;
        }

        return (string) $v === self::ALL ? self::ALL : self::OWN;
    }

    /**
     * A LEITURA deste request ignora o filtro de empresa/unidade? Só quando a
     * opção está ligada, o usuário é o administrador do dono, o SaaS está
     * desligado e nada pediu leitura estrita. Nunca lança (na dúvida, false).
     */
    public static function readsAll(): bool
    {
        if (self::mode() !== self::ALL) {
            return false; // opção desligada: nem olha a sessão
        }

        try {
            if (TenantContext::overridden() || UnitContext::overridden()) {
                return false; // job, console, API REST, teste: contexto fixado
            }
            if (self::strictRequested()) {
                return false; // PDV, MCP (o middleware do token pede)
            }
            if (self::bearerRequest()) {
                return false; // MCP, Chat IA, Copilot, Central de Comando, API REST
            }
            if (self::saas()) {
                return false; // fail-closed: SaaS ligado
            }

            return self::isOwnerAdmin();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * O usuário da sessão é o administrador do dono do app: login `admin` E
     * grupo Administrador ({@see \Mad\Security\OwnerAdmin::session()}, a
     * mesma regra da Central de Comando e do GED), e não é administrador de
     * unidade. Sessão sem a lista de grupos (token JWT, API) não passa.
     */
    public static function isOwnerAdmin(): bool
    {
        if ((string) self::session('unit_admin') === 'Y') {
            return false;
        }

        return \Mad\Security\OwnerAdmin::session();
    }

    /**
     * Licenciamento (SaaS) ligado? Qualquer uma das fontes basta — preferência
     * do app (vence no AppConfigService) OU config/env. Na dúvida: ligado.
     */
    public static function saas(): bool
    {
        try {
            if ((string) config('mad.general.licensing', '0') === '1') {
                return true;
            }
            if (class_exists(\App\Service\Iam\AppConfigService::class)
                && method_exists(\App\Service\Iam\AppConfigService::class, 'licensing')) {
                return (bool) \App\Service\Iam\AppConfigService::licensing();
            }

            return false;
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * Pede leitura estrita (empresa/unidade ativa) no restante deste request —
     * o PDV chama antes de ler produto/cliente: vender no caixa da empresa A
     * nunca pode listar o estoque da empresa B. Escopo de request (binding
     * `scoped`: o Octane e o worker de fila descartam a cada request/job).
     */
    public static function strict(): void
    {
        $holder = self::strictHolder();
        if ($holder !== null) {
            $holder->on = true;
        }
    }

    public static function strictRequested(): bool
    {
        $holder = self::strictHolder();

        return $holder !== null && $holder->on;
    }

    private static function strictHolder(): ?object
    {
        try {
            if (! function_exists('app')) {
                return null;
            }
            $app = app();
            if (! $app->bound(self::STRICT_BINDING)) {
                $app->scoped(self::STRICT_BINDING, static fn () => new class {
                    public bool $on = false;
                });
            }

            return $app->make(self::STRICT_BINDING);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * O request corrente se autentica por token Bearer? As telas do app usam a
     * sessão; quem chega com Bearer é o agente (MCP, chat, Copilot, Central) ou
     * a API. Lido do request (e não de um estático como o McpCurrentUser, que
     * sobreviveria ao request num worker persistente).
     */
    private static function bearerRequest(): bool
    {
        if (! function_exists('app') || ! app()->bound('request')) {
            return false;
        }
        $request = app('request');

        return $request instanceof \Illuminate\Http\Request && (string) $request->bearerToken() !== '';
    }

    /** Leitura de sessão que não lança fora de request (console, testes sem kernel). */
    private static function session(string $key): mixed
    {
        try {
            return (function_exists('session') && app()->bound('session')) ? session($key) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
