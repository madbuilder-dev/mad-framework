<?php

namespace Mad\Help;

/**
 * ManualRepository — leitura do manual do sistema (a "Ajuda" do app).
 *
 * O MadBuilder grava o manual em `resources/manual/` (no deploy e pela Central
 * de Comando › Manual):
 *
 *   resources/manual/manual.json          índice (seções, páginas, telas, texto da busca)
 *   resources/manual/<path>.md            página publicada (front-matter + Markdown)
 *   resources/manual/prints/<nome>.webp   imagens citadas pelas páginas
 *
 * Só lê: quem grava é o MadBuilder. Caminho de página e nome de print passam por
 * allowlist (sem `..`, sem barra invertida, sem link simbólico para fora da
 * pasta) — o path chega pela URL.
 *
 * A pasta vem de `config('mad.manual.path')` (padrão `resources/manual`).
 */
final class ManualRepository
{
    /** Teto do manual.json lido (o índice traz o texto plano de todas as páginas). */
    private const MAX_INDEX_BYTES = 16 * 1024 * 1024;

    /** Segmento de path de página: minúsculas, dígitos, `-` e `_`. */
    private const PATH_RE = '#^[a-z0-9][a-z0-9_-]*(?:/[a-z0-9][a-z0-9_-]*)*$#';

    /** Nome de print servido. */
    private const PRINT_RE = '/^[A-Za-z0-9][A-Za-z0-9._-]*\.webp$/';

    /** @var array<string, array{mtime:int, size:int, index:?array}> cache por pasta (request) */
    private static array $cache = [];

    private string $root;

    public function __construct(?string $root = null)
    {
        $root = $root ?? (string) (config('mad.manual.path') ?: resource_path('manual'));
        $this->root = rtrim($root, '/');
    }

    public static function make(): self
    {
        return new self();
    }

    /** Limpa o cache do índice (testes e depois de puxar o manual). */
    public static function flush(): void
    {
        self::$cache = [];
    }

    public function root(): string
    {
        return $this->root;
    }

    /** Há manual publicado (índice válido com pelo menos uma página)? */
    public function exists(): bool
    {
        $index = $this->index();

        return $index !== null && $index['pages'] !== [];
    }

    /**
     * Índice normalizado do `manual.json` (formato do contrato §4), ou null
     * quando falta, está corrompido ou passa do teto.
     *
     * @return array{v:int, manual_version:string, generated_at:string, sections:array, pages:array}|null
     */
    public function index(): ?array
    {
        $file = $this->root . '/manual.json';
        clearstatcache(true, $file);
        if (!is_file($file) || is_link($file)) {
            return null;
        }
        $mtime = (int) @filemtime($file);
        $size  = (int) @filesize($file);
        $hit   = self::$cache[$this->root] ?? null;
        if ($hit !== null && $hit['mtime'] === $mtime && $hit['size'] === $size) {
            return $hit['index'];
        }

        $index = null;
        if ($size > 0 && $size <= self::MAX_INDEX_BYTES) {
            $raw = json_decode((string) @file_get_contents($file), true);
            if (is_array($raw)) {
                $index = self::normalize($raw);
            }
        }
        self::$cache[$this->root] = ['mtime' => $mtime, 'size' => $size, 'index' => $index];

        return $index;
    }

    /** @return list<array> páginas do índice, na ordem de seção e posição */
    public function pages(): array
    {
        $out = [];
        foreach ($this->tree() as $section) {
            foreach ($section['pages'] as $page) {
                $out[] = $page;
            }
        }

        return $out;
    }

    /**
     * Árvore por seção: seções na `position` do índice (as que só aparecem nas
     * páginas vão para o fim, em ordem alfabética); páginas por `position` e título.
     *
     * @return list<array{name:string, position:int, pages:list<array>}>
     */
    public function tree(): array
    {
        $index = $this->index();
        if ($index === null) {
            return [];
        }

        $sections = [];
        foreach ($index['sections'] as $s) {
            $sections[$s['name']] = ['name' => $s['name'], 'position' => $s['position'], 'pages' => []];
        }
        foreach ($index['pages'] as $page) {
            $name = $page['section'];
            if (!isset($sections[$name])) {
                $sections[$name] = ['name' => $name, 'position' => PHP_INT_MAX, 'pages' => []];
            }
            $sections[$name]['pages'][] = $page;
        }

        $sections = array_values(array_filter($sections, static fn ($s) => $s['pages'] !== []));
        usort($sections, static fn ($a, $b) => [$a['position'], self::sortKey($a['name'])] <=> [$b['position'], self::sortKey($b['name'])]);
        foreach ($sections as &$s) {
            usort($s['pages'], static fn ($a, $b) => [$a['position'], self::sortKey($a['title'])] <=> [$b['position'], self::sortKey($b['title'])]);
        }
        unset($s);

        return $sections;
    }

