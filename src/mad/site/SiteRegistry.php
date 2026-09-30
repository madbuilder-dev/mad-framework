<?php

namespace Mad\Site;

/**
 * SiteRegistry — índice das páginas PÚBLICAS do site (as registradas por
 * {@see \Mad\Routing\MadRoutes::exposeSite()}).
 *
 * O roteador do Laravel sabe QUE a rota existe, mas não sabe o que ela
 * significa para o site: qual é a inicial, quais entram no menu e em que
 * ordem, quais devem ficar fora dos buscadores. Esse significado vive aqui e é
 * consumido por três lugares:
 *
 *   - o componente de navegação (`<mad-site-nav>`) → {@see navItems()};
 *   - o mapa do site (`/sitemap.xml`) → {@see all()} + {@see collectExtraUrls()};
 *   - o `routes/web.php`, que só desvia a raiz para o login quando NÃO há
 *     página inicial de site → {@see hasHome()}.
 *
 * Estado estático: 1 request = 1 registro, montado no carregamento das rotas.
 * {@see reset()} existe para testes e processos PHP de vida longa.
 *
 * Endereços de conteúdo dinâmico (posts do blog, por exemplo) não têm rota
 * própria — cada um entraria como `blog/{slug}`. Quem sabe listá-los registra
 * um provedor com {@see extraUrls()}, chamado só quando o mapa é pedido.
 */
class SiteRegistry
{
    /**
     * @var array<string, array{
     *     path: string, class: string, name: string, kind: string,
     *     home: bool, noindex: bool, nav: array{label: string, order: int, show: bool}|null
     * }>
     */
    private static array $pages = [];

    /** @var array<array-key, callable():iterable<string>> */
    private static array $extraUrlProviders = [];

    /**
     * Registra uma página pública.
     *
     * @param string $path  Caminho SEM barra inicial ('' = inicial).
     * @param string $class Classe da página (subclasse de {@see MadSitePage}).
     * @param array{home?: bool, nav?: array|null, noindex?: bool, kind?: string, name?: string} $opts
     */
    public static function register(string $path, string $class, array $opts = []): void
    {
        $path = trim($path, '/');

        self::$pages[$path] = [
            'path'    => $path,
            'class'   => $class,
            'name'    => (string) ($opts['name'] ?? ($path === '' ? 'site.home' : 'site.' . $path)),
            'kind'    => (string) ($opts['kind'] ?? 'page'),
            'home'    => (bool) ($opts['home'] ?? ($path === '')),
            'noindex' => (bool) ($opts['noindex'] ?? false),
            'nav'     => self::normalizeNav($opts['nav'] ?? null),
        ];
    }

