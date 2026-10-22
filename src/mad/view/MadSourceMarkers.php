<?php
namespace Mad\View;

/**
 * Marcadores de origem do Modo Vibe (MAD_SOURCE_MARKERS=1, só no Teste Online).
 *
 * Passo 0 do MadBladeCompiler::compileString: antes de qualquer precompiler,
 * cada tag de abertura `<mad-*>` real da view ganha um par de comentários HTML
 *
 *   <!--mad:s{n}@{viewKey}--><mad-x ...>...</mad-x><!--mad:e{n}@{viewKey}-->
 *
 * em que `n` é o ordinal da abertura no texto da view (1, 2, 3…). O Studio conta
 * as aberturas do mesmo jeito sobre o Blade que ele carrega e liga o ordinal ao
 * nó do IR — é assim que um clique no app real chega à tag que o gerou.
 *
 * Regras de contagem (espelhadas no lado da plataforma — mudar aqui é mudar lá):
 *  - conta toda abertura `<mad-NOME` (inclusive autofechada); fechamento não conta;
 *  - ignora o que está dentro de `{{-- --}}`, `@verbatim…@endverbatim`,
 *    `@php…@endphp` (forma de bloco), `<script>…</script>` e `<style>…</style>`;
 *  - dentro de host opaco (OPAQUE_HOSTS), os descendentes contam mas não recebem
 *    marcador: o precompiler do host lê os filhos e um comentário no meio quebra
 *    ou some.
 *
 * Desligado (env ausente) o compilador nem chama esta classe: a saída fica byte
 * a byte igual à de antes.
 */
final class MadSourceMarkers
{
    /**
     * Hosts cujo precompiler não tolera comentário entre os filhos (spike F0).
     * Só o host recebe marcador; os descendentes avançam o contador.
     */
    public const OPAQUE_HOSTS = [
        // grid e parentes do MadGridCompiler: colunas/ações viram config PHP
        'grid', 'seek', 'field-list', 'detail-form', 'checklist-field', 'dbchecklist-field',
        // precompilers de tela inteira: sub-tags viram config do renderInline
        'kanban', 'calendar', 'gantt', 'sheet', 'pdv', 'reconcile', 'org-chart',
        // blocos de filtro: o miolo vai em base64 e é re-renderizado sem os comentários
        'dash-filters', 'grid-filters', 'kanban-filters', 'calendar-filters', 'gantt-filters',
        // documento: colunas da tabela viram :columns
        'doc-data-table',
    ];

    /**
     * Sub-tags de config extraídas para prop do PAI (campo de banco): nem elas
     * nem o miolo recebem marcador. Marcador sobrando no corpo do campo depois
     * da extração faria o campo ganhar um slot que ele não tinha.
     */
    public const CONFIG_TAGS = ['quick-form', 'option-highlights'];

    /**
     * Slots que o MadGridCompiler arranca do pai pelo NOME da tag e re-emite só
     * o miolo (`<mad-db-blocks-form>`, a linha do `<mad-db-blocks>`, o menu e a
     * barra da `<mad-tree-view>`): a tag some, os filhos seguem com par.
     */
    public const SLOT_TAGS = ['db-blocks-form', 'db-blocks-row', 'tree-context-menu', 'tree-actions'];

    /**
     * Filtro escrito DENTRO do host que ele filtra é içado para antes do host
     * (MadDashFiltersCompiler::hoistFiltersOutOfHost): não herda a opacidade
     * do host. Mapa filtro => host.
     */
    private const HOISTED_FILTERS = [
        'grid-filters' => 'grid', 'kanban-filters' => 'kanban',
        'calendar-filters' => 'calendar', 'gantt-filters' => 'gantt',
    ];

    /** Tokens que interessam ao scanner: regiões excluídas + aberturas/fechamentos mad. */
    private const SCAN = '/\{\{--.*?--\}\}'
        . '|@verbatim\b.*?@endverbatim\b'
        . '|@php\b(?!\s*\().*?@endphp\b'
        . '|<script\b.*?<\/script\s*>'
        . '|<style\b.*?<\/style\s*>'
        . '|<(\/?)mad-([a-z0-9][a-z0-9.\-]*)(?=[\s\/>])/si';

    public static function enabled(): bool
    {
        return \Mad\Support\VibePreview::sourceMarkers();
    }

    /**
     * Chave da view: caminho relativo ao diretório de views do APP, sem
     * `.blade.php`, com `/`. Null para o que não é view do app (pacote,
     * vendor, cache inline do renderString, compileString sem path).
     */
    public static function viewKey(?string $path, ?string $viewsDir): ?string
    {
        if ($path === null || $path === '' || $viewsDir === null || $viewsDir === '') {
            return null;
        }
        $file = realpath($path) ?: $path;
        $root = rtrim(str_replace('\\', '/', realpath($viewsDir) ?: $viewsDir), '/') . '/';
        $file = str_replace('\\', '/', $file);

        if (!str_starts_with($file, $root) || !str_ends_with($file, '.blade.php')) {
            return null;
        }
        $rel = substr($file, strlen($root), -strlen('.blade.php'));
        if ($rel === '' || str_starts_with($rel, 'vendor/') || str_contains($rel, '/vendor/')) {
            return null;
        }
        return $rel;
    }

