<?php

namespace Mad\Site;

/**
 * MadSiteAssets
 *
 * Singleton de estado para o sistema de assets lazy das páginas públicas.
 *
 * Três camadas independentes, ligadas só quando a página pede:
 *
 *   - SITE  → `mad-site.css` + `site.js`: o visual e o comportamento do site
 *     do produto (menu no celular, troca mensal/anual). É o padrão de
 *     {@see MadSitePage::showPage()}.
 *   - DOCS  → `mad-docs.css` + `mad-docs.js`: tipografia/layout do portal de
 *     documentação (a camada antiga, mantida para quem já a usava).
 *   - PAINEL → `mad-ui.css` + a pilha reativa (Alpine/MadWire): só para a
 *     página que declarar `$useMadUi = true`.
 *
 * Uso típico:
 *   1. MadSitePage::showPage() chama enableSite() (e enableMadUi() se pedido).
 *   2. O layout Blade chama renderHead()/renderBody() para injetar os assets
 *      certos antes de fechar head/body.
 *
 * A classe é completamente estática porque o ciclo de vida é 1 request = 1 estado.
 */
class MadSiteAssets
{
    private static bool $siteEnabled = false;
    private static bool $docsEnabled = false;
    private static bool $madUiEnabled = false;

    /** @var string[] HTML extra a injetar no <head> */
    private static array $headExtras = [];

    /** @var string[] HTML extra a injetar no fim do <body> */
    private static array $bodyExtras = [];

    /**
     * Marca que a view precisa do visual do site (mad-site.css + site.js).
     * Idempotente.
     */
    public static function enableSite(): void
    {
        self::$siteEnabled = true;
    }

    /**
     * Marca que a view precisa da tipografia do portal de documentação
     * (mad-docs.css + mad-docs.js). Idempotente.
     */
    public static function enableDocs(): void
    {
        self::$docsEnabled = true;
    }

    /**
     * Marca que a view precisa de mad-ui.js + MadWire + Alpine (componentes
     * reativos do painel reutilizados nas páginas públicas). Implica enableSite()
     * — o visual do site continua valendo por baixo.
     */
    public static function enableMadUi(): void
    {
        self::$madUiEnabled = true;
        self::$siteEnabled  = true;
    }

    /**
     * Injeta HTML extra dentro do <head> (ex: meta tags, CSS de terceiros).
     *
     * @param string $html HTML pré-computado — a classe não escapa, confie na origem.
     */
    public static function pushHead(string $html): void
    {
        self::$headExtras[] = $html;
    }

    /**
     * Injeta HTML extra dentro do fim do <body> (ex: scripts de terceiros).
     */
    public static function pushBody(string $html): void
    {
        self::$bodyExtras[] = $html;
    }

    /**
     * Renderiza o bloco de assets que vai dentro de <head>.
     */
    public static function renderHead(): string
    {
        $out = [];

        if (self::$siteEnabled) {
            $out[] = '<link rel="stylesheet" href="' . site_url('/app/lib/include/builder/ui/mad-site.css') . '">';
        }

        if (self::$docsEnabled) {
            $out[] = '<link rel="stylesheet" href="' . site_url('/app/lib/include/builder/ui/mad-docs.css') . '">';
        }

        if (self::$madUiEnabled) {
            $out[] = '<link rel="stylesheet" href="' . site_url('/app/lib/include/builder/ui/mad-ui.css') . '">';
        }

        if (!empty(self::$headExtras)) {
            $out[] = implode("\n", self::$headExtras);
        }

        return implode("\n", $out);
    }

    /**
     * Renderiza o bloco de scripts que vai no fim do <body>.
     */
    public static function renderBody(): string
    {
        $out = [];

        if (self::$madUiEnabled) {
            // Pilha reativa: Lucide (icones) → mad.js (namespace + fetch) →
            // mad-livewire.js (MadWire global) → mad-ui.js (Alpine components).
            // Ordem importa: defer preserva ordem de execucao apos parse do HTML.
            $out[] = '<script src="https://unpkg.com/lucide@0.468.0/dist/umd/lucide.min.js" defer></script>';
            $out[] = '<script src="' . site_url('/lib/mad/mad.js') . '" defer></script>';
            $out[] = '<script src="' . site_url('/lib/mad/mad-livewire.js') . '" defer></script>';
            $out[] = '<script src="' . site_url('/app/lib/include/builder/ui/mad-ui.js') . '" defer></script>';
        }

        if (self::$docsEnabled) {
            $out[] = '<script src="' . site_url('/app/lib/include/builder/ui/mad-docs.js') . '" defer></script>';
        }

        if (self::$siteEnabled) {
            $out[] = '<script src="' . site_url('/app/lib/include/builder/ui/site.js') . '" defer></script>';
        }

        if (!empty(self::$bodyExtras)) {
            $out[] = implode("\n", self::$bodyExtras);
        }

        return implode("\n", $out);
    }

    /**
     * Reset explícito — útil em testes ou processos PHP de vida longa.
     */
    public static function reset(): void
    {
        self::$siteEnabled  = false;
        self::$docsEnabled  = false;
        self::$madUiEnabled = false;
        self::$headExtras   = [];
        self::$bodyExtras   = [];
    }

    /**
     * Introspecção — útil para debug.
     *
     * @return array<string, mixed>
     */
    public static function state(): array
    {
        return [
            'siteEnabled'  => self::$siteEnabled,
            'docsEnabled'  => self::$docsEnabled,
            'madUiEnabled' => self::$madUiEnabled,
            'headExtras'   => self::$headExtras,
            'bodyExtras'   => self::$bodyExtras,
        ];
    }
}
