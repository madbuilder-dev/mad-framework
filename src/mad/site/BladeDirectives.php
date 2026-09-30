<?php

namespace Mad\Site;

use Mad\View\MadBlade;

/**
 * BladeDirectives do MAD Site
 *
 * Registra as diretivas @site, @sitedocs, @sitehead, @sitebody no BladeOne via
 * MadBlade::directive() — mesmo padrão usado por Mad\Web\BladeDirectives
 * (@csrf/@auth/@error/etc). São classes separadas e complementares.
 *
 * Chamada pelo MadServiceProvider::bootBlade(), depois de
 * Mad\Web\BladeDirectives::register().
 */
class BladeDirectives
{
    private static bool $registered = false;

    /**
     * Registra as diretivas no BladeOne via MadBlade::directive().
     * Idempotente — seguro chamar múltiplas vezes.
     */
    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        // @site — ativa o visual do site (mad-site.css + site.js). Sem argumentos.
        MadBlade::directive('site', function (string $expr): string {
            return '<?php \\Mad\\Site\\MadSiteAssets::enableSite(); ?>';
        });

        // @sitedocs — ativa a tipografia do portal de documentação
        // (mad-docs.css + mad-docs.js). Sem argumentos.
        MadBlade::directive('sitedocs', function (string $expr): string {
            return '<?php \\Mad\\Site\\MadSiteAssets::enableDocs(); ?>';
        });

        // @sitehead('<meta ...>') — injeta HTML no <head> via MadSiteAssets.
        MadBlade::directive('sitehead', function (string $expr): string {
            return '<?php \\Mad\\Site\\MadSiteAssets::pushHead(' . $expr . '); ?>';
        });

        // @sitebody('<script>...</script>') — injeta HTML no fim do <body>.
        MadBlade::directive('sitebody', function (string $expr): string {
            return '<?php \\Mad\\Site\\MadSiteAssets::pushBody(' . $expr . '); ?>';
        });
    }

    public static function isRegistered(): bool
    {
        return self::$registered;
    }

    public static function reset(): void
    {
        self::$registered = false;
    }
}