    /**
     * Aberturas `<mad-*>` da view, na ordem do texto.
     *
     * @return list<array{ord:int, tag:string, start:int, openEnd:int, end:?int, selfClosing:bool, parent:?int}>
     *         `start` = offset do `<`; `openEnd` = offset logo após o `>` da
     *         abertura; `end` = offset logo após o `>` do fechamento casado
     *         (ou = openEnd na autofechada; null se não casou); `parent` = índice
     *         do pai mad na lista.
     */
    public static function scan(string $blade): array
    {
        if (!preg_match_all(self::SCAN, $blade, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $tags  = [];
        $stack = [];
        $len   = strlen($blade);

        foreach ($all as $m) {
            if (!isset($m[2]) || $m[2][1] < 0) {
                continue; // região excluída
            }
            $at   = (int) $m[0][1];
            $name = strtolower($m[2][0]);

            if ($m[1][0] === '/') {
                $gt = strpos($blade, '>', $at);
                if ($gt === false) {
                    continue;
                }
                for ($i = count($stack) - 1; $i >= 0; $i--) {
                    if ($tags[$stack[$i]]['tag'] === $name) {
                        $tags[$stack[$i]]['end'] = $gt + 1;
                        array_splice($stack, $i);
                        break;
                    }
                }
                continue;
            }

            [$openEnd, $self] = self::openTagEnd($blade, $at + strlen($m[0][0]), $len);
            $idx = count($tags);
            $tags[] = [
                'ord'         => $idx + 1,
                'tag'         => $name,
                'start'       => $at,
                'openEnd'     => $openEnd,
                'end'         => $self ? $openEnd : null,
                'selfClosing' => $self,
                'parent'      => $stack ? $stack[count($stack) - 1] : null,
            ];
            if (!$self) {
                $stack[] = $idx;
            }
        }

        return $tags;
    }

    /**
     * Emite os marcadores. Tag sem fechamento casado não recebe par (o
     * ordinal dela continua contado): um `s` sem `e` só confundiria o bridge.
     *
     * @param list<string>|null $opaque hosts opacos (default OPAQUE_HOSTS)
     */
    public static function annotate(string $blade, string $viewKey, ?array $opaque = null): string
    {
        $tags = self::scan($blade);
        if (!$tags) {
            return $blade;
        }
        $opaque = $opaque ?? self::OPAQUE_HOSTS;

        $ins = []; // offset => marcadores a inserir ali
        foreach ($tags as $i => $t) {
            if ($t['end'] === null || in_array($t['tag'], self::SLOT_TAGS, true)
                || self::insideOpaque($tags, $i, $opaque)) {
                continue;
            }
            $ins[$t['start']][] = '<!--mad:s' . $t['ord'] . '@' . $viewKey . '-->';
            $ins[$t['end']][]   = '<!--mad:e' . $t['ord'] . '@' . $viewKey . '-->';
        }
        if (!$ins) {
            return $blade;
        }

        // Num mesmo offset cabem no máximo um `e` (fim da tag anterior) e um
        // `s` (início da seguinte): o `e` vem primeiro, senão os pares cruzam.
        $out  = '';
        $prev = 0;
        ksort($ins);
        foreach ($ins as $off => $list) {
            usort($list, static fn (string $a, string $b): int
                => (int) str_starts_with($b, '<!--mad:e') <=> (int) str_starts_with($a, '<!--mad:e'));
            $out .= substr($blade, $prev, $off - $prev) . implode('', $list);
            $prev = $off;
        }
        return $out . substr($blade, $prev);
    }

    /** @param list<array> $tags */
    private static function insideOpaque(array $tags, int $i, array $opaque): bool
    {
        if (in_array($tags[$i]['tag'], self::CONFIG_TAGS, true)) {
            return true;
        }
        $hoistedFrom = self::HOISTED_FILTERS[$tags[$i]['tag']] ?? null;
        for ($p = $tags[$i]['parent']; $p !== null; $p = $tags[$p]['parent']) {
            $tag = $tags[$p]['tag'];
            if ($tag === $hoistedFrom && $p === $tags[$i]['parent']) {
                continue; // içado para fora deste host antes de qualquer compiler
            }
            if (in_array($tag, $opaque, true) || in_array($tag, self::CONFIG_TAGS, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Fim da tag de abertura a partir de `$pos` (logo após o nome): pula
     * strings entre aspas e `{{ }}` / `{!! !!}` soltos, que podem ter `>`.
     *
     * @return array{int, bool} [offset após o `>`, autofechada?]
     */
    private static function openTagEnd(string $s, int $pos, int $len): array
    {
        while ($pos < $len) {
            $c = $s[$pos];
            if ($c === '"' || $c === "'") {
                $q = strpos($s, $c, $pos + 1);
                if ($q === false) {
                    return [$len, false];
                }
                $pos = $q + 1;
                continue;
            }
            if ($c === '{' && ($s[$pos + 1] ?? '') === '{') {
                $q = strpos($s, '}}', $pos + 2);
                $pos = $q === false ? $len : $q + 2;
                continue;
            }
            if ($c === '{' && substr($s, $pos, 3) === '{!!') {
                $q = strpos($s, '!!}', $pos + 3);
                $pos = $q === false ? $len : $q + 3;
                continue;
            }
            if ($c === '>') {
                $j = $pos - 1;
                while ($j > 0 && ctype_space($s[$j])) {
                    $j--;
                }
                return [$pos + 1, $s[$j] === '/'];
            }
            $pos++;
        }
        return [$len, false];
    }
}