    /** Existe página inicial de site? (decide se a raiz continua indo ao login) */
    public static function hasHome(): bool
    {
        foreach (self::$pages as $page) {
            if ($page['home']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Itens do menu do site, na ordem pedida, só os marcados para aparecer.
     *
     * @return array<int, array{label: string, href: string}>
     */
    public static function navItems(): array
    {
        $itens = [];
        foreach (self::$pages as $page) {
            $nav = $page['nav'];
            if ($nav === null || ! $nav['show'] || $nav['label'] === '') {
                continue;
            }
            // Caminho com parâmetro (blog/{slug}) não vira link de menu: não há
            // endereço concreto para apontar.
            if (str_contains($page['path'], '{')) {
                continue;
            }
            $itens[] = [
                'order' => $nav['order'],
                'label' => $nav['label'],
                'href'  => self::href($page['path']),
            ];
        }

        usort($itens, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        return array_map(
            static fn (array $i): array => ['label' => $i['label'], 'href' => $i['href']],
            $itens
        );
    }

    /**
     * Todas as páginas registradas.
     *
     * `kind` vai junto porque quem monta endereços dinâmicos precisa saber
     * QUAL página é o molde do conteúdo (a de `blog-post`, por exemplo) — sem
     * isso o mapa do site teria que adivinhar pelo caminho.
     *
     * @return array<int, array{path: string, class: string, noindex: bool, name: string, kind: string}>
     */
    public static function all(): array
    {
        return array_values(array_map(
            static fn (array $p): array => [
                'path'    => $p['path'],
                'class'   => $p['class'],
                'noindex' => $p['noindex'],
                'name'    => $p['name'],
                'kind'    => $p['kind'],
            ],
            self::$pages
        ));
    }

    /** Página inicial registrada (ou null). */
    public static function home(): ?array
    {
        foreach (self::$pages as $page) {
            if ($page['home']) {
                return $page;
            }
        }

        return null;
    }

    /**
     * Registra um provedor de endereços extras para o mapa do site (posts do
     * blog, por exemplo). Chamado só quando o mapa é pedido.
     *
     * `$key` marca o provedor com um nome: registrar de novo com o mesmo nome
     * SUBSTITUI o anterior em vez de somar. Quem registra no boot do app
     * (que roda a cada processo, e a cada teste) precisa disso — sem o nome,
     * o mesmo provedor se acumularia e o mapa faria a mesma consulta dezenas
     * de vezes. Sem `$key`, o comportamento é o de sempre: soma.
     *
     * @param callable():iterable<string> $fn
     */
    public static function extraUrls(callable $fn, ?string $key = null): void
    {
        if ($key !== null && $key !== '') {
            self::$extraUrlProviders[$key] = $fn;

            return;
        }

        self::$extraUrlProviders[] = $fn;
    }

    /**
     * Executa os provedores e devolve os caminhos extras, já normalizados.
     * Provedor que estoura nunca derruba o mapa.
     *
     * @return array<int, string>
     */
    public static function collectExtraUrls(): array
    {
        $urls = [];

        foreach (self::$extraUrlProviders as $fn) {
            try {
                foreach ((array) $fn() as $url) {
                    $url = trim((string) $url);
                    if ($url !== '') {
                        $urls[] = $url;
                    }
                }
            } catch (\Throwable) {
                // Um provedor quebrado (tabela ausente, banco fora) não pode
                // derrubar o mapa inteiro do site.
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * Caminhos que o site NÃO pode ocupar (colidiriam com a área interna ou
     * com arquivos servidos). Lido de `config('mad.site.reserved_paths')`.
     *
     * @return array<int, string>
     */
    public static function reservedPaths(): array
    {
        $cfg = null;
        if (function_exists('config')) {
            $cfg = config('mad.site.reserved_paths');
        }

        if (! is_array($cfg) || $cfg === []) {
            $cfg = [
                'app', 'public', 'billing', 'api', 'storage', 'install', 'embed',
                'agent-console', 'lib', 'images', 'fonts', 'sitemap.xml', 'robots.txt', 'docs',
            ];
        }

        return array_values(array_map(static fn ($p): string => trim((string) $p, '/'), $cfg));
    }

    /** Reset explícito — testes e processos de vida longa. */
    public static function reset(): void
    {
        self::$pages             = [];
        self::$extraUrlProviders = [];
    }

    /** Endereço (relativo ao deploy) de um caminho de site. */
    public static function href(string $path): string
    {
        $path = trim($path, '/');

        return function_exists('site_url')
            ? site_url('/' . $path)
            : '/' . $path;
    }

    /**
     * @param  mixed $nav
     * @return array{label: string, order: int, show: bool}|null
     */
    private static function normalizeNav(mixed $nav): ?array
    {
        if (! is_array($nav) || $nav === []) {
            return null;
        }

        return [
            'label' => trim((string) ($nav['label'] ?? '')),
            'order' => (int) ($nav['order'] ?? 0),
            // Quem informou `nav` quer aparecer; `show: false` é o jeito de
            // registrar rótulo/ordem sem entrar no menu ainda.
            'show'  => ! array_key_exists('show', $nav) || (bool) $nav['show'],
        ];
    }
}
