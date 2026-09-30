<?php

namespace Mad\Core;

/**
 * PORT REAL (F1-03) — config da aplicação vinda do config/mad.php (Laravel).
 *
 * Substitui o application.ini/application.php do framework legado. Mesma API estática
 * `get()` que todos os call sites já usam; o conteúdo agora vive no diretório
 * config/ padrão do Laravel (com env() para segredos) e participa do
 * config:cache.
 */
class AppConfig
{
    private static ?array $config = null;

    public static function get(): array
    {
        if (self::$config !== null) {
            return self::$config;
        }

        // Caminho normal: repositório de config do Laravel (respeita config:cache).
        if (function_exists('app') && app()->bound('config')) {
            $cfg = config('mad');
            if (is_array($cfg)) {
                return self::$config = $cfg;
            }
        }

        // Fallback (CLI fora do Laravel, testes isolados): lê o arquivo direto.
        $file = (defined('PATH') ? PATH : dirname(__DIR__, 3)) . '/config/mad.php';

        return self::$config = is_file($file) ? (require $file) : [];
    }

    /**
     * Nome do sistema para MOSTRAR a quem usa (cabeçalho do Copilot IA, Central
     * de Comando, "Meus Dashboards"): `general.title` — o mesmo do <title> das
     * telas e dos e-mails (app.json → MAD_APP_TITLE → APP_NAME).
     *
     * `general.application` é identificador INTERNO (chave das abas salvas,
     * emissor do 2FA) e no esqueleto é o placeholder fixo 'mad_framework': lido
     * como nome, todo app gerado aparecia como "mad_framework". Ele só vale como
     * nome quando o projeto o personalizou e não há título.
     */
    public static function appDisplayName(string $fallback = ''): string
    {
        $general = (array) (self::get()['general'] ?? []);

        $title = trim((string) ($general['title'] ?? ''));
        if ($title !== '') {
            return $title;
        }

        $application = trim((string) ($general['application'] ?? ''));
        if ($application !== '' && $application !== 'mad_framework') {
            return $application;
        }

        return $fallback;
    }

    /** Compat com a API original (init.php chamava load/apply). */
    public static function load(?array $config = null): void
    {
        if (is_array($config)) {
            self::$config = $config;
        }
    }

    public static function reset(): void
    {
        self::$config = null;
    }
}
