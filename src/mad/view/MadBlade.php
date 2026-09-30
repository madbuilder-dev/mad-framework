<?php
namespace Mad\View;
use Mad\Component\MadComponent;
use Mad\Grid\MadGridCompiler;
use Mad\Ui\MadAction;


use Illuminate\View\Compilers\BladeCompiler;

/**
 * MadBladeCompiler — BladeCompiler do Illuminate com o pipeline MAD:
 * compilers de tags (<mad-grid>, <mad-kanban>...), alias <mad-xxx> → <x-xxx>,
 * compilação própria de componentes (semântica clássica: todo atributo vira
 * variável no template) e pós-passe mad:* → data-mad-*.
 *
 * F1-10: substitui o BladeOne. A compilação nativa de <x-...> do Illuminate
 * (ComponentTagCompiler, que exige @props/classe) fica DESLIGADA — o
 * compileComponents() abaixo emite $__env->startComponent()/renderComponent()
 * (API clássica do Factory), preservando o contrato dos ~100 templates mad-*.
 */
class MadBladeCompiler extends BladeCompiler
{
    /** Desliga o ComponentTagCompiler nativo — <x-*> é nosso. */
    protected $compilesComponentTags = false;

    public function compileString($value)
    {
        // 0.85. Canonicaliza a POSIÇÃO do bloco de filtros: escrito DENTRO do
        //       componente que ele filtra (<mad-grid-filters> dentro de
        //       <mad-grid>, idem kanban/calendar/gantt), o bloco é içado pra
        //       imediatamente ANTES do host. Tem que ser o PRIMEIRO passo — a
        //       partir daqui todo o resto do pipeline (wrap de sidebar, o
        //       compiler do host, o compiler dos filtros) vê a forma canônica e
        //       não precisa saber que a outra existe.
        $value = \Mad\Dashboard\MadDashFiltersCompiler::hoistFiltersOutOfHost($value);

        // 0.9. <mad-grid-filters style="sidebar-*">: embrulha filters + o
        //      <mad-grid> seguinte no layout de coluna. TEM que rodar antes
        //      do MadGridCompiler — depois dele a tag <mad-grid> não existe
        //      mais e o wrap não teria como casar.
        if (strpos($value, '<mad-grid-filters') !== false) {
            $value = \Mad\Dashboard\MadDashFiltersCompiler::wrapGridSidebarLayout($value);
        }

        // 0.95. <mad-dash-filters style="sidebar-*">: embrulha filters + o resto
        //       do <mad-page-content> no layout de coluna do dashboard. Antes de
        //       qualquer compiler consumir as tags (o wrap fecha no
        //       </mad-page-content> literal).
        if (strpos($value, '<mad-dash-filters') !== false) {
            $value = \Mad\Dashboard\MadDashFiltersCompiler::wrapDashSidebarLayout($value);
        }

        // 1. Preprocessa <mad-grid>/<mad-col>/etc. → PHP puro (antes de qualquer outra coisa)
        $value = MadGridCompiler::compile($value);

        // 1.05. Preprocessa <mad-kanban>/<mad-kanban-card>/<mad-kanban-badge|meta|footer>.
        //       Substitui o antigo @include('components.kanban') por API declarativa.
        //       Lookahead nas regex evita conflito com <mad-kanban-filters> (tratado em 1.7).
        if (strpos($value, '<mad-kanban') !== false) {
            $value = \Mad\Calendar\MadKanbanCompiler::compile($value);
        }

        // 1.06. Preprocessa <mad-calendar>/<mad-calendar-toolbar|popover|resource|filter>.
        //       API Blade-first do MadCalendarComponent.
        //       Lookahead nas regex evita conflito com <mad-calendar-filters> (tratado em 1.7).
        if (strpos($value, '<mad-calendar') !== false) {
            $value = \Mad\Calendar\MadCalendarCompiler::compile($value);
        }

        // 1.07. Preprocessa <mad-gantt>/<mad-gantt-column|filter|header-action|baseline|holiday|resource|resource-filter|toolbar|popover>.
        //       API Blade-first do MadGanttComponent.
        //       Lookahead nas regex evita conflito com <mad-gantt-filters> (tratado em 1.7).
        if (strpos($value, '<mad-gantt') !== false) {
            $value = \Mad\Calendar\MadGanttCompiler::compile($value);
        }

        // 1.08. Preprocessa <mad-sheet>/<mad-sheet-col> (planilha de lançamento
        //       em lote). Lookahead nas regex evita casar <mad-sheet-col> na
        //       tag raiz.
        if (strpos($value, '<mad-sheet') !== false) {
            $value = \Mad\Sheet\MadSheetCompiler::compile($value);
        }

        // 1.09. Preprocessa <mad-reconcile> (conciliação two-panel).
        if (strpos($value, '<mad-reconcile') !== false) {
            $value = \Mad\Reconcile\MadReconcileCompiler::compile($value);
        }

        // 1.10. Preprocessa <mad-org-chart>/<mad-org-chart-card> (hierarquia
        //       visual). Lookahead nas regex evita casar a tag-filho.
        if (strpos($value, '<mad-org-chart') !== false) {
            $value = \Mad\OrgChart\MadOrgChartCompiler::compile($value);
        }

        // 1.11. Preprocessa <mad-pdv>/<mad-pdv-payment|action> (frente de
        //       caixa). API Blade-first do MadPdvComponent. Lookahead nas
        //       regex evita casar as sub-tags na tag raiz.
        if (strpos($value, '<mad-pdv') !== false) {
            $value = \Mad\Pdv\MadPdvCompiler::compile($value);
        }

        // 1.12. Desce o `submit` do <mad-form> para os <mad-btn type="submit">
        //       dentro dele, como perm-action. O botão "Salvar" é o único que
        //       não sabe o que faz: quem sabe é o formulário em volta, e no
        //       render é tarde (o slot renderiza ANTES do template do form).
        //       Sem isto, "Salvar" era o único botão sem como perguntar se o
        //       perfil permite incluir/editar.
        if (strpos($value, '<mad-form') !== false) {
            $value = \Mad\Security\MadPermissionCompiler::compile($value);
        }

        // 1.5. Preprocessa <mad-doc-repeater> do gerador de documentos.
        //      Converte em @foreach + acumuladores de agregados, publicando
        //      totais em $totals. Precisa rodar ANTES da etapa 3 (str_replace
        //      <mad- → <x-) senão a tag vira um componente Blade comum —
        //      que não consegue iterar o slot com $record por linha.
        if (strpos($value, '<mad-doc-repeater') !== false) {
            $value = \Mad\Doc\MadDocRepeaterCompiler::compile($value);
        }

        // 1.6. Preprocessa <mad-doc-data-table> + filhos <mad-doc-data-table-column>.
        //      Mesma motivação do 1.5: agregamos os filhos em :columns="[…]"
        //      e emitimos um <x-doc-data-table> com :rows vindos da query do model
        //      (ou :rows literal como escape hatch).
        if (strpos($value, '<mad-doc-data-table') !== false) {
            $value = \Mad\Doc\MadDocTableCompiler::compile($value);
        }

        // 1.7. Preprocessa <mad-dash-filters style="..."> e aliases semanticos:
        //      <mad-grid-filters>, <mad-kanban-filters>, <mad-calendar-filters>,
        //      <mad-gantt-filters>. Mesmo compiler + renderers.
        if (strpos($value, '-filters') !== false
            && (strpos($value, '<mad-dash-filters') !== false
                || strpos($value, '<mad-grid-filters') !== false
                || strpos($value, '<mad-kanban-filters') !== false
                || strpos($value, '<mad-calendar-filters') !== false
                || strpos($value, '<mad-gantt-filters') !== false)) {
            $value = \Mad\Dashboard\MadDashFiltersCompiler::compile($value);
        }

        // 2. Normaliza <x-slot:name> → <x-slot name="name"> (sintaxe moderna)
        $value = preg_replace_callback(
            '/<x-slot:([a-zA-Z][a-zA-Z0-9_-]*)([\s\S]*?)>([\s\S]*?)<\/x-slot:\1>/s',
            fn($m) => '<x-slot name="' . $m[1] . '"' . $m[2] . '>' . $m[3] . '</x-slot>',
            $value
        );

        // 2.5. Extrai <mad-quick-form>...</mad-quick-form> de dentro de tags MAD de
        //      selecao e converte em props :no-results-quick-fields no pai.
        $value = \Mad\Form\MadQuickFormCompiler::process($value);

        // 2.55. Extrai filhos <fill> dos selects de banco e converte em
        //        :auto-fill="[...]" no pai. DEPOIS do quick-form (que reconcatena
        //        o corpo, preservando os <fill>) e ANTES do mapping mad→x.
        $value = \Mad\Form\MadAutoFillCompiler::process($value);

        // 2.6. Aliases de chart por tipo: <mad-{bar|line|pie|...|radar|mixed}-chart>
        //      → <mad-db-chart type="{tipo}">. Injeta type="..." preservando demais attrs.
        $chartTypes = 'bar|line|pie|donut|rose|funnel|treemap|radar|mixed';
        $value = preg_replace_callback(
            '#<mad-(' . $chartTypes . ')-chart\b([^/>]*?)(/?)>#s',
            fn($m) => '<mad-db-chart type="' . $m[1] . '"' . $m[2] . $m[3] . '>',
            $value
        );
        $value = preg_replace('#</mad-(' . $chartTypes . ')-chart>#s', '</mad-db-chart>', $value);

        // 3. Converte <mad-xxx> → <x-xxx> (exceto as tags de grid já compiladas).
        //    Preserva Blade comments {{-- ... --}} — caso contrário, exemplos
        //    de uso dentro de comentários seriam compilados em invocações reais
        //    (e componentes que citam a si mesmos num exemplo vão recursar
        //    infinitamente). Extrai comentários, transforma, e restaura.
        $bladeComments = [];
        $value = preg_replace_callback('/\{\{--.*?--\}\}/s', function ($m) use (&$bladeComments) {
            $key = "\x00__MAD_BLADE_COMMENT_" . count($bladeComments) . "__\x00";
            $bladeComments[$key] = $m[0];
            return $key;
        }, $value);
        // Preserva blocos PHP — str_replace nao deve mexer em '<mad-...' que
        // aparece como literal de string dentro de blocos PHP do template
        // (ex: preg_replace com pattern '#<mad-tag\b#'). Sem isso, esses
        // literals viram '<x-tag' no PHP de runtime, contaminam o output de
        // helpers como renderString e o compileComponents seguinte enxerga
        // '<x-...' fora do contexto Blade — recusa-se a casar componentes ao
        // redor (body de <x-form> com a string literal nao bate o regex).
        $phpBlocks = [];
        $value = preg_replace_callback(
            '/(?:<\?(?:php|=)[\s\S]*?\?>)|(?:@php\b[\s\S]*?@endphp\b)/s',
            function ($m) use (&$phpBlocks) {
                $key = "\x00__MAD_PHP_BLOCK_" . count($phpBlocks) . "__\x00";
                $phpBlocks[$key] = $m[0];
                return $key;
            },
            $value
        );
        $value = str_replace('</mad-', '</x-', $value);
        $value = str_replace('<mad-',  '<x-',  $value);
        if ($phpBlocks) {
            $value = strtr($value, $phpBlocks);
        }
        if ($bladeComments) {
            $value = strtr($value, $bladeComments);
        }

        // 3.5. Compila componentes antes do token_get_all do BladeOne,
        //      pois blocos PHP gerados pelo MadGridCompiler fragmentam
        //      o HTML em multiplos T_INLINE_HTML, impedindo o regex de
        //      casar tags que abrem e fecham em segmentos diferentes.
        $value = $this->compileComponents($value);

        // 4. BladeOne compila o resto
        $compiled = parent::compileString($value);

        // 5. Converte diretivas mad:* → atributos data-mad-* (HTML5)
        //    Protege blocos <pre>/<code> para não converter texto de documentação
        $preserved = [];
        $compiled = preg_replace_callback('/<(pre|code)\b[^>]*>[\s\S]*?<\/\1>/si', function ($m) use (&$preserved) {
            $key = '<!--MAD_PRE_' . count($preserved) . '-->';
            $preserved[$key] = $m[0];
            return $key;
        }, $compiled);

        $compiled = strtr($compiled, [
            'mad:click='          => 'data-mad-click=',
            'mad:model.live='     => 'data-mad-model-live=',
            'mad:model='          => 'data-mad-model=',
            'mad:submit='         => 'data-mad-submit=',
            'mad:change='         => 'data-mad-change=',
            'mad:loading.remove'  => 'data-mad-loading-remove',
            'mad:loading'         => 'data-mad-loading',
        ]);

        return strtr($compiled, $preserved);
    }

