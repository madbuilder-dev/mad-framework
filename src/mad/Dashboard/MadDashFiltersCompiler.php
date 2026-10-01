<?php
namespace Mad\Dashboard;

/**
 * Pre-compiler for <mad-dash-filters style="..."> blocks.
 *
 * Extracts child native MAD tags (period-monthyear, dbcombo, dbsearch etc),
 * parses their attrs, preserves raw HTML, then replaces the block with
 * an @include of the renderer matching the chosen style.
 *
 * Runs BEFORE BladeOne's component pass so it controls what BladeOne sees.
 */
class MadDashFiltersCompiler
{
    /**
     * Native MAD tags recognized as filter fields.
     *
     * Só estas viram campo no layout automático (toolbar/chips/form/drawer/
     * modal/sidebar): tag de campo fora da lista SUMIA do render, sem erro nem
     * log — um `<mad-numeric-field>` com o percentual que a ação em lote lê, ou
     * o `<mad-radio-field>` do "filtro rápido", nunca aparecia e a ação recusava
     * o valor vazio. Os campos de valor escalar do kit entram todos (5.90.0);
     * o que não serve de filtro (upload, editor rico, checklist) continua fora.
     */
    public const FILTER_TAGS = [
        'mad-period-monthyear',
        'mad-dbunique-search-field',
        'mad-dbcombo-field',
        'mad-dbselect-field',
        'mad-select-field',
        'mad-input-field',
        'mad-search-field',
        'mad-date-field',
        'mad-daterange-field',
        'mad-switch-field',
        'mad-checkbox-field',
        'mad-number-field',
        'mad-numeric-field',
        'mad-money-field',
        'mad-spinner-field',
        'mad-radio-field',
        'mad-dbradio-field',
        'mad-dbmulti-search-field',
        'mad-dbcheckbox-group-field',
        'mad-datetime-field',
        'mad-time-field',
        'mad-textarea-field',
        'mad-color-field',
    ];

    /**
     * Logical filter type per tag. O tipo só escolhe como o rótulo do valor
     * ativo é resolvido (MadFiltersTrait::resolveFilterLabel): `dbcombo`/
     * `dbsearch` buscam o registro pelo model/display; tipo sem regra própria
     * mostra o valor cru.
     */
    public const TAG_TYPES = [
        'mad-period-monthyear'      => 'period-monthyear',
        'mad-dbunique-search-field' => 'dbsearch',
        'mad-dbcombo-field'         => 'dbcombo',
        'mad-dbselect-field'        => 'dbselect',
        'mad-select-field'          => 'select',
        'mad-input-field'           => 'input',
        'mad-search-field'          => 'search',
        'mad-date-field'            => 'date',
        'mad-daterange-field'       => 'daterange',
        'mad-switch-field'          => 'switch',
        'mad-checkbox-field'        => 'checkbox',
        'mad-number-field'          => 'number',
        'mad-numeric-field'         => 'number',
        'mad-money-field'           => 'number',
        'mad-spinner-field'         => 'number',
        'mad-radio-field'           => 'radio',
        'mad-dbradio-field'         => 'dbcombo',
        'mad-dbmulti-search-field'  => 'dbsearch',
        'mad-dbcheckbox-group-field' => 'dbcombo',
        'mad-datetime-field'        => 'datetime',
        'mad-time-field'            => 'time',
        'mad-textarea-field'        => 'input',
        'mad-color-field'           => 'color',
    ];

    /** Style → renderer template (resolved via MadBlade component namespace). */
    public const STYLE_RENDERERS = [
        'toolbar'       => 'components.dash-filters-toolbar',
        'chips'         => 'components.dash-filters-chips',
        'drawer'        => 'components.dash-filters-drawer',
        'modal'         => 'components.dash-filters-modal',
        'form'          => 'components.dash-filters-form',     // form tradicional inline
        'sidebar'       => 'components.dash-filters-sidebar',  // alias → sidebar-left
        'sidebar-left'  => 'components.dash-filters-sidebar',
        'sidebar-right' => 'components.dash-filters-sidebar',
    ];

    /**
     * Compila TODOS os blocos de filtros declarativos no template.
     * Aliases semanticos (mesmo compiler + renderers):
     *   <mad-dash-filters>      → dashboards (MadDashboard)
     *   <mad-grid-filters>      → listagens (MadDataGrid)
     *   <mad-kanban-filters>    → kanbans (MadKanban)
     *   <mad-calendar-filters>  → calendarios (MadComponent + MadFullCalendar)
     *   <mad-gantt-filters>     → gantts (futuro — placeholder)
     */
    public const TAG_ALIASES = ['dash', 'grid', 'kanban', 'calendar', 'gantt'];

