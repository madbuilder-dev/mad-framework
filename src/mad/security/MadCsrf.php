<?php

namespace Mad\Security;

/**
 * MadCsrf — UNIFICADO com o CSRF nativo do Laravel (F2-04).
 *
 * O token agora é o `session()->token()` do próprio Laravel — o mesmo que o
 * VerifyCsrfToken valida. Fluxo:
 *
 *   1. Full-load: as views Blade do casco (resources/views/shell/*) injetam
 *      <meta name="csrf-token" content="{token}"> no <head> (via MadCsrf::token()).
 *   2. mad-livewire.js lê o meta e envia no header X-CSRF-TOKEN de cada POST.
 *   3. O middleware ValidateCsrfToken do Laravel valida o header nativamente;
 *      validateWire() permanece como gate explícito do endpoint reativo
 *      (defesa em profundidade + regra do fluxo anônimo).
 *
 * O token do Laravel é estável por sessão (regenerado só em login/logout via
 * regenerateToken) — sobrevive à navegação parcial que mantém o <head>.
 */
class MadCsrf
{
    /** Token CSRF da sessão Laravel (gera se ausente). */
    public static function token(): string
    {
        $session = app('session');
        if (!$session->token()) {
            $session->regenerateToken();
        }

        return (string) $session->token();
    }

    /**
     * Lê o token apresentado pelo cliente. Ordem: header X-CSRF-TOKEN,
     * POST _token / mad_csrf, header X-XSRF-TOKEN.
     */
    public static function readFromRequest(): string
    {
        $header = static function (string $name): string {
            $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
            return isset($_SERVER[$key]) ? (string) $_SERVER[$key] : '';
        };

        $candidates = [
            $header('X-CSRF-TOKEN'),
            isset($_POST['_token'])   ? (string) $_POST['_token']   : '',
            isset($_POST['mad_csrf']) ? (string) $_POST['mad_csrf'] : '',
            $header('X-XSRF-TOKEN'),
        ];

        foreach ($candidates as $c) {
            if ($c !== '') {
                return $c;
            }
        }

        return '';
    }

    /** Valida o token apresentado contra o da sessão (constant-time). */
    public static function check(?string $provided = null): bool
    {
        $provided = $provided ?? self::readFromRequest();
        $expected = (string) app('session')->token();

        if ($expected === '' || $provided === '') {
            return false;
        }

        return hash_equals($expected, $provided);
    }

    /**
     * Gate CSRF do wire admin. Só EXIGE token para usuário LOGADO — o alvo
     * real de CSRF é a sessão autenticada. Fluxos anônimos que também usam
     * wire (login, cadastro/reset público) passam sem token; o
     * MadComponentHandler ainda valida o estado criptografado.
     */
    public static function validateWire(): bool
    {
        if (!session('logged')) {
            return true;
        }

        return self::check();
    }
}