    /**
     * Sobrescreve compileComponents para suportar:
     *  - Atributos multi-linha e '>' dentro de valores entre aspas
     *  - Named slots: <x-slot name="title">...</x-slot> dentro de <x-component>
     */
    protected function compileComponents($value)
    {
        // Atributos: sequência de name="val" | :name="expr" | @click="..." | mad:click="action" | name (booleano)
        // Permite espaços, quebras e '>' dentro das aspas.
        // O prefixo @?:? suporta Alpine directives (@click, @change, @mouseenter etc.)
        // Também aceita {{ expr }} e {!! expr !!} inline como atributos condicionais
        //   ex: {{ $isNew ? 'required' : '' }}
        $attr = '(?:\s+(?:@?:?[a-zA-Z0-9_:.-]+(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'))?|\{\{[^}]*\}\}|\{!![^!]*!!\}))*\s*';

        // ── Estratégia iterativa bottom-up ──────────────────────────────────
        // Compila de dentro para fora em múltiplas passadas:
        //  1. Self-closing: <x-component ... />
        //  2. Leaf components (sem <x-*> filhos): <x-component ...>...</x-component>
        //  3. Repete até não haver mais <x-*> tags
        // Evita o regex recursivo (?:(?!<\/x-\1>)[\s\S])* que estoura JIT stack.

        $compileSelfClosing = function (array $match): string {
            $paramsCompiled = $this->parseParams($match[2] ?? '', $match[1] ?? '');
            $template       = static::resolveComponentTemplate($match[1]);
            return '<?php $__env->startComponent(\'' . $template . '\', ' . $paramsCompiled . '); '
                 . 'echo $__env->renderComponent(); ?>';
        };

        $compileWithBody = function (array $match): string {
            $inner = $match[3] ?? '';

            // Converte <menu>...</menu> → <x-slot name="menu">...</x-slot> (shorthand)
            $inner = str_replace('<menu>',  '<x-slot name="menu">',  $inner);
            $inner = str_replace('</menu>', '</x-slot>', $inner);

            // Extrai <x-slot name="nome">...</x-slot> e converte em slots clássicos
            $namedSlots = '';
            $inner = preg_replace_callback(
                '/<x-slot\s+name=["\']([^"\']+)["\']\s*>([\s\S]*?)<\/x-slot>/s',
                function (array $sm) use (&$namedSlots): string {
                    $namedSlots .= "<?php \$__env->slot('{$sm[1]}'); ?>"
                                 . $sm[2]
                                 . '<?php $__env->endSlot(); ?>';
                    return ''; // remove do slot padrão
                },
                $inner
            );

            $paramsCompiled = $this->parseParams($match[2] ?? '', $match[1] ?? '');
            $template       = static::resolveComponentTemplate($match[1]);
            return '<?php $__env->startComponent(\'' . $template . '\', ' . $paramsCompiled . '); ?>'
                 . $namedSlots . $inner
                 . '<?php echo $__env->renderComponent(); ?>';
        };

        // Passo 1: self-closing <x-component ... />
        // (?!slot\b) — <x-slot> NUNCA é componente: é slot nomeado do pai.
        // No BladeOne o matcher inner-first comia <x-slot> como componente
        // ("Template not found: components.slot") — bug raiz dos named slots.
        $selfClose = '/<x-((?!slot\b)[a-z0-9.-]+)(' . $attr . ')\/>/s';
        $prev = '';
        $maxIter = 50;
        while ($value !== $prev && $maxIter-- > 0) {
            $prev = $value;
            $value = preg_replace_callback($selfClose, $compileSelfClosing, $value) ?? $value;
        }

        // Passo 2: componentes com corpo — innermost first (sem <x-*> filhos).
        // SEM regex sobre o corpo: a versão antiga casava o corpo inteiro com
        // (?:[^<]|<(?!x-…))* e em views grandes (~300KB, ex: showcase) o PCRE
        // estourava match_limit, preg_replace_callback devolvia NULL e o
        // `?? $value` ENGOLIA a falha — os wrappers mais externos
        // (<x-page-container>, <x-tabs>…) iam crus pro HTML. Aqui o regex só
        // casa a TAG DE ABERTURA (pequena); corpo e fechamento são resolvidos
        // por strpos/substr — O(n) em qualquer tamanho de view.
        $openTag = '/<x-((?!slot\b)[a-z0-9.-]+)(' . $attr . ')>/s';

        $offset = 0;
        while (preg_match($openTag, $value, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $full     = $m[0][0];
            $startPos = (int) $m[0][1];
            $tag      = $m[1][0];
            $attrStr  = $m[2][0];

            $bodyStart = $startPos + strlen($full);
            $closeTag  = '</x-' . $tag . '>';
            $closePos  = strpos($value, $closeTag, $bodyStart);

            if ($closePos === false) {
                // Sem fechamento (tag quebrada) — pula esta abertura.
                $offset = $startPos + 1;
                continue;
            }

            $body = substr($value, $bodyStart, $closePos - $bodyStart);

            // Leaf? Corpo não pode conter OUTRO componente <x-*> (x-slot é
            // permitido — vira named slot do componente sendo compilado).
            if (preg_match('/<\/?x-(?!slot\b)/', $body)) {
                // Tem filho componente — avança pra compilar o filho primeiro;
                // este wrapper é revisitado quando o offset resetar.
                $offset = $startPos + 1;
                continue;
            }

            $compiled = $compileWithBody([$full, $tag, $attrStr, $body]);
            $value    = substr($value, 0, $startPos)
                      . $compiled
                      . substr($value, $closePos + strlen($closeTag));

            // String mudou — recomeça do início (wrappers pais agora podem
            // ter virado leaf). Cada compile remove um <x-, então termina.
            $offset = 0;
        }

        return $value;
    }

    /**
     * Resolve o nome de um componente <x-tag> para o template Blade correspondente,
     * aplicando o namespace map do MAD.
     *
     * Regras (testadas em ordem de especificidade):
     *   - Tags comecando com "site-db-" → "site.components.db.{rest}"
     *     Ex: <x-site-db-blog-grid> → site.components.db.blog-grid
     *   - Tags comecando com "site-" → "site.components.{rest}"
     *     Ex: <x-site-hero> → site.components.hero
     *   - Qualquer outra tag → "components.{tag}" (comportamento original)
     *     Ex: <x-btn> → components.btn
     *
     * @param string $tag Nome do componente (apos o prefixo "x-")
     * @return string Dotted-path do template (sem extensao)
     */
    protected static function resolveComponentTemplate(string $tag): string
    {
        // Sub-namespace db-* dentro de site (variantes auto-query)
        // Precisa ser testado ANTES de site-* porque "site-db-X" tambem
        // comeca com "site-".
        if (str_starts_with($tag, 'site-db-')) {
            return 'site.components.db.' . substr($tag, 8); // tira 'site-db-'
        }

        // Namespace: site-*
        if (str_starts_with($tag, 'site-')) {
            return 'site.components.' . substr($tag, 5); // tira 'site-'
        }

        // Fallback: namespace admin (comportamento original)
        return 'components.' . $tag;
    }

    /**
     * Sobrescreve parseParams com parser manual que suporta:
     *  - name="value with spaces"   → string prop
     *  - :name="$phpExpr ?? ''"    → dynamic prop (PHP expression)
     *  - name='value'              → string prop (aspas simples)
     *  - name                      → boolean prop (true)
     *  - Atributos multi-linha e '>' dentro de valores entre aspas
     */
    /**
     * Mapa de diretivas mad:* → data-mad-* (HTML5 válido).
     * Usado em parseParams para converter diretivas em atributos HTML passthrough.
     */
    private static array $madDirectives = [
        'mad:click'          => 'data-mad-click',
        'mad:model.live'     => 'data-mad-model-live',
        'mad:model'          => 'data-mad-model',
        'mad:submit'         => 'data-mad-submit',
        'mad:change'         => 'data-mad-change',
        'mad:loading.remove' => 'data-mad-loading-remove',
        'mad:loading'        => 'data-mad-loading',
    ];

    /**
     * Converte kebab-case para camelCase (padrão Laravel Blade).
     * Ex: "group-by" → "groupBy", "legend-position" → "legendPosition"
     */
    private static function kebabToCamel(string $key): string
    {
        if (strpos($key, '-') === false) return $key;
        return lcfirst(str_replace('-', '', ucwords($key, '-')));
    }

    /**
     * `close-drawer` / `close-modal` sem nome → fecha a camada que CONTÉM o
     * botão (Mad.closeOverlayOf, mad.js). `Mad.closeDrawer('')` não serve: o
     * evento com nome vazio não casa a gaveta aberta por Mad.go (o nome dela é
     * o id da tela). `open-*` sem nome não tem o que abrir: nenhum atributo
     * (antes vazava a prop `openDrawer`=>true, ignorada do mesmo jeito).
     */
    private static function overlayShortcutAttr(string $verb, string $kind): ?string
    {
        return $verb === 'close'
            ? "onclick=\"Mad.closeOverlayOf(this, '{$kind}')\""
            : null;
    }

    /**
     * Tags cujo botão passa pelo gate de permissão por ação. Restrito de
     * propósito: emitir `permClass`/`permAction` em toda tag MAD mudaria a
     * compilação de ~100 componentes para nada — só o `<mad-btn>` consome.
     */
    private const PERM_TAGS = ['btn'];

    /**
     * @param string $tag Nome da tag sendo compilada ('btn', 'card'…), quando
     *                    o chamador sabe. Vazio = compilação avulsa.
     */
    protected function parseParams($params, string $tag = ''): string
    {
        $compiled     = [];
        $extraAttrs   = [];  // atributos HTML passthrough (mad:click etc.) — strings literais
        $rawPhpAttrs  = [];  // atributos com expressões Blade {{ }} — PHP runtime
        $hasAttrs     = false;
        $attrsIndex   = -1;
        $_hasNav      = false; // true se navigate/target foi processado
        // Botão que executa ação: a ação vira chave de permissão (ver ActionGuard).
        $_wantsPerm   = in_array($tag, self::PERM_TAGS, true);
        $pos        = 0;
        $len        = strlen($params);

        while ($pos < $len) {
            // Pula espaços/quebras
            if (preg_match('/\G\s+/', $params, $m, 0, $pos)) {
                $pos += strlen($m[0]);
                continue;
            }
            // Atributo com valor: (:?)name = "…" ou (:?)name = '…'
            // Inclui ':' e '.' no nome para suportar mad:click, mad:model.live etc.
            if (preg_match('/\G(:?)([a-zA-Z0-9_.:-]+)\s*=\s*(["\'])((?:(?!\3)[\s\S])*?)\3/s', $params, $m, 0, $pos)) {
                $pos  += strlen($m[0]);
                $key   = $m[2];
                $val   = $m[4];

                // Diretiva mad:* → acumula como atributo HTML passthrough
                if (isset(self::$madDirectives[$key])) {
                    $htmlKey = self::$madDirectives[$key];
                    // <mad-btn mad:click="onDelete(5)"> — o botão anuncia a ação
                    // que vai executar; o template pergunta ao perfil antes de
                    // desenhá-la. Só o nome do método interessa (os argumentos
                    // são da linha, não da permissão), e só quando ele é
                    // literal: valor montado em runtime não vira chave.
                    if ($_wantsPerm && $key === 'mad:click'
                        && preg_match('/^\s*(on[A-Za-z0-9_]*)\s*(?:\(|$)/', $val, $_pm)) {
                        $compiled[] = '"permAction"=>"' . $_pm[1] . '"';
                    }
                    if (preg_match('/\{\{.*?\}\}|\{!!.*?!!\}/', $val)) {
                        // Contém expressões Blade — gerar como expressão PHP avaliada em runtime
                        $phpExpr = preg_replace('/\{\{\s*(.+?)\s*\}\}/', '\' . ($1) . \'', $val);
                        $phpExpr = preg_replace('/\{!!\s*(.+?)\s*!!\}/', '\' . ($1) . \'', $phpExpr);
                        $rawPhpAttrs[] = "'{$htmlKey}=\"" . $phpExpr . "\"'";
                    } else {
                        $extraAttrs[] = $htmlKey . '="' . str_replace('"', '&quot;', $val) . '"';
                    }
                    continue;
                }

                // Estado condicional no cliente: disabled-when="{tipo_int} != 2"
                // (também readonly-/required-/visible-when) → diretiva Alpine
                // `x-mad-when:<kind>` no `attrs` do componente, avaliada contra os
                // valores do formulário (mad-ui.js). Só a forma LITERAL: `:x-when`
                // continua sendo prop PHP. O <mad-field-list-column> tem semântica
                // POR LINHA e é consumido antes pelo MadGridCompiler — a guarda
                // de tag é defensiva.
                if ($m[1] === '' && $tag !== 'field-list-column'
                    && preg_match('/^(disabled|readonly|required|visible)-when$/', $key, $wm)
                    && !preg_match('/\{\{|\{!!/', $val)) {
                    if (trim($val) !== '') {
                        $extraAttrs[] = 'x-mad-when:' . $wm[1] . '="'
                            . htmlspecialchars($val, ENT_COMPAT | ENT_HTML5, 'UTF-8', false) . '"';
                    }
                    continue;
                }

                // target="Class" ou navigate="Class" → delega ao MadAction (auto-detect wrapper + forward params)
                if ($key === 'target' || $key === 'navigate') {
                    $_hasNav = true;

                    // ALVO DINÂMICO — :target="$expr" (bind) ou target="{{ $expr }}"
                    // (interpolação). Sem isto o nome da classe virava string
                    // literal ('$c[\'target\']'), o MadAction gerava um onclick
                    // pra uma classe inexistente e TODO card/atalho renderizado
                    // dentro de @foreach virava link morto.
                    // Nessa forma o "::method" não é parseável — use method="…".
                    $navClassExpr = null;
                    if ($m[1] === ':' || (strpos($val, '{{') !== false && strpos($val, '::') === false)) {
                        $segs = [];
                        foreach (preg_split('/(\{\{.*?\}\})/s', $m[1] === ':' ? '{{'.$val.'}}' : $val,
                                            -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $seg) {
                            $segs[] = preg_match('/^\{\{\s*(.+?)\s*\}\}$/s', $seg, $sm)
                                ? '(' . $sm[1] . ')'
                                : "'" . addslashes($seg) . "'";
                        }
                        $navClassExpr = implode('.', $segs);
                    }

                    // Suporta navigate="Class::method" e navigate="Class::method({{ $id }})"
                    $navClass = $val;
                    $navMethodDefault = 'show';
                    $navMethodExplicito = false; // o autor nomeou o método?
                    $navMethodArgsExpr = null; // PHP expr extraída de args inline
                    if ($navClassExpr === null && strpos($val, '::') !== false) {
                        [$navClass, $navMethodPart] = explode('::', $val, 2);
                        // `(.*)` e não `(.+)`: o studio gravava `onShow()` para
                        // método sem parâmetro. Com `.+` isso não casava, caía no
                        // else e o método virava a string "onShow()" — que sai na
                        // URL como /app/{slug}/onShow%28%29 e a rota rejeita.
                        $navMethodExplicito = true;
                        if (preg_match('/^(\w+)\s*\((.*)\)$/', $navMethodPart, $mm)) {
                            $navMethodDefault = $mm[1];
                            $argRaw = trim($mm[2]);
                            // Parênteses vazios não são argumento — sem esta guarda
                            // o params vira ['id' => ()] e o Blade nem compila.
                            if ($argRaw !== '') {
                                $navMethodArgsExpr = preg_replace('/\{\{\s*(.+?)\s*\}\}/', '$1', $argRaw);
                            }
                        } else {
                            $navMethodDefault = $navMethodPart;
                        }
                    }

                    // Extrai method — suporta method="onEdit" e method="onEdit({{ $id }})"
                    // Atributo method explícito sobrescreve o ::method do navigate
                    $navMethod = $navMethodDefault;
                    if (preg_match('/\bmethod\s*=\s*["\']([^"\']+)["\']/', $params, $nm)) {
                        $navMethodExplicito = true;
                        $navMethodRaw = $nm[1];
                        // Mesma tolerância do navigate: method="onShow()" tem que
                        // resolver para o método 'onShow', sem parênteses.
                        if (preg_match('/^(\w+)\s*\((.*)\)$/', $navMethodRaw, $mm)) {
                            // method="onEdit({{ $fid }})" → método 'onEdit', arg como PHP expr
                            $navMethod = $mm[1];
                            $argRaw = trim($mm[2]);
                            if ($argRaw !== '') {
                                $navMethodArgsExpr = preg_replace('/\{\{\s*(.+?)\s*\}\}/', '$1', $argRaw);
                            }
                        } else {
                            $navMethod = $navMethodRaw;
                        }
                    }

                    // Extrai params — :params="expr" (bind PHP) ou params="{literal}" ou vazio
                    $navParamsExpr = '[]'; // expressão PHP para o array de params
                    if (preg_match('/(?:^|\s):params\s*=\s*"((?:[^"\\\\]|\\\\.)*)"/s', $params, $np)
                     || preg_match("/(?:^|\s):params\s*=\s*'((?:[^'\\\\]|\\\\.)*)'/s", $params, $np)) {
                        // :params="expr" → expr é PHP que retorna string JSON ou array
                        // Converte para array se necessário
                        $navParamsExpr = '(function($v){return is_array($v)?$v:(is_string($v)?json_decode($v,true)?:[]:[]);})((' . $np[1] . '))';
                    } elseif (preg_match('/\bparams\s*=\s*["\']([^"\']+)["\']/', $params, $np)) {
                        // params="{key: val}" → valor JS literal, parsear como JSON relaxado
                        $literal = $np[1];
                        // Tenta converter JS object literal para JSON: {key:val} → {"key":"val"}
                        $jsonish = preg_replace('/(\w+)\s*:/', '"$1":', $literal);
                        $jsonish = preg_replace('/:\s*(\d+)/', ': $1', $jsonish);
                        $navParamsExpr = 'json_decode(\'' . addslashes($jsonish) . '\', true) ?: []';
                    }

                    // Se method tinha args inline e nenhum params explícito, usar arg como 'id'
                    if ($navMethodArgsExpr !== null && $navParamsExpr === '[]') {
                        $navParamsExpr = "['id' => (" . $navMethodArgsExpr . ")]";
                    }

                    // Gera código PHP runtime: MadAction::to('Class','method', $params)->auto()
                    $navClassExpr ??= "'" . addslashes($navClass) . "'";
                    // Botão que abre OUTRA tela: quem manda na permissão é a tela
                    // de DESTINO (é ela que o perfil marca), não a que contém o
                    // botão. Sem método nomeado, abrir um formulário é abrir um
                    // cadastro em branco — ou seja, INCLUIR.
                    if ($_wantsPerm) {
                        $compiled[] = '"permClass"=>' . $navClassExpr;
                        $compiled[] = '"permAction"=>"'
                            . ($navMethodExplicito ? addslashes($navMethod) : 'insert') . '"';
                    }
                    $compiled[] = '"attrs"=>\\Mad\\Ui\\MadAction::to(' . $navClassExpr . ',\'' . addslashes($navMethod) . '\',' . $navParamsExpr . ')->auto()';
                    continue;
                }
                if ($_hasNav && ($key === 'method' || $key === 'params')) continue;

                // Overlay shortcuts: open-drawer="X" → onclick="Mad.openDrawer('X')"
                // Valor vazio (`close-drawer=""`) = a forma sem valor: não há nome
                // a casar — ver overlayShortcutAttr().
                if (preg_match('/^(open|close)-(drawer|modal)$/', $key, $om)) {
                    if (trim($val) === '') {
                        $shortcut = self::overlayShortcutAttr($om[1], $om[2]);
                        if ($shortcut !== null) $extraAttrs[] = $shortcut;
                        continue;
                    }
                    $method = $om[1] . ucfirst($om[2]);
                    $extraAttrs[] = "onclick=\"Mad.{$method}('{$val}')\"";
                    continue;
                }

                // Converte kebab-case → camelCase para PHP (ex: group-by → groupBy)
                $phpKey = self::kebabToCamel($key);

                if ($phpKey === 'attrs') {
                    $hasAttrs   = true;
                    $attrsIndex = count($compiled);
                }

                $compiled[] = $m[1]  // tem ':' → expressão PHP
                    ? '"' . $phpKey . '"=>' . $val
                    // Escapar SO os chars que quebram string PHP double-quoted:
                    // backslash, aspas duplas, cifrao. NAO escapar aspa simples
                    // (addslashes() colocaria \' que vira literal e contamina
                    // valores como SQL com strftime('%Y-%m', dt_pedido)).
                    : '"' . $phpKey . '"=>"' . str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $val) . '"';
                continue;
            }
            // Atributo booleano (sem valor): required, disabled, mad:loading…
            if (preg_match('/\G([a-zA-Z][a-zA-Z0-9_.:-]*)/', $params, $m, 0, $pos)) {
                $pos += strlen($m[0]);
                $key  = $m[1];

                // Diretiva booleana mad:loading → atributo HTML passthrough
                if (isset(self::$madDirectives[$key])) {
                    $extraAttrs[] = self::$madDirectives[$key];
                    continue;
                }

                // `close-drawer` / `close-modal` SEM valor (o "Cancelar" que as
                // skills e o gerador ensinam): virava a prop `closeDrawer`=>true,
                // que nenhum componente declara — botão morto.
                if (preg_match('/^(open|close)-(drawer|modal)$/', $key, $om)) {
                    $shortcut = self::overlayShortcutAttr($om[1], $om[2]);
                    if ($shortcut !== null) $extraAttrs[] = $shortcut;
                    continue;
                }

                // Converte kebab-case → camelCase para PHP
                $phpKey = self::kebabToCamel($key);
                $compiled[] = '"' . $phpKey . '"=>true';
                continue;
            }
            $pos++; // caractere desconhecido — pula
        }

        // Merge atributos HTML passthrough no prop 'attrs'
        if ($extraAttrs || $rawPhpAttrs) {
            $parts = [];
            if ($extraAttrs) {
                $extra = str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], implode(' ', $extraAttrs));
                $parts[] = '"' . $extra . '"';
            }
            foreach ($rawPhpAttrs as $expr) {
                $parts[] = $expr;
            }
            $attrsExpr = count($parts) === 1 ? $parts[0] : implode(' . \' \' . ', $parts);

            if ($hasAttrs && $attrsIndex >= 0) {
                // Concatena ao attrs existente
                $compiled[$attrsIndex] = preg_replace(
                    '/"$/',
                    '" . \' \' . ' . $attrsExpr,
                    $compiled[$attrsIndex]
                );
            } else {
                $compiled[] = '"attrs"=>' . $attrsExpr;
            }
        }

        return '[' . implode(',', $compiled) . ']';
    }
}