    /**
     * Attribute matcher — lida com valores quoted contendo '>':
     * attr="val" | attr='val' | attr=word | @click="..." | :prop="..." | bool attr.
     *
     * Valor sem aspas segue o HTML: sem espaço, aspas, `=`, `<`, `>` nem crase.
     * Com `\S+` o backtracking aceitava `style="form"><mad-date-field` como um
     * valor sem aspas quando o campo vinha colado na abertura do bloco
     * (`<mad-grid-filters style="form"><mad-date-field … />`): o padrão do
     * bloco self-closing casava até o `/>` do CAMPO e o campo sumia da tela.
     */
    private const ATTR_PATTERN = '(?:\s+(?:@?:?[a-zA-Z0-9_:.-]+(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'=<>`]+))?))*';

    /**
     * Componente "host" de cada alias de filtro — o que o bloco filtra.
     * `dash` fica de fora de propósito: o host dele é a página inteira, não uma
     * tag única (ver wrapDashSidebarLayout).
     */
    public const FILTER_HOSTS = [
        'grid'     => 'mad-grid',
        'kanban'   => 'mad-kanban',
        'calendar' => 'mad-calendar',
        'gantt'    => 'mad-gantt',
    ];

    /**
     * Iça `<mad-{alias}-filters>` de DENTRO do host pra imediatamente ANTES dele.
     *
     * Por que existe: escrever o bloco de filtros dentro do componente que ele
     * filtra é a leitura intuitiva ("os filtros são da grid, logo moram na grid")
     * e era o erro nº 1 de posicionamento — medido em 400 páginas de produção
     * (jul/2026): 22 tinham `<mad-grid-filters>` dentro do `<mad-grid>`, e nas 22
     * os filtros **não renderizavam**. O motivo era invisível: o MadGridCompiler
     * roda ANTES do compiler de filtros e descarta todo o corpo da grid que não
     * seja coluna/ação — quando o MadDashFiltersCompiler chegava, a tag já tinha
     * sido apagada junto com o `<mad-grid>`. Sem erro, sem log, sem filtro.
     *
     * A correção é de POSIÇÃO, não de semântica: em vez de ensinar cada compiler
     * de host a entender filtros (4 compilers, 4 duplicações, 4 chances de
     * divergir), normaliza-se o template uma vez, antes de todo mundo. Do passo
     * seguinte em diante o pipeline inteiro só conhece a forma canônica.
     *
     * Roda no passo 0.85 do MadBlade — ANTES do wrap de sidebar (0.9), senão
     * `style="sidebar-*"` içado não encontraria mais o par (filters + host) que o
     * wrap procura. Idempotente: uma vez fora, o bloco não casa de novo.
     *
     * Só move o filtro do PRÓPRIO alias: um `<mad-dash-filters>` perdido dentro de
     * uma grid continua onde está (é outro componente, e mover seria adivinhar).
     */
    public static function hoistFiltersOutOfHost(string $template): string
    {
        foreach (self::FILTER_HOSTS as $alias => $host) {
            $open = '<mad-' . $alias . '-filters';
            if (strpos($template, $open) === false)   continue;
            if (strpos($template, '<' . $host) === false) continue;

            // (?![-\w]) e NÃO \b: `<mad-grid\b` casaria `<mad-grid-filters`, e
            // `<mad-calendar\b` casaria `<mad-calendar-toolbar`.
            $template = preg_replace_callback(
                '#<' . $host . '(?![-\w])(' . self::ATTR_PATTERN . ')\s*>(.*?)</' . $host . '\s*>#s',
                static function (array $m) use ($alias, $host, $open): string {
                    $body = $m[2];
                    if (strpos($body, $open) === false) return $m[0];

                    $lifted = '';
                    $body = preg_replace_callback(
                        '#' . preg_quote($open, '#') . '(?![-\w])(' . self::ATTR_PATTERN . ')\s*'
                            . '(?:/\s*>|>(.*?)</mad-' . $alias . '-filters\s*>)#s',
                        static function (array $f) use (&$lifted): string {
                            $lifted .= $f[0];
                            return '';
                        },
                        $body
                    );
                    if ($lifted === '') return $m[0];

                    return $lifted . '<' . $host . $m[1] . '>' . $body . '</' . $host . '>';
                },
                $template
            );
        }

        return $template;
    }

    public static function compile(string $template): string
    {
        // Trigger check rapido — qualquer prefix conhecido?
        $hit = false;
        foreach (self::TAG_ALIASES as $a) {
            if (strpos($template, '<mad-' . $a . '-filters') !== false) {
                $hit = true; break;
            }
        }
        if (!$hit) return $template;

        // 0. <mad-grid-filters style="sidebar-*"> em LISTAGEM: o renderer da
        //    sidebar exige um wrapper de coluna (.mad-gridf-layout-left|right)
        //    envolvendo sidebar + grid. Em dashboards o proprio fluxo do
        //    dashboard emite o wrapper; em listagens ninguem emitia e a
        //    sidebar empilhava full-width. Aqui embrulhamos o par
        //    (filters + <mad-grid> seguinte) automaticamente.
        $template = self::wrapGridSidebarLayout($template);
        $template = self::wrapDashSidebarLayout($template);

        $alt = implode('|', self::TAG_ALIASES); // (dash|grid|kanban|calendar|gantt)

        // Self-closing: <mad-{alt}-filters ... /> — sem filhos, fields vazio.
        // ATTR_PATTERN (nao [^>]*) pra nao truncar em label="a > b".
        $template = preg_replace_callback(
            '#<mad-(?:' . $alt . ')-filters(' . self::ATTR_PATTERN . ')\s*/\s*>#s',
            function ($m) {
                $wrapperAttrs = self::parseAttrs($m[1] ?? '');
                return self::buildOutput($wrapperAttrs, []);
            },
            $template
        );

        // Forma com filhos: <mad-{alt}-filters ...>...</mad-{alt}-filters>
        return preg_replace_callback(
            '#<mad-(' . $alt . ')-filters(' . self::ATTR_PATTERN . ')\s*>(.*?)</mad-\1-filters>#s',
            function ($m) {
                $wrapperAttrs = self::parseAttrs($m[2] ?? '');
                $inner = $m[3] ?? '';
                $fields = self::parseInnerTags($inner);
                // Preserva inner cru pra renderers que suportam layout="custom"
                // (form/drawer/modal/sidebar). Permite uso de <mad-form-grid>,
                // <mad-form-section>, <mad-tabs>, etc. dentro do wrapper.
                $wrapperAttrs['_raw_inner_b64'] = base64_encode($inner);
                return self::buildOutput($wrapperAttrs, $fields);
            },
            $template
        );
    }

    /**
     * Embrulha <mad-grid-filters style="sidebar-*"> + o <mad-grid> seguinte no
     * layout de coluna (.mad-gridf-layout-left|right + .mad-gridf-main). O
     * main leva order:3 (a sidebar emite order:1/left ou order:4/right — mesmo
     * contrato do dashboard). Se o autor ja embrulhou na mao (qualquer coisa
     * entre o filters e o grid), o pattern nao casa e nada muda.
     *
     * PRECISA rodar ANTES do MadGridCompiler (que consome a tag <mad-grid>) —
     * por isso o MadBlade chama este metodo no passo 0.9, alem do fallback
     * dentro de compile() (idempotente: uma vez embrulhado, nao casa de novo).
     */
    public static function wrapGridSidebarLayout(string $template): string
    {
        if (strpos($template, '<mad-grid-filters') === false) {
            return $template;
        }

        return preg_replace_callback(
            '#(<mad-grid-filters' . self::ATTR_PATTERN . '\s*>.*?</mad-grid-filters>'
                . '|<mad-grid-filters' . self::ATTR_PATTERN . '\s*/\s*>)'
                . '\s*(<mad-grid\b.*?</mad-grid>)#s',
            function ($m) {
                if (!preg_match('#style\s*=\s*["\']sidebar-(left|right)["\']#', $m[1], $s)) {
                    return $m[0];
                }
                return '<div class="mad-gridf-layout-' . $s[1] . '">'
                    . $m[1]
                    . '<div class="mad-gridf-main" style="order:3;">' . $m[2] . '</div>'
                    . '</div>';
            },
            $template
        );
    }

    /**
     * Embrulha <mad-dash-filters style="sidebar-*"> + TODO o conteudo seguinte
     * (ate </mad-page-content>) no layout de coluna .mad-dashf-layout-left|right.
     * No dashboard o "main" nao e uma tag unica como o <mad-grid> da listagem —
     * e o resto da pagina (KPIs, charts, grids) — por isso o wrap fecha no
     * </mad-page-content>. Sem isto o estilo degrada pra painel empilhado
     * full-width (o enum aceitava sidebar-left/right mas nada montava a coluna).
     *
     * Roda no passo 0.9 do MadBlade (antes de qualquer compiler consumir tags)
     * + fallback dentro de compile(). Idempotente: o lookahead pula quando o
     * conteudo seguinte ja e o <div class="mad-dashf-main"> de um wrap anterior.
     */
    public static function wrapDashSidebarLayout(string $template): string
    {
        if (strpos($template, '<mad-dash-filters') === false
            || strpos($template, 'sidebar-') === false) {
            return $template;
        }

        return preg_replace_callback(
            '#(<mad-dash-filters' . self::ATTR_PATTERN . '\s*>.*?</mad-dash-filters>'
                . '|<mad-dash-filters' . self::ATTR_PATTERN . '\s*/\s*>)'
                . '(?!\s*<div class="mad-dashf-main")'
                . '\s*(.*?)(</mad-page-content>)#s',
            function ($m) {
                if (!preg_match('#style\s*=\s*["\']sidebar-(left|right)["\']#', $m[1], $s)) {
                    return $m[0];
                }
                return '<div class="mad-dashf-layout-' . $s[1] . '">'
                    . $m[1]
                    . '<div class="mad-dashf-main" style="order:3;">' . $m[2] . '</div>'
                    . '</div>' . $m[3];
            },
            $template
        );
    }

    /**
     * Parses inner HTML, extracting metadata for each known native filter tag.
     */
    private static function parseInnerTags(string $inner): array
    {
        $fields = [];
        $tagAlt = implode('|', array_map('preg_quote', self::FILTER_TAGS));

        $attrPattern = self::ATTR_PATTERN;

        // Self-closing: <mad-X ATTRS />  OR open-close: <mad-X ATTRS>...</mad-X>
        $patternSelf  = '#<(' . $tagAlt . ')(' . $attrPattern . ')\s*/\s*>#s';
        $patternBlock = '#<(' . $tagAlt . ')(' . $attrPattern . ')\s*>(.*?)</\1>#s';

        $positions = [];

        if (preg_match_all($patternSelf, $inner, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($matches as $match) {
                $positions[$match[0][1]] = [
                    'tag'      => $match[1][0],
                    'attrsStr' => $match[2][0] ?? '',
                    'inner'    => '',
                    'raw_html' => $match[0][0],
                ];
            }
        }

        if (preg_match_all($patternBlock, $inner, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($matches as $match) {
                $positions[$match[0][1]] = [
                    'tag'      => $match[1][0],
                    'attrsStr' => $match[2][0] ?? '',
                    'inner'    => $match[3][0] ?? '',
                    'raw_html' => $match[0][0],
                ];
            }
        }

        // Tag aberta sem '/>' nem '</tag>': trata como void — antes o campo
        // sumia do render silenciosamente. Offsets ja capturados (abertura de
        // block-form) sao pulados.
        $patternOpen = '#<(' . $tagAlt . ')(' . $attrPattern . ')\s*>#s';
        if (preg_match_all($patternOpen, $inner, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($matches as $match) {
                $off = $match[0][1];
                if (isset($positions[$off])) continue;
                $positions[$off] = [
                    'tag'      => $match[1][0],
                    'attrsStr' => $match[2][0] ?? '',
                    'inner'    => '',
                    'raw_html' => $match[0][0],
                ];
            }
        }

        ksort($positions);

        foreach ($positions as $pos => $data) {
            $attrs = self::parseAttrs($data['attrsStr']);

            // `filter-op` e instrucao pro WHERE do host, nao atributo do input:
            // sai do HTML re-renderizado (senao vazaria como atributo
            // desconhecido no DOM) e vira mapa coluna=>operador, consumido por
            // MadFiltersTrait::declareFilterOps.
            $rawHtml = $data['raw_html'];
            if (isset($attrs['filter-op'])) {
                $rawHtml = preg_replace('/\s*filter-op\s*=\s*(?:"[^"]*"|\'[^\']*\'|\S+)/', '', $rawHtml);
            }

            $fields[] = [
                'tag'      => $data['tag'],
                'type'     => self::TAG_TYPES[$data['tag']] ?? 'unknown',
                'attrs'    => $attrs,
                // base64-encoded HTML — necessario porque steps subsequentes do
                // MadBlade::compileString fazem str_replace('<mad-', '<x-') no
                // template inteiro, incluindo strings PHP. Renderer decodifica
                // antes de passar pra MadBlade::renderString.
                'raw_html_b64' => base64_encode($rawHtml),
                'inner_b64'    => base64_encode($data['inner']),
            ];
        }

        return $fields;
    }

    /**
     * Parses an HTML attributes string into a name=>value array.
     * Preserves :prefix (bind) and @prefix (alpine) in attribute names.
     * Values stored as literal strings (without surrounding quotes).
     */
    public static function parseAttrs(string $str): array
    {
        $attrs = [];
        $pattern = '/(@?:?[\w\-]+(?::[\w\-]+)?)' .       // name (incl colons, dashes)
                   '(?:\s*=\s*' .
                       '(?:"([^"]*)"|\'([^\']*)\'|(\S+))' . // double / single / unquoted
                   ')?/';
        // UNMATCHED_AS_NULL: distingue grupo nao-casado (null) de valor vazio
        // ('') — antes !empty() colapsava attr="0" e attr="" em boolean true.
        if (preg_match_all($pattern, $str, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL)) {
            foreach ($matches as $m) {
                $name = $m[1];
                if (isset($m[2])) {
                    $attrs[$name] = $m[2];
                } elseif (isset($m[3])) {
                    $attrs[$name] = $m[3];
                } elseif (isset($m[4])) {
                    $attrs[$name] = $m[4];
                } else {
                    $attrs[$name] = true;
                }
            }
        }
        return $attrs;
    }

    /**
     * Builds Blade output: declares $__dashFields, $__dashStyle, $__dashWrapper
     * and @includes the proper renderer.
     */
    /**
     * Textos do wrapper que aceitam expressao (`:title="__('Busca')"`, o modo
     * traduzivel do editor). Antes o `:title` virava a chave literal ':title'
     * e o titulo traduzido nunca aparecia — caia no "Filtros" padrao.
     */
    private const BOUND_TEXT_ATTRS = ['title', 'apply-label', 'clear-label'];

    /**
     * Rotulos e botoes do wrapper, comuns a todos os estilos (forum #65:
     * "Buscar"/"Limpar" no lugar de "Aplicar"/"Limpar tudo", sem "Atualizar",
     * sem a linha de cabecalho do estilo Formulario).
     *
     * @return array{apply: string, clearAll: string, clear: string, refresh: bool, header: bool}
     */
    public static function chrome(array $wrapper): array
    {
        $text = static function (string $k) use ($wrapper): string {
            $v = $wrapper[$k] ?? null;
            return is_scalar($v) && !is_bool($v) ? trim((string) $v) : '';
        };
        $flag = static function (string $k) use ($wrapper): bool {
            $v = $wrapper[$k] ?? null;
            if ($v === null || $v === false) return false;
            return $v === true || !in_array(strtolower(trim((string) $v)), ['false', '0'], true);
        };
        $apply = $text('apply-label');
        $clear = $text('clear-label');

        return [
            'apply'    => $apply !== '' ? $apply : mad_t('mad.btn.apply'),
            'clearAll' => $clear !== '' ? $clear : mad_t('mad.btn.clear_all'),
            'clear'    => $clear !== '' ? $clear : mad_t('mad.btn.clear'),
            'refresh'  => !$flag('no-refresh'),
            'header'   => !$flag('no-header'),
        ];
    }

    private static function buildOutput(array $wrapperAttrs, array $fields): string
    {
        $style = (string) ($wrapperAttrs['style'] ?? 'toolbar');
        $renderer = self::STYLE_RENDERERS[$style] ?? self::STYLE_RENDERERS['toolbar'];

        // `:attr="expr"` dos textos vira PHP avaliado no render; o resto segue
        // literal. Expressao do proprio autor da tela, como qualquer `{{ }}`.
        $bound = [];
        foreach (self::BOUND_TEXT_ATTRS as $name) {
            $expr = $wrapperAttrs[':' . $name] ?? null;
            unset($wrapperAttrs[':' . $name]);
            if (is_string($expr) && trim($expr) !== '') {
                $bound[] = var_export($name, true) . ' => (' . $expr . ')';
                unset($wrapperAttrs[$name]);
            }
        }

        // Serialize fields + wrapper as PHP literals via var_export
        // Note: raw_html may contain double quotes; var_export handles by single-quoting strings.
        $fieldsPhp  = self::exportArray($fields);
        $wrapperPhp = self::exportArray($wrapperAttrs);
        if ($bound) {
            $wrapperPhp = 'array_merge(' . $wrapperPhp . ', [' . implode(', ', $bound) . '])';
        }

        // Operadores declarados no blade (`filter-op=`) → host. Emitido aqui,
        // no bloco comum, e nao em cada renderer de estilo: sao 8 estilos, e
        // um esquecido viraria filtro que ignora o operador em silencio.
        $opsPhp = self::exportArray(self::filterOpsOf($fields));

        // PHP CRU (tag de abertura + tag de fechamento), NUNCA `@php`/`@endphp`.
        // Não é estilo: é correção de bug. O `storePhpBlocks()` do Blade casa
        // `/(?<!@)@php(.*?)@endphp/s` — não-guloso a partir do PRIMEIRO `@php`,
        // sem distinguir a forma shorthand `@php(<expr>)` da forma de bloco.
        // Se o template do usuário tiver um `@php($x = 1)` em qualquer ponto
        // ANTES deste bloco, o match começa lá e termina no `@endphp` daqui: o
        // span inteiro é reemitido como UM bloco só, o `; ` + fechamento que o
        // shorthand geraria some, a abertura fica colada no `(` (deixa de ser
        // tag de abertura válida) e todo o PHP gerado — o bloco de filtros
        // inclusive — vaza como HTML. Página quebrada, sem erro de compilação
        // que aponte a causa. Emitindo PHP cru não existe `@endphp` pra
        // ancorar o match: o shorthand do usuário compila normalmente e este
        // bloco atravessa o token_get_all como PHP real (mesmo contrato dos
        // demais compilers MAD que emitem PHP, ex. MadGridCompiler).
        return "<?php\n"
             . "    \$__dashFields  = {$fieldsPhp};\n"
             . "    \$__dashStyle   = " . var_export($style, true) . ";\n"
             . "    \$__dashWrapper = {$wrapperPhp};\n"
             . "    \$__dashOps     = {$opsPhp};\n"
             . "    \$__dashHost    = \\Mad\\Component\\MadRenderContext::getComponent();\n"
             . "    if (\$__dashOps && \$__dashHost && method_exists(\$__dashHost, 'declareFilterOps')) {\n"
             . "        \$__dashHost->declareFilterOps(\$__dashOps);\n"
             . "    }\n"
             // Filtros ativos como TEXTO, para o {FILTERS} das bandas do PDF
             // exportado pela grade. O method_exists degrada pra nada em app
             // com view compilada antiga — o `view:clear` do republish liga.
             . "    if (\$__dashHost && method_exists(\$__dashHost, 'declareFilterFields')) {\n"
             . "        \$__dashHost->declareFilterFields(\$__dashFields);\n"
             . "    }\n"
             . "?>\n"
             . "@include('{$renderer}', ['fields' => \$__dashFields, 'style' => \$__dashStyle, 'wrapper' => \$__dashWrapper])";
    }

    /**
     * Mapa coluna => operador a partir dos campos parseados.
     *
     * @param array<int,array<string,mixed>> $fields
     * @return array<string,string>
     */
    private static function filterOpsOf(array $fields): array
    {
        $ops = [];
        foreach ($fields as $f) {
            $name = (string) ($f['attrs']['name'] ?? '');
            $op   = $f['attrs']['filter-op'] ?? '';
            if ($name === '' || !is_string($op) || $op === '') continue;
            $ops[$name] = $op;
        }

        return $ops;
    }

    /**
     * Exporta array como literal PHP short-syntax. Recursivo, sem regex
     * pos-var_export — valores contendo "array (" ou newline+")" corrompiam
     * o PHP compilado com a abordagem antiga.
     */
    public static function exportArray(array $arr): string
    {
        $parts = [];
        foreach ($arr as $k => $v) {
            $key = var_export($k, true);
            if (is_array($v)) {
                $val = self::exportArray($v);
            } elseif (is_scalar($v) || $v === null) {
                $val = var_export($v, true);
            } else {
                $val = var_export((string) $v, true);
            }
            $parts[] = $key . ' => ' . $val;
        }
        return '[' . implode(', ', $parts) . ']';
    }
}
