<?php

/**
 * Bootstrap de compatibilidade do mad/framework — carregado via composer
 * "files" (antes do boot do Laravel). O resto do boot vive no
 * Mad\MadServiceProvider (PATH, MadBlade, TConnection, tradutores, rota wire).
 */

// No servidor embutido (artisan serve) SCRIPT_NAME vira o path da request
// (/app/X) e RoutingDriver::basePath() derivaria '/app'. No Apache/nginx é
// /index.php e este bloco é inerte. Precisa rodar antes de qualquer leitura.
if (PHP_SAPI === 'cli-server') {
    $_SERVER['SCRIPT_NAME'] = '/index.php';
}

if (!function_exists('mad_app_config')) {
    /**
     * BACKLOG F2-03 — versão com guard do helper de GlobalFunctions.php
     * (o original colide com __()/response() do Laravel).
     */
    function mad_app_config(bool $fresh = false): array
    {
        if ($fresh) {
            \Mad\Core\AppConfig::reset();
        }

        return \Mad\Core\AppConfig::get();
    }
}

if (!function_exists('mad_dump_modal')) {
    /**
     * Mostra variáveis num modal de depuração no navegador, ao fim da ação
     * (botão, evento, Salvar) — o `dd()` das telas, sem interromper a ação.
     *
     *   mad_dump_modal($this->form->getData(), $cliente);
     *   mad_dump_modal('marcador', $valor);   // várias chamadas, um modal só
     *
     * Os valores vão para o buffer de {@see \Mad\Util\MadDumpModal} e saem
     * como op `dump_modal` na resposta da ação. Mesma função de
     * src/mad/util/GlobalFunctions.php — que NÃO é carregado (redefine
     * `__()`/`response()` do Laravel): sem esta cópia, `mdm($x)` num método de
     * tela dava "Call to undefined function mdm()".
     *
     * @param mixed ...$args qualquer número de variáveis
     */
    function mad_dump_modal(...$args): void
    {
        // Chamada direta (sem passar por outro helper): o modal mostra o
        // arquivo e a linha de quem chamou.
        \Mad\Util\MadDumpModal::push($args);
    }
}

if (!function_exists('mdm')) {
    /**
     * Atalho curto de mad_dump_modal().
     *
     *   mdm($email, $dados);
     *
     * @param mixed ...$args qualquer número de variáveis
     */
    function mdm(...$args): void
    {
        \Mad\Util\MadDumpModal::push($args);
    }
}

if (!function_exists('mad_can_access')) {
    /**
     * mad_can_access — o usuário logado tem acesso a um programa (e, opcional, ação)?
     *
     * Fachada enxuta sobre \Mad\Security\PermissionGate::canAccess(): a forma
     * canônica de checar permissão em QUALQUER lugar (view, controller, service)
     * sem tocar na sessão crua — nada de session('programs') / TSession::getValue.
     *
     * Em Blade prefira a diretiva @canAccess('Class') ... @endCanAccess, que
     * delega exatamente para esta função.
     *
     *   if (mad_can_access('NotificationCenter')) { ... }
     *   if (mad_can_access('UserForm', 'onSave')) { ... }
     *
     * @param string      $class  Nome do controller/programa.
     * @param string|null $method Ação (método). Null = só checa o programa.
     */
    function mad_can_access(string $class, ?string $method = null): bool
    {
        return \Mad\Security\PermissionGate::canAccess($class, $method);
    }
}

if (!function_exists('mad_is_unit_admin')) {
    /**
     * mad_is_unit_admin — o usuário logado administra os usuários da unidade ATIVA?
     *
     * Vem da sessão, gravada pelo PermissionResolver a cada login/troca de
     * unidade (`mad_iam_user_unit.unit_admin`): o flag é POR UNIDADE, então o
     * mesmo cadastro pode ser admin numa e usuário comum em outra.
     *
     * Com licenciamento desligado a chave nem existe na sessão => false.
     *
     *   if (mad_is_unit_admin()) { ... }
     */
    function mad_is_unit_admin(): bool
    {
        return session('unit_admin') === 'Y';
    }
}

// ── Aliases globais dos serviços AJAX (P0 do inventário do port) ─────────────
// Os widgets JS montam a URL por NOME DE CLASSE (/app/MadCepService/...) e o
// MadAppController resolve class_exists($class) no namespace global. Aliases
// LAZY via autoloader (não-eager: registrar class_alias direto aqui forçaria o
// load das 6 classes em todo request).
spl_autoload_register(static function (string $class): void {
    static $map = [
        'MadAutoFillService'     => \Mad\Service\MadAutoFillService::class,
        'MadDbComboService'      => \Mad\Service\MadDbComboService::class,
        'MadDbEntryService'      => \Mad\Service\MadDbEntryService::class,
        'MadDbSearchService'     => \Mad\Service\MadDbSearchService::class,
        'MadQuickRegisterService'=> \Mad\Service\MadQuickRegisterService::class,
        'MadCepService'          => \Mad\Service\MadCepService::class,
        'MadCnpjService'         => \Mad\Service\MadCnpjService::class,
    ];
    if (isset($map[$class])) {
        class_alias($map[$class], $class);
    }
});