/**
 * MadRendered — wrapper fino que entrega HTML cru via show()/__toString().
 */
class MadRendered
{
    private string $html;

    public function __construct(string $html)
    {
        $this->html = $html;
    }

    public function show(): void
    {
        echo $this->html;
    }

    public function __toString(): string
    {
        return $this->html;
    }
}

/**
 * MadBlade — static facade around BladeOne with framework integration.
 *
 * Usage:
 *   // Render a Blade view and add to page
 *   parent::add(MadBlade::view('my-view', ['title' => 'Hello']));
 *
 *   // Render to string
 *   $html = MadBlade::render('my-view', ['items' => $items]);
 *
 *   // Render inline Blade template string
 *   $html = MadBlade::renderString('@if($ok) OK @endif', ['ok' => true]);
 *
 *   // Custom directive
 *   MadBlade::directive('myTag', fn($exp) => "<?php echo myHelper($exp); ?>");
 *
 * Views live in:  app/resources/views/
 * Cache lives in: tmp/blade-cache/
 */
class MadBlade
{
    private static ?\Illuminate\View\Factory $factory   = null;
    private static ?MadBladeCompiler         $compiler  = null;
    private static array $shared       = [];
    /**
     * Diretivas registradas por directive(), replicadas em todo compilador novo.
     * configure() zera a factory; sem este registro, @sitehead/@csrf e afins
     * sumiam do compilador seguinte e saíam no HTML como texto literal.
     */
    private static array $customDirectives = [];
    private static string $viewsPath   = '';
    private static string $cachePath   = '';