    /** Página do índice pelo path; null se o path é inválido ou não existe. */
    public function find(string $path): ?array
    {
        $path = trim($path, '/');
        if (!self::isSafePath($path)) {
            return null;
        }
        foreach ($this->index()['pages'] ?? [] as $page) {
            if ($page['path'] === $path) {
                return $page;
            }
        }

        return null;
    }

    /** Página anterior e seguinte na ordem da árvore (navegação no rodapé). */
    public function neighbours(string $path): array
    {
        $pages = $this->pages();
        foreach ($pages as $i => $page) {
            if ($page['path'] === $path) {
                return ['prev' => $pages[$i - 1] ?? null, 'next' => $pages[$i + 1] ?? null];
            }
        }

        return ['prev' => null, 'next' => null];
    }

    /** Caminho absoluto do `.md` da página (validado), ou null. */
    public function markdownFile(string $path): ?string
    {
        $path = trim($path, '/');
        if (!self::isSafePath($path)) {
            return null;
        }

        return $this->insideRoot($this->root . '/' . $path . '.md');
    }

    /**
     * Páginas da Ajuda da tela (botão **?**): `screens` contém a classe e a
     * página não desligou a ajuda da tela (`context_help`).
     *
     * @return list<array>
     */
    public function forScreen(string $class): array
    {
        $class = self::screenName($class);
        if ($class === '') {
            return [];
        }

        return array_values(array_filter(
            $this->pages(),
            static fn ($p) => $p['context_help'] && in_array($class, $p['screens'], true)
        ));
    }

    /**
     * Busca no texto plano do índice (título, palavras-chave, títulos internos
     * e texto). Sem acento e sem caixa; todas as palavras precisam aparecer.
     *
     * @return list<array{path:string, title:string, section:string, snippet:string, score:int}>
     */
    public function search(string $query, int $limit = 20): array
    {
        $terms = array_values(array_filter(
            preg_split('/\s+/u', self::fold(trim($query))) ?: [],
            static fn ($t) => mb_strlen($t) >= 2
        ));
        if ($terms === []) {
            return [];
        }

        $hits = [];
        foreach ($this->pages() as $page) {
            $title    = self::fold($page['title']);
            $keywords = self::fold(implode(' ', $page['keywords']));
            $heads    = self::fold(implode(' ', array_column($page['headings'], 'text')));
            $text     = self::fold($page['text']);
            $score    = 0;
            foreach ($terms as $t) {
                $s = 0;
                if (str_contains($title, $t))    { $s += 10; }
                if (str_contains($keywords, $t)) { $s += 6; }
                if (str_contains($heads, $t))    { $s += 4; }
                if (str_contains($text, $t))     { $s += 1 + min(4, substr_count($text, $t)); }
                if ($s === 0) {
                    continue 2; // todas as palavras precisam aparecer
                }
                $score += $s;
            }
            $hits[] = [
                'path'    => $page['path'],
                'title'   => $page['title'],
                'section' => $page['section'],
                'snippet' => self::snippet($page['text'], $terms),
                'score'   => $score,
            ];
        }

        usort($hits, static fn ($a, $b) => [$b['score'], $a['title']] <=> [$a['score'], $b['title']]);

        return array_slice($hits, 0, max(1, $limit));
    }

    /**
     * Arquivo do print (absoluto) para a rota `/app/ajuda/prints/{arquivo}`:
     * só `prints/*.webp` com nome seguro, arquivo comum e dentro da pasta.
     */
    public function printFile(string $name): ?string
    {
        if (!self::isSafePrintName($name)) {
            return null;
        }
        $file = $this->root . '/prints/' . $name;
        if (is_link($file)) {
            return null;
        }

        return $this->insideRoot($file, $this->root . '/prints');
    }