    // ─── Configuration ────────────────────────────────────────────────────────

    /**
     * Override default paths before first use.
     * ('mode' do BladeOne é aceito e ignorado — compat de chamada.)
     *
     * @param array{views?: string, cache?: string} $options
     */
    public static function configure(array $options): void
    {
        if (isset($options['views'])) {
            self::$viewsPath = $options['views'];
            self::$factory   = null;
        }
        if (isset($options['cache'])) {
            self::$cachePath = $options['cache'];
            self::$factory   = null;
        }
    }

    /**
     * Share a variable with all views (like Laravel's View::share).
     */
    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
        if (self::$factory !== null) {
            self::$factory->share($key, $value);
        }
    }

    /**
     * Register a custom Blade directive.
     */
    public static function directive(string $name, callable $handler): void
    {
        self::$customDirectives[$name] = $handler;
        self::factory(); // garante boot
        self::$compiler->directive($name, $handler);
    }

    // ─── Rendering ────────────────────────────────────────────────────────────

    /**
     * Render a Blade view file to an HTML string.
     * View files live in app/resources/views/ and use .blade.php extension.
     */
    public static function render(string $view, array $data = []): string
    {
        $factory = self::factory();
        $obLevel = ob_get_level();

        try {
            return $factory->make($view, array_merge(self::$shared, $data))->render();
        } catch (\Throwable $e) {
            // Limpa buffers abertos pelo render parcial e o estado de
            // componentes/sections do Factory antes de propagar.
            while (ob_get_level() > $obLevel) {
                ob_end_clean();
            }
            $factory->flushState();
            throw $e;
        }
    }

    /**
     * Render a Blade view and return a MadRendered element (for parent::add()).
     */
    public static function view(string $view, array $data = []): MadRendered
    {
        return new MadRendered(self::render($view, $data));
    }

    /**
     * Render an inline Blade template string to HTML.
     */
    public static function renderString(string $template, array $data = []): string
    {
        $factory = self::factory();

        $dir = self::resolvedCachePath() . '/inline';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        // Nome por hash do CONTEUDO: template mudou → arquivo novo → recompila.
        $path = $dir . '/' . sha1($template) . '.blade.php';
        if (!is_file($path)) {
            file_put_contents($path, $template);
        }

        $obLevel = ob_get_level();
        try {
            return $factory->file($path, array_merge(self::$shared, $data))->render();
        } catch (\Throwable $e) {
            while (ob_get_level() > $obLevel) {
                ob_end_clean();
            }
            $factory->flushState();
            throw $e;
        }
    }

    /**
     * Render an inline Blade template string and return a MadRendered element.
     */
    public static function viewString(string $template, array $data = []): MadRendered
    {
        return new MadRendered(self::renderString($template, $data));
    }

    // ─── MadComponent (Livewire-like) ─────────────────────────────────────────

    /**
     * Instancia e renderiza um componente reativo (MadComponent).
     *
     * Uso no controller:
     *   parent::add(MadBlade::component(ContadorComponent::class));
     *   parent::add(MadBlade::component(BuscaComponent::class, ['query' => $q]));
     *
     * Uso direto em view Blade:
     *   {!! \Mad\View\MadBlade::component(\App\Components\ContadorComponent::class) !!}
     *
     * @param  string $class   FQCN da classe que extende MadComponent
     * @param  array  $params  Parâmetros passados para o método mount()
     * @return MadRendered
     */
    public static function component(string $class, array $params = []): MadRendered
    {
        if (!class_exists($class) || !is_subclass_of($class, MadComponent::class)) {
            throw new \InvalidArgumentException("Classe {$class} não é um MadComponent válido.");
        }

        /** @var MadComponent $component */
        $component = new $class();
        $component->mount($params);

        return new MadRendered($component->_renderWrapped());
    }

    // ─── Component renderers ──────────────────────────────────────────────────
    // These are called by Blade directives (@madIcon, @madField, etc.)
    // but can also be used directly in PHP controllers.

    /**
     * Lucide icon element.
     * Requires Lucide UMD loaded on the page (mad-ui.js calls lucide.createIcons()).
     */
    public static function _icon(string $name, string $class = '', string $size = ''): string
    {
        $attrs = 'data-lucide="' . htmlspecialchars($name) . '"';
        if ($class) $attrs .= ' class="' . htmlspecialchars($class) . '"';
        if ($size)  $attrs .= ' style="width:' . $size . ';height:' . $size . '"';
        return "<i {$attrs}></i>";
    }

    /**
     * Form field wrapper with label, content slot, and optional hint.
     */
    public static function _field(string $label, string $content, bool $required = false, string $hint = ''): string
    {
        $req     = $required ? ' <span class="mad-required" aria-hidden="true">*</span>' : '';
        $hintHtml = $hint ? "\n  <p class=\"mad-field-hint\">{$hint}</p>" : '';
        return <<<HTML
<div class="mad-field">
  <label class="mad-label">{$label}{$req}</label>
  {$content}{$hintHtml}
</div>
HTML;
    }

    /**
     * Section header with optional icon and description.
     */
    public static function _section(string $title, string $description = '', string $icon = ''): string
    {
        $iconHtml = $icon
            ? '<i data-lucide="' . htmlspecialchars($icon) . '" class="mad-section-icon"></i>'
            : '';
        $descHtml = $description
            ? "\n    <p class=\"mad-text-muted\" style=\"margin:0;font-size:.85rem\">{$description}</p>"
            : '';
        return <<<HTML
<div class="mad-section-header">
  <div class="mad-section-title">
    {$iconHtml}
    <div>
      <strong>{$title}</strong>{$descHtml}
    </div>
  </div>
</div>
HTML;
    }

    /**
     * Alert / info box.
     * @param string $type  info | success | warning | error
     */
    public static function _alert(string $message, string $type = 'info', string $title = '', string $icon = ''): string
    {
        $defaultIcons = [
            'info'    => 'info',
            'success' => 'check-circle',
            'warning' => 'alert-triangle',
            'error'   => 'x-circle',
        ];
        $icon     = $icon ?: ($defaultIcons[$type] ?? 'info');
        $titleHtml = $title ? "<strong>{$title}</strong> " : '';
        return <<<HTML
<div class="mad-alert mad-alert-{$type}" role="alert">
  <i data-lucide="{$icon}"></i>
  <span>{$titleHtml}{$message}</span>
</div>
HTML;
    }

    /**
     * Badge / pill element.
     * @param string $variant  default | primary | success | warning | error | info | dark | white
     */
    public static function _badge(string $text, string $variant = 'default'): string
    {
        return '<span class="mad-badge mad-badge-' . $variant . '">' . $text . '</span>';
    }

    /**
     * Button element.
     * @param string $variant  default | primary | ghost | outline | destructive | link
     */
    public static function _btn(
        string $label,
        string $variant = 'default',
        string $icon = '',
        string $attrs = '',
        string $size = ''
    ): string {
        $iconHtml     = $icon ? '<i data-lucide="' . htmlspecialchars($icon) . '"></i> ' : '';
        $variantClass = $variant !== 'default' ? " mad-btn-{$variant}" : '';
        $sizeClass    = $size ? " mad-btn-{$size}" : '';
        return "<button class=\"mad-btn{$variantClass}{$sizeClass}\" {$attrs}>{$iconHtml}{$label}</button>";
    }

    /**
     * Visual separator, optionally with a label.
     */
    public static function _separator(string $label = ''): string
    {
        if ($label) {
            return "<div class=\"mad-separator\"><span>{$label}</span></div>";
        }
        return '<hr class="mad-separator">';
    }

    /**
     * Card wrapper.
     */
    public static function _card(string $content, string $class = ''): string
    {
        $extra = $class ? " {$class}" : '';
        return "<div class=\"mad-card{$extra}\">{$content}</div>";
    }

    /**
     * Inline CSS file with optional Vue-style scoped isolation.
     *
     * Modes:
     *   _css('path/file.css')               → embed inline (global)
     *   _css('path/file.css', true)         → scoped: prefix selectors with [data-css-{hash}]
     *   _css('path/file.css', 'meu-scope')  → scoped with custom name
     *
     * Scoped output wraps content in <span data-css-{hash} style="display:contents;">
     * and prefixes every selector — only descendants of the wrapper match.
     *
     * Path resolution: tries absolute, project-relative, and views-relative.
     * Returns HTML comment if file not readable (no fatal).
     */
    public static function _css(string $path, bool|string $scope = false): string
    {
        $resolved = self::_resolveCssPath($path);
        if (!$resolved) {
            return "<!-- @css not found: " . htmlspecialchars($path) . " -->";
        }

        $css = (string) file_get_contents($resolved);
        // Strip comments + collapse whitespace
        $css = preg_replace('!/\*.*?\*/!s', '', $css);
        $css = preg_replace('/\s+/', ' ', trim($css));
        $css = preg_replace('/\s*([{}:;,>+~])\s*/', '$1', $css);

        if ($scope === false) {
            $hash = substr(md5_file($resolved), 0, 8);
            return "<style data-css-src=\"" . htmlspecialchars(basename($path)) . "\" data-hash=\"{$hash}\">{$css}</style>";
        }

        // Scoped mode — prefix every selector with [data-css-{hash}]
        $hash      = is_string($scope) ? preg_replace('/[^a-z0-9-]/i', '', $scope) : substr(md5($resolved . microtime(true)), 0, 8);
        $attr      = "data-css-{$hash}";
        $scopedCss = self::_scopeCss($css, "[{$attr}]");

        return "<span {$attr} style=\"display:contents;\">"
             . "<style data-css-src=\"" . htmlspecialchars(basename($path)) . "\" data-scope=\"{$hash}\">{$scopedCss}</style>"
             . "</span>";
    }

    private static function _resolveCssPath(string $path): ?string
    {
        if (is_readable($path)) {
            return realpath($path);
        }
        $root = defined('PATH') ? PATH : dirname(__DIR__, 3);
        $candidates = [
            $root . '/' . ltrim($path, '/'),
            $root . '/app/resources/views/' . ltrim($path, '/'),
            $root . '/app/lib/include/builder/ui/' . ltrim($path, '/'),
        ];
        foreach ($candidates as $c) {
            if (is_readable($c)) {
                return realpath($c);
            }
        }
        return null;
    }

    /**
     * Prefix every CSS selector with $prefix.
     * Skips @-rules (@media, @keyframes, @font-face, etc) — recurses into @media bodies.
     * Skips :root and html/body selectors (would be unreachable inside scoped wrapper).
     */
    private static function _scopeCss(string $css, string $prefix): string
    {
        $out    = '';
        $len    = strlen($css);
        $i      = 0;
        $depth  = 0;

        while ($i < $len) {
            // Capture @-rule
            if ($css[$i] === '@') {
                $end = strpos($css, '{', $i);
                $sc  = strpos($css, ';', $i);
                if ($sc !== false && ($end === false || $sc < $end)) {
                    // @import, @charset — passthrough until ;
                    $out .= substr($css, $i, $sc - $i + 1);
                    $i = $sc + 1;
                    continue;
                }
                if ($end === false) break;
                $rule = substr($css, $i, $end - $i + 1);
                // For @media/@supports — emit + recurse into body
                $bodyStart = $end + 1;
                $bodyEnd   = self::_matchBrace($css, $end);
                if ($bodyEnd === -1) break;
                $body = substr($css, $bodyStart, $bodyEnd - $bodyStart);
                $out .= $rule . self::_scopeCss($body, $prefix) . '}';
                $i = $bodyEnd + 1;
                continue;
            }

            // Regular selector { ... }
            $end = strpos($css, '{', $i);
            if ($end === false) break;
            $selectors = substr($css, $i, $end - $i);
            $bodyEnd   = self::_matchBrace($css, $end);
            if ($bodyEnd === -1) break;
            $body = substr($css, $end, $bodyEnd - $end + 1);

            // Prefix each comma-separated selector
            $parts = array_map('trim', explode(',', $selectors));
            $prefixed = array_map(function (string $sel) use ($prefix): string {
                if ($sel === '' || preg_match('/^(:root|html|body)\b/', $sel)) {
                    return $sel;
                }
                // Handle :scope explicitly (just the wrapper itself)
                if (str_starts_with($sel, ':scope')) {
                    return $prefix . substr($sel, 6);
                }
                return $prefix . ' ' . $sel;
            }, $parts);

            $out .= implode(',', $prefixed) . $body;
            $i = $bodyEnd + 1;
        }

        return $out;
    }

    private static function _matchBrace(string $css, int $openPos): int
    {
        $depth = 0;
        $len   = strlen($css);
        for ($i = $openPos; $i < $len; $i++) {
            if ($css[$i] === '{') $depth++;
            elseif ($css[$i] === '}') {
                $depth--;
                if ($depth === 0) return $i;
            }
        }
        return -1;
    }

    // ─── Engine illuminate/view (singleton) ──────────────────────────────────

    private static function resolvedCachePath(): string
    {
        $root = defined('PATH') ? PATH : dirname(__DIR__, 3);
        $base = self::$cachePath ?: $root . '/storage/framework/mad-blade';

        // O cache é invalidado pelo mtime do .blade.php do APP. Mas metade da
        // saída vem do MadGridCompiler (framework): subir uma versão nova do
        // framework muda o COMPILADOR sem tocar as views do app, e o Blade
        // devolveria o compilado velho pra sempre (bug real: props novas de
        // <mad-db-blocks> sumindo silenciosamente após upgrade).
        // Carimbar a versão no caminho faz o upgrade trocar de pasta.
        return $base . '/v' . self::frameworkVersion();
    }

    /** Versão do mad-framework (arquivo VERSION do pacote), pra chavear o cache. */
    private static function frameworkVersion(): string
    {
        static $v = null;
        if ($v === null) {
            $file = dirname(__DIR__, 3) . '/VERSION';
            $raw  = is_file($file) ? trim((string) file_get_contents($file)) : '';
            $v    = preg_replace('/[^0-9A-Za-z._-]/', '', $raw) ?: 'dev';
        }

        return $v;
    }

    private static function factory(): \Illuminate\View\Factory
    {
        if (self::$factory !== null) {
            return self::$factory;
        }

        $root = defined('PATH') ? PATH : dirname(__DIR__, 3); // project root

        $views    = self::$viewsPath ?: $root . '/resources/views';
        $madViews = dirname(__DIR__) . '/views'; // views do próprio package
        $cache    = self::resolvedCachePath();

        foreach ([$views, $madViews, $cache] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }

        $files = new \Illuminate\Filesystem\Filesystem();

        self::$compiler = new MadBladeCompiler($files, $cache);

        $resolver = new \Illuminate\View\Engines\EngineResolver();
        $resolver->register('blade', fn () => new \Illuminate\View\Engines\CompilerEngine(self::$compiler, $files));
        $resolver->register('php',   fn () => new \Illuminate\View\Engines\PhpEngine($files));

        // app views primeiro (permite sobrescrever componentes mad)
        $finder = new \Illuminate\View\FileViewFinder($files, [$views, $madViews]);

        $dispatcher = new \Illuminate\Events\Dispatcher(new \Illuminate\Container\Container());

        self::$factory = new \Illuminate\View\Factory($resolver, $finder, $dispatcher);
        self::$factory->addExtension('blade.php', 'blade');

        foreach (self::$shared as $key => $value) {
            self::$factory->share($key, $value);
        }

        self::registerDirectives(self::$compiler);
        foreach (self::$customDirectives as $name => $handler) {
            self::$compiler->directive($name, $handler);
        }

        return self::$factory;
    }

    // ─── Blade directives ─────────────────────────────────────────────────────

    private static function registerDirectives(MadBladeCompiler $blade): void
    {
        // @props(['title' => '', 'size' => 'md', 'required' => false])
        // Declara as props do componente com defaults — equivalente a @php $var = $var ?? default; @endphp
        // Não sobrescreve variáveis já definidas (vindas do pai via parseParams).
        $blade->directive('props', function (string $exp): string {
            return "<?php foreach ({$exp} as \$__k => \$__v) { if (!isset(\$\$__k)) \$\$__k = \$__v; } unset(\$__k, \$__v); ?>";
        });

        // @madIcon('name')  or  @madIcon('name', 'css-class')  or  @madIcon('name', '', '20px')
        $blade->directive('madIcon', function (string $exp): string {
            return "<?php echo \\Mad\\View\\MadBlade::_icon({$exp}); ?>";
        });

        // @madField('Label', $inputHtml)  or  @madField('Label', $html, true, 'Hint text')
        $blade->directive('madField', function (string $exp): string {
            return "<?php echo \\Mad\\View\\MadBlade::_field({$exp}); ?>";
        });

        // @madSection('Title')  or  @madSection('Title', 'description', 'icon-name')
        $blade->directive('madSection', function (string $exp): string {
            return "<?php echo \\Mad\\View\\MadBlade::_section({$exp}); ?>";
        });

        // @madAlert('message')  or  @madAlert('message', 'success', 'Title', 'icon')
        $blade->directive('madAlert', function (string $exp): string {
            return "<?php echo \\Mad\\View\\MadBlade::_alert({$exp}); ?>";
        });

        // @madBadge('text')  or  @madBadge('text', 'success')
        $blade->directive('madBadge', function (string $exp): string {
            return "<?php echo \\Mad\\View\\MadBlade::_badge({$exp}); ?>";
        });

        // @madBtn('Label')  or  @madBtn('Save', 'primary', 'save', 'id="btn-save"')
        $blade->directive('madBtn', function (string $exp): string {
            return "<?php echo \\Mad\\View\\MadBlade::_btn({$exp}); ?>";
        });

        // @madSeparator  or  @madSeparator('Section label')
        $blade->directive('madSeparator', function (string $exp): string {
            $arg = trim($exp) ?: "''";
            return "<?php echo \\Mad\\View\\MadBlade::_separator({$arg}); ?>";
        });

        // @madCard($content)  or  @madCard($content, 'extra-class')
        $blade->directive('madCard', function (string $exp): string {
            return "<?php echo \\Mad\\View\\MadBlade::_card({$exp}); ?>";
        });

        // @css('path/file.css')                  → embed inline (global)
        // @css('path/file.css', true)            → Vue-style scoped (auto hash)
        // @css('path/file.css', 'meu-scope')     → scoped with custom name
        //
        // Reads file at compile-render time, minifies, wraps in <style>.
        // Scoped mode prefixes every selector with [data-css-{hash}] and wraps
        // content in <span data-css-{hash} style="display:contents;">.
        $blade->directive('css', function (string $exp): string {
            return "<?php echo \\Mad\\View\\MadBlade::_css({$exp}); ?>";
        });

        // @madAction('Class')  →  onclick com navegação completa (Mad.go + friendly URL)
        // @madAction('Class', 'method')
        // @madAction('Class', 'method', ['id' => 42])
        $blade->directive('madAction', function (string $exp): string {
            return "<?php echo \\Mad\\Ui\\MadAction::to({$exp})->onclick(); ?>";
        });

        // @madGet('Class')  →  onclick="Mad.get('Class@show', {})"  chamada parcial (modal/drawer)
        // @madGet('Class', 'method', ['id' => 42])
        $blade->directive('madGet', function (string $exp): string {
            return "<?php echo \\Mad\\Ui\\MadAction::to({$exp})->onget(); ?>";
        });

        // @madUrl('Class')  → só a URL, sem onclick
        $blade->directive('madUrl', function (string $exp): string {
            return "<?php echo \\Mad\\Ui\\MadAction::to({$exp})->url(); ?>";
        });

        // @madBind('prop') → <span data-mad-bind="prop">valor</span>
        // Permite update parcial do span sem re-renderizar o componente inteiro.
        $blade->directive('madBind', function (string $exp): string {
            $prop = trim($exp, "'\" ");
            return "<?php echo '<span data-mad-bind=\"{$prop}\">'
                . htmlspecialchars((string)(\${$prop} ?? ''), ENT_QUOTES)
                . '</span>'; ?>";
        });

        // @madWire(['prop1', 'prop2']) → mad-data='{"prop1":valor,"prop2":valor}'
        // Emite atributo mad-data (alias de x-data via mad-ui.js) com valores iniciais
        // dos props publicos do MadComponent. Combinado com a op bind do MadWire,
        // a UI fica reativa a mudancas server-side sem full re-render.
        $blade->directive('madWire', function (string $exp): string {
            return "<?php
                \$_madCmp = \\Mad\\Component\\MadRenderContext::getComponent();
                \$_madProps = {$exp};
                if (is_string(\$_madProps)) {
                    \$_madProps = array_map('trim', explode(',', \$_madProps));
                }
                \$_madState = [];
                foreach ((array) \$_madProps as \$_p) {
                    if (\$_madCmp && property_exists(\$_madCmp, \$_p)) {
                        \$_madState[\$_p] = \$_madCmp->\$_p;
                    } else {
                        \$_madState[\$_p] = null;
                    }
                }
                echo \"mad-data='\" . htmlspecialchars(json_encode(\$_madState, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS), ENT_QUOTES) . \"'\";
            ?>";
        });

        // @js($value) — serializa PHP value para JSON safe em atributo HTML.
        // Equivalente ao @js() do Laravel: escapa aspas/<>/& via JSON_HEX_* + htmlspecialchars,
        // produzindo expressao JS valida dentro de atributos x-data, x-init, :class, etc.
        //
        // Uso:
        //   <div x-data="{ tem: @js($var) }">              -> "Y"  /  ""  /  null
        //   <div x-init="init(@js($arr))">                  -> [1,2,3]
        //   <div x-data='{ user: @js($user) }'>             -> {"id":1,"nome":"..."}
        //
        // Suporta tanto delimitador " quanto ' no atributo HTML (JSON_HEX_QUOT + JSON_HEX_APOS).
        $blade->directive('js', function (string $exp): string {
            return "<?php echo htmlspecialchars("
                . "json_encode({$exp}, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT),"
                . " ENT_QUOTES, 'UTF-8'); ?>";
        });

        // @canAccess('Class') ... @endCanAccess
        // @canAccess('Class', 'method') ... @endCanAccess
        // Renderiza o bloco SOMENTE se o usuário logado tem acesso ao programa
        // (e, opcionalmente, à ação). Fonte da verdade única: PermissionGate.
        // NUNCA leia session('programs') / TSession na view — use esta diretiva.
        $blade->directive('canAccess', function (string $exp): string {
            return "<?php if (mad_can_access({$exp})): ?>";
        });

        $blade->directive('endCanAccess', function (string $exp): string {
            return "<?php endif; ?>";
        });
    }
}