    /**
     * Classe da tela para o botão ? do `<mad-page-header>` — '' quando o botão
     * não deve aparecer: sem manual publicado, sem login, sem tela dona do
     * cabeçalho ou na própria Ajuda. Nunca lança (o cabeçalho não pode quebrar).
     */
    public static function headerButtonScreen(?object $owner): string
    {
        try {
            if ($owner === null || !session('logged')) {
                return '';
            }
            $screen = self::screenName(get_class($owner));
            if ($screen === '' || $screen === 'HelpCenter' || !(new self())->exists()) {
                return '';
            }

            return $screen;
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Marcadores numerados de um print (`{n, x_pct, y_pct, label, field?}`):
     * n inteiro 1..999, x/y numéricos entre 0 e 100, rótulo em texto (até 200).
     * Marcador fora disso é descartado — vira HTML posicionado na figura.
     *
     * @return list<array{n:int, x_pct:float, y_pct:float, label:string, field:string}>
     */
    public static function normalizeMarkers(array $raw): array
    {
        $out = [];
        foreach ($raw as $m) {
            if (!is_array($m)) {
                continue;
            }
            $n = $m['n'] ?? null;
            $x = $m['x_pct'] ?? null;
            $y = $m['y_pct'] ?? null;
            if (!(is_int($n) || (is_string($n) && ctype_digit($n)) || (is_float($n) && floor($n) === $n))
                || !is_numeric($x) || !is_numeric($y)) {
                continue;
            }
            $n = (int) $n;
            $x = (float) $x;
            $y = (float) $y;
            if ($n < 1 || $n > 999 || !is_finite($x) || !is_finite($y) || $x < 0 || $x > 100 || $y < 0 || $y > 100) {
                continue;
            }
            $label = is_scalar($m['label'] ?? null) ? trim((string) $m['label']) : '';
            $field = is_scalar($m['field'] ?? null) ? trim((string) $m['field']) : '';
            $out[] = [
                'n'     => $n,
                'x_pct' => $x,
                'y_pct' => $y,
                'label' => mb_substr($label, 0, 200),
                'field' => mb_substr($field, 0, 120),
            ];
        }

        return $out;
    }

    /**
     * Endereço da Ajuda no idioma ativo (ex.: /app/ajuda, /erp/app/help).
     * Rota não registrada (app sem a tela) → `/app/ajuda` como fallback.
     */
    public static function baseUrl(): string
    {
        try {
            $url = rtrim(\Mad\Routing\MadRoutes::urlFor('HelpCenter'), '/');
        } catch (\Throwable) {
            $url = '';
        }

        return $url !== '' ? $url : rtrim(\Mad\Routing\RoutingDriver::basePath(), '/') . '/app/ajuda';
    }

    /** Endereço de uma página da Ajuda. */
    public static function pageUrl(string $path): string
    {
        return self::baseUrl() . '/' . trim($path, '/');
    }

    public static function isSafePath(string $path): bool
    {
        return strlen($path) <= 200 && preg_match(self::PATH_RE, $path) === 1;
    }

    public static function isSafePrintName(string $name): bool
    {
        return strlen($name) <= 160 && preg_match(self::PRINT_RE, $name) === 1 && !str_contains($name, '..');
    }

    /** Classe da tela sem namespace (App\Control\Cad\FeriadoForm → FeriadoForm). */
    public static function screenName(string $class): string
    {
        $class = trim(str_replace('/', '\\', $class), '\\ ');
        $pos   = strrpos($class, '\\');
        $base  = $pos === false ? $class : substr($class, $pos + 1);

        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $base) ? $base : '';
    }

    // ── internos ────────────────────────────────────────────────────────────

    /** Arquivo comum dentro de $base (padrão: a pasta do manual), ou null. */
    private function insideRoot(string $file, ?string $base = null): ?string
    {
        $base = realpath($base ?? $this->root);
        $real = is_file($file) ? realpath($file) : false;
        if ($base === false || $real === false) {
            return null;
        }

        return str_starts_with($real, $base . DIRECTORY_SEPARATOR) ? $real : null;
    }

    /** Normaliza o índice cru: tipos certos, path inválido fora, sem duplicata. */
    private static function normalize(array $raw): array
    {
        $sections = [];
        foreach ((array) ($raw['sections'] ?? []) as $i => $s) {
            if (!is_array($s) || trim((string) ($s['name'] ?? '')) === '') {
                continue;
            }
            $name = trim((string) $s['name']);
            $sections[$name] = ['name' => $name, 'position' => (int) ($s['position'] ?? ($i + 1))];
        }

        $pages = [];
        foreach ((array) ($raw['pages'] ?? []) as $p) {
            if (!is_array($p)) {
                continue;
            }
            $path = trim((string) ($p['path'] ?? ''), '/');
            if (!self::isSafePath($path) || isset($pages[$path])) {
                continue;
            }
            $title = trim((string) ($p['title'] ?? ''));
            $headings = [];
            foreach ((array) ($p['headings'] ?? []) as $h) {
                if (is_array($h) && trim((string) ($h['text'] ?? '')) !== '') {
                    $headings[] = [
                        'level' => (int) ($h['level'] ?? 2),
                        'text'  => trim((string) $h['text']),
                        'id'    => (string) ($h['id'] ?? ''),
                    ];
                }
            }
            $prints = [];
            foreach ((array) ($p['prints'] ?? []) as $pr) {
                if (is_array($pr) && self::isSafePrintName((string) ($pr['name'] ?? ''))) {
                    $prints[] = [
                        'name'    => (string) $pr['name'],
                        'width'   => (int) ($pr['width'] ?? 0),
                        'height'  => (int) ($pr['height'] ?? 0),
                        'markers' => self::normalizeMarkers((array) ($pr['markers'] ?? [])),
                    ];
                }
            }
            $pages[$path] = [
                'path'         => $path,
                'title'        => $title !== '' ? $title : $path,
                'section'      => trim((string) ($p['section'] ?? '')) ?: 'Geral',
                'position'     => (int) ($p['position'] ?? 0),
                'screens'      => array_values(array_filter(array_map(
                    static fn ($s) => self::screenName((string) $s),
                    (array) ($p['screens'] ?? [])
                ))),
                'context_help' => !array_key_exists('context_help', $p) || (bool) $p['context_help'],
                'keywords'     => array_values(array_filter(array_map('strval', (array) ($p['keywords'] ?? [])), static fn ($k) => trim($k) !== '')),
                'version'      => (int) ($p['version'] ?? 0),
                'headings'     => $headings,
                'text'         => mb_substr((string) ($p['text'] ?? ''), 0, 20000),
                'prints'       => $prints,
            ];
        }

        return [
            'v'              => (int) ($raw['v'] ?? 1),
            'manual_version' => (string) ($raw['manual_version'] ?? ''),
            'generated_at'   => (string) ($raw['generated_at'] ?? ''),
            'sections'       => array_values($sections),
            'pages'          => array_values($pages),
        ];
    }

    private static function sortKey(string $s): string
    {
        return self::fold($s);
    }

    /** Minúsculo e sem acento. */
    public static function fold(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        if (class_exists(\Normalizer::class)) {
            $n = \Normalizer::normalize($s, \Normalizer::FORM_D);
            if (is_string($n)) {
                return preg_replace('/\p{Mn}+/u', '', $n) ?? $s;
            }
        }

        return strtr($s, [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'ê' => 'e', 'è' => 'e',
            'í' => 'i', 'î' => 'i', 'ó' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o', 'ú' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n',
        ]);
    }

    /** Trecho do texto em volta da 1ª palavra achada (até ~180 caracteres). */
    private static function snippet(string $text, array $terms): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($text === '') {
            return '';
        }
        $folded = self::fold($text);
        $pos = null;
        foreach ($terms as $t) {
            $p = mb_strpos($folded, $t);
            if ($p !== false && ($pos === null || $p < $pos)) {
                $pos = $p;
            }
        }
        $start = max(0, ($pos ?? 0) - 60);
        $piece = mb_substr($text, $start, 180);

        return ($start > 0 ? '…' : '') . $piece . (mb_strlen($text) > $start + 180 ? '…' : '');
    }
}
