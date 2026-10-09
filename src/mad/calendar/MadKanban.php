<?php
namespace Mad\Calendar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mad\Component\MadComponent;
use Mad\Database\QuerySource;
use Mad\Filters\MadFilterable;
use Mad\Filters\MadFiltersTrait;
use Mad\Form\ModelOptionsLoader;
use Mad\Http\MadResponse;
use Mad\Ui\MadMessage;


/**
 * MadKanban — Componente de quadro Kanban reativo com drag-drop, scroll infinito
 * e cards customizáveis.
 *
 * ┌─ Como usar ─────────────────────────────────────────────────────────────┐
 * │                                                                         │
 * │  class MeuKanban extends MadKanban                                      │
 * │  {                                                                      │
 * │      protected static string $wrapper = self::INTERNAL;                 │
 * │      protected string $model      = 'Negociacao';                       │
 * │      protected string $database   = 'business';                          │
 * │      protected string $stageModel = 'EtapaNegociacao';                  │
 * │      protected string $stageField = 'etapa_negociacao_id';              │
 * │      protected string $valueField = 'valor_total';                      │
 * │                                                                         │
 * │      public function renderCard(object $item): string { ... }           │
 * │                                                                         │
 * │      protected function view(): string|array                            │
 * │      {                                                                  │
 * │          return 'meu-kanban';                                           │
 * │      }                                                                  │
 * │  }                                                                      │
 * │                                                                         │
 * └─────────────────────────────────────────────────────────────────────────┘
 */
abstract class MadKanban extends MadComponent implements MadFilterable
{
    use MadFiltersTrait;

    // ── MadFiltersTrait — props que a trait espera no host ──────────────
    protected string $periodType    = 'none';
    protected string $dateField     = '';
    protected array  $periodFields  = ['mes' => 'mes', 'ano' => 'ano'];
    protected bool   $defaultToCurrentPeriod = false;
    protected bool   $usePresets    = false;
    protected bool   $rememberFilters = false;
    protected bool   $applyUnitFilter = false;
    protected string $unitField     = 'unit_id';
    protected array  $unitFields    = [];
    protected array  $skipAutoFilter = [];
    protected bool   $autoMergeDashFilters = true;

    // ── Configuração (não serializada, definida pelo subclass) ───────────

    /** Classe do model dos cards (short name ou FQCN). Ex: 'Negociacao' */
    protected string $model = '';

    /** Database connection. Default: MAIN_DATABASE */
    protected string $database = '';

    /** Classe do model para os stages (colunas). Ex: 'EtapaNegociacao' */
    protected string $stageModel = '';

    /** Campo FK no card model que referencia o stage. Ex: 'etapa_negociacao_id' */
    protected string $stageField = '';

    /** Campo do stage usado como titulo (renderizado no header da coluna). */
    protected string $stageTitleField = 'nome';

    /** Campo do stage usado como cor (bolinha no header da coluna). */
    protected string $stageColorField = 'cor';

    /**
     * Campo do stage usado p/ ordenação default dos stages (orderBy no builder).
     * Só vale se a coluna existir na tabela do stage; senão ordena pela chave.
     */
    protected string $stageOrderField = 'ordem';

    /** Direção da ordenação dos stages: 'asc' | 'desc'. */
    protected string $stageOrderDirection = 'asc';

    /** Reservado p/ futura implementação de drag das próprias colunas. */
    protected bool $stagesReorderable = false;

    /**
     * Campo de ordenação dos cards dentro do stage. Só é usado (ordenar e
     * regravar a ordem ao arrastar) quando a coluna EXISTE na tabela do model —
     * ver _cardOrderColumn(). Sem ela o board ordena pela chave e arrastar só
     * troca a etapa. O Studio não escreve `card-order-field` quando o campo
     * fica vazio: o default 'ordem' gravava numa coluna inexistente.
     */
    protected string $cardOrderField = 'ordem';

    /** Campo numérico para somar por coluna (ex: 'valor_total'). Vazio = sem totais. */
    protected string $valueField = '';

    /** Quantidade de cards por carregamento (load more). */
    protected int $cardsPerLoad = 20;

    /** Permite drag-drop de cards entre colunas. */
    protected bool $cardsDraggable = true;

    /** Renderiza scrollbar horizontal duplicada no topo do board (alem da nativa do rodape). */
    protected bool $topScroll = false;

    /**
     * Formato do totalizador da coluna (valueField). Usa MadChartFormatter.
     *
     * Default 'currency:R$ :2' é load-bearing p/ boards pt-BR existentes —
     * NÃO mudar; apps em outro locale sobrescrevem via prop/attr value-format.
     * Exemplos:
     *   'currency:R$ :2'   → R$ 1.234,56  (default)
     *   'currency:US$ :2:.:,'  → US$ 1,234.56
     *   'currency:€:2'     → € 1.234,56
     *   'numeric:0::: kg'  → 1.234 kg
     *   'integer'          → 1234
     *   'percent:1'        → 12,3%
     *   'abbreviate:1'     → 1,2K  /  2,3M
     */
    protected string $valueFormat = 'currency:R$ :2';

    // ── Customizacao do card default ─────────────────────────────────────
    // Para card 100% custom, setar $cardView ou override renderCard().
    //
    // Toda config aceita "dotted path" pra navegar relacoes:
    //   'nome'                       → $item->nome
    //   'cliente.nome'               → $item->cliente->nome
    //   'estado_pedido.cor'          → $item->estado_pedido->cor

    /** Path do titulo do card. Vazio = "Item #N". Ex: 'obs', 'cliente.nome'. */
    protected string $cardTitle = '';

    /**
     * Badges que aparecem no topo do card (canto sup esq).
     * Cada item: ['type' => 'id'|'state'|'text', 'path' => '...', 'color' => '...', 'colorPath' => '...']
     *
     * Defaults sensatos: 1 badge de #ID + 1 badge colorido de estado (auto via stageField).
     */
    protected array $cardBadges = [];

    /**
     * Linhas de metadata exibidas no corpo do card.
     * Cada item: ['icon' => 'lucide-name', 'path' => '...', 'subPath' => '...optional']
     *
     * Ex:
     *   [['icon' => 'circle-user-round', 'path' => 'cliente.nome', 'subPath' => 'cliente.fone'],
     *    ['icon' => 'user',              'path' => 'vendedor.nome']]
     */
    protected array $cardMeta = [];

    /**
     * Items do footer do card (alinhados left/right).
     * Cada item: ['type' => 'money'|'date'|'text', 'path' => '...', 'icon' => '...', 'prefix' => '...', 'format' => '...']
     *
     * Ex:
     *   [['type' => 'money', 'path' => 'valor_total', 'prefix' => 'R$'],
     *    ['type' => 'date',  'path' => 'dt_pedido',   'icon' => 'calendar', 'format' => 'd/m/Y']]
     */
    protected array $cardFooter = [];

    /** Blade view do card. Subclasse pode setar para template customizado. */
    protected string $cardView = 'components.kanban-card-default';

    // ── Dados de render (não serializados) ───────────────────────────────

    protected array $_stages      = [];
    protected array $_stageCards  = [];  // [stageId => [card objects]]
    protected array $_stageCounts = [];  // [stageId => int]
    protected array $_stageTotals = [];  // [stageId => float]
    private   bool  $_dataLoaded  = false;

    /**
     * Chave do cache de sessão da config inline deste board (hash da identidade
     * da config). PÚBLICA de propósito: entra no state do wire, então cada board
     * hidrata a PRÓPRIA config — antes a chave era só static::class e duas
     * páginas/abas com a mesma classe sobrescreviam a config uma da outra.
     */
    public string $kbCfgKey = '';

    /**
     * Config recebido do <mad-kanban> declarativo (MadKanbanCompiler).
     * Estrutura: ['card' => [...], 'clickTarget' => '...', 'stageActions' => [...],
     * 'toolbar' => 'base64'].
     * Quando presente, sobrescreve $cardTitle/$cardBadges/$cardMeta/$cardFooter
     * e cardClickTarget() do PHP.
     */
    protected ?array $_inlineConfig = null;

    // ── API — sobrescreva nas subclasses ─────────────────────────────────

    // Filtros do board: subclasse declara onSearch(?\Illuminate\Database\Eloquent\Builder $q)
    // e aplica $q->where(...) — chamado por-stage via _applyOnSearch (F5, builder-native).
    // O contrato da grid — onSearch() SEM argumento montando a closure abaixo —
    // também vale: é o que o stub do kanban gerado pela plataforma escreve.

    /**
     * Filtro dos cards montado por um `onSearch()` sem argumento:
     * closure fn(Builder $q). Mesmo contrato da MadDataGrid. Não serializada
     * (não é pública) — refeita a cada consulta.
     * @var callable|null
     */
    protected $searchQuery = null;

    /**
     * Renderiza o HTML de um card.
     *
     * Ordem de precedência:
     *   1. Override de subclasse — vence tudo
     *   2. <mad-kanban-card> com HTML "puro" (sem title/badges/meta/footer)
     *      → wrap mínimo + body do user (modo "bare")
     *   3. <mad-kanban-card> com trio configs (title/badges/meta/footer)
     *      → default template; se body também tem HTML custom, insere entre
     *        meta e footer via var $customHtml
     *
     * @param object $item  Record (model Eloquent) do card
     * @return string       HTML do card
     */
    public function renderCard(object $item): string
    {
        $card        = $this->_inlineConfig['card'] ?? [];
        $hasTemplate = !empty($card['template']);
        $hasTrio     = !empty($card['title']) || !empty($card['badges'])
                    || !empty($card['meta'])  || !empty($card['footer']);

        if ($hasTemplate && !$hasTrio) {
            return $this->_renderInlineCustomCard($item);
        }
        return $this->_renderDefaultCard($item);
    }

    /**
     * Renderiza um card usando o body custom do <mad-kanban-card> (HTML/Blade).
     *
     * Variáveis disponíveis no template:
     *   $item   Record do card
     *   $id     int|string — chave primária do card (UUID/código preservado)
     *   $kanban $this (a controller, p/ chamar $kanban->_resolvePath(...) etc)
     *   $attrs  string com data-* obrigatórios do card (drag/click/data-card-id...)
     *
     * O HTML é envolvido por <div class="mad-kanban-card" $attrs> automaticamente.
     */
    protected function _renderInlineCustomCard(object $item): string
    {
        $template = base64_decode((string) $this->_inlineConfig['card']['template']);
        $id       = $this->cardId($item);
        $attrs    = $this->cardAttrs($item);

        $body = \Mad\View\MadBlade::renderString($template, [
            'item'   => $item,
            'id'     => $id,
            'kanban' => $this,
            'attrs'  => $attrs,
        ]);

        return '<div class="mad-kanban-card" ' . $attrs . '>' . $body . '</div>';
    }

    /**
     * Card default — usa Blade `$cardView` (default: components.kanban-card-default).
     * Subclasse pode setar `$cardView` para template customizado OR override
     * `renderCard()` direto pra controle total via PHP.
     */
    protected function _renderDefaultCard(object $item): string
    {
        $vars = $this->_resolveCardVars($item);
        return \Mad\View\MadBlade::render($this->cardView, $vars);
    }

    /**
     * Resolve vars do card default a partir do record + props de config.
     *
     * Saida:
     *   item, id, attrs           (sempre)
     *   titulo                    (resolvido de $cardTitle ou fallback)
     *   badges  : list<array>     (resolved de $cardBadges)
     *   meta    : list<array>     (resolved de $cardMeta)
     *   footer  : list<array>     (resolved de $cardFooter)
     *
     * Quando o board é renderizado via <mad-kanban> declarativo, o
     * MadKanbanCompiler popula $this->_inlineConfig['card'] e essas configs
     * têm prioridade sobre as props PHP do controller.
     *
     * @return array<string,mixed>
     */
    protected function _resolveCardVars(object $item): array
    {
        $id    = $this->cardId($item);
        $attrs = $this->cardAttrs($item);

        // Card config: inline (Blade) > PHP props
        $card = $this->_inlineConfig['card'] ?? [];
        $cardTitle  = $card['title']  ?? $this->cardTitle;
        $cardBadges = $card['badges'] ?? $this->cardBadges;
        $cardMeta   = $card['meta']   ?? $this->cardMeta;
        $cardFooter = $card['footer'] ?? $this->cardFooter;

        // Titulo — modos:
        //   1. Vazio                       → fallback "Item #N"
        //   2. Contém `{` ... `}`          → render template (HTML cru)
        //      Aceita: {campo}, {relacao->campo}, {campo_id->X} (normalizado p/ {campo->X})
        //      Ex: "Pedido #{id} <br><small>{cliente->nome}</small>"
        //   3. Dotted path simples          → _resolvePath (output escapado)
        //      Ex: "cliente.nome"          → $item->cliente->nome
        $titulo     = '';
        $tituloHtml = false;
        if ($cardTitle) {
            if (str_contains($cardTitle, '{') && str_contains($cardTitle, '}')) {
                // escapeValues: o HTML do template é do dev (confiável); os
                // valores interpolados vêm do DB e são escapados — o blade
                // imprime com {!! !!} quando $tituloHtml.
                $titulo     = $this->_renderTemplate($item, $cardTitle, escapeValues: true);
                $tituloHtml = true;
            } else {
                $titulo = $this->_resolveValue($item, $cardTitle);
            }
        }
        if (trim(strip_tags($titulo)) === '') {
            $titulo     = mad_t('mad.kanban.item', ['id' => $id]);
            $tituloHtml = false;
        }

        // Badges — defaults sensatos se nada configurado
        $badgeConfigs = $this->_effectiveBadges((array) $cardBadges);
        $badges = [];
        foreach ($badgeConfigs as $cfg) {
            $b = $this->_resolveBadge($item, $cfg, $id);
            if ($b) $badges[] = $b;
        }

        // Meta rows — separadas em block (default, empilhadas) vs inline (chips horizontais)
        $meta       = [];
        $metaInline = [];
        foreach ($cardMeta as $cfg) {
            $m = $this->_resolveMeta($item, $cfg);
            if (!$m) continue;
            if (!empty($cfg['inline'])) {
                $metaInline[] = $m;
            } else {
                $meta[] = $m;
            }
        }

        // Meta groups — resolvidos com layout config
        $metaGroups = [];
        foreach (($card['metaGroups'] ?? []) as $g) {
            $items = [];
            foreach (($g['metas'] ?? []) as $cfg) {
                $m = $this->_resolveMeta($item, $cfg);
                if ($m) $items[] = $m;
            }
            if (empty($items)) continue;
            $metaGroups[] = [
                'direction' => (string) ($g['direction'] ?? 'column'),  // row | column
                'justify'   => (string) ($g['justify']   ?? 'start'),   // start | end | between | center | around
                'align'     => (string) ($g['align']     ?? 'start'),   // start | end | center
                'gap'       => (int)    ($g['gap']       ?? 6),
                'metas'     => $items,
            ];
        }

        // Footer items
        $footer = [];
        foreach ($cardFooter as $cfg) {
            $f = $this->_resolveFooter($item, $cfg);
            if ($f) $footer[] = $f;
        }

        // Custom HTML do <mad-kanban-card> (modo misto: trio + body custom)
        $customHtml = '';
        if (!empty($this->_inlineConfig['card']['template'])) {
            $template = base64_decode((string) $this->_inlineConfig['card']['template']);
            if (trim($template) !== '') {
                $customHtml = \Mad\View\MadBlade::renderString($template, [
                    'item'   => $item,
                    'id'     => $id,
                    'kanban' => $this,
                    'attrs'  => $attrs,
                ]);
            }
        }

        // Actions per-item — separadas em inline (botões diretos no card) e
        // grouped (vão para menu/dropdown conforme actionsMode).
        // Quando actionsMode === 'inline', todas viram inline.
        $actionsMode    = $card['actionsMode'] ?? 'menu';
        $actionsInline  = [];
        $actionsGrouped = [];
        foreach (($card['actions'] ?? []) as $cfg) {
            $a = $this->_resolveAction($item, $cfg, $id);
            if (!$a) continue;
            if (!empty($cfg['inline']) || $actionsMode === 'inline') {
                $actionsInline[] = $a;
            } else {
                $actionsGrouped[] = $a;
            }
        }

        return [
            'item'           => $item,
            'id'             => $id,
            'attrs'          => $attrs,
            'titulo'         => $titulo,
            'tituloHtml'     => $tituloHtml,
            'badges'         => $badges,
            'meta'           => $meta,
            'metaInline'     => $metaInline,
            'metaGroups'     => $metaGroups,
            'footer'         => $footer,
            'customHtml'     => $customHtml,
            'actions'        => $actionsGrouped,
            'actionsInline'  => $actionsInline,
            'actionsMode'    => $actionsMode,
        ];
    }

    /**
     * Resolve uma action declarada via <mad-kanban-action> para um item.
     *
     * Aplica display-condition + when-field/value/in/nin. Retorna null se a
     * action deve ser ocultada para esse item.
     *
     * Saída:
     *   label, icon, variant   — visual
     *   confirm                — string p/ confirmação client
     *   attrs                  — string com atributos HTML do <button>
     */
    protected function _resolveAction(object $item, array $cfg, int|string $id): ?array
    {
        $row = method_exists($item, 'toArray') ? $item->toArray() : (array) $item;

        // display-condition: 'Classe::metodo' ou qualquer callable (closure,
        // [obj, 'metodo'], …). Auto-detect via reflection (mesmo padrão de
        // GridAction::isVisible):
        //   1 arg  → fn(array $row): bool                — legado
        //   2 args → fn(?object $object, array $row): bool — record-compat
        // Só o nome do método (como o painel Visibilidade do Studio grava) =
        // método public static da própria tela.
        $dc = \Mad\Grid\GridAction::hostCallable($cfg['displayCondition'] ?? ($cfg['display-condition'] ?? null), [static::class]);
        if ($dc && is_callable($dc)) {
            $visible = (bool) self::_callRowCallback($dc, $item, $row);
            if (!$visible) return null;
        }

        // when-field + when-value | when-in | when-nin
        $wf = $cfg['whenField'] ?? ($cfg['when-field'] ?? '');
        if ($wf !== '') {
            $val = (string) ($row[$wf] ?? '');
            if (isset($cfg['whenValue']) || isset($cfg['when-value'])) {
                $expected = (string) ($cfg['whenValue'] ?? $cfg['when-value']);
                if ($val !== $expected) return null;
            }
            $in = $cfg['whenIn'] ?? ($cfg['when-in'] ?? null);
            if ($in !== null) {
                $set = is_array($in) ? $in : array_map('trim', explode(',', (string) $in));
                if (!in_array($val, $set, true)) return null;
            }
            $nin = $cfg['whenNin'] ?? ($cfg['when-nin'] ?? null);
            if ($nin !== null) {
                $set = is_array($nin) ? $nin : array_map('trim', explode(',', (string) $nin));
                if (in_array($val, $set, true)) return null;
            }
        }

        $label   = (string) ($cfg['label']   ?? '');
        $icon    = (string) ($cfg['icon']    ?? '');
        $variant = (string) ($cfg['variant'] ?? '');
        $confirm = (string) ($cfg['confirm'] ?? '');

        // target=Class::method({id})  OU  method=onAprovar
        $target = (string) ($cfg['target'] ?? '');
        $method = (string) ($cfg['method'] ?? '');
        $drawer = !empty($cfg['drawer']);

        $attrs = '';
        if ($target !== '') {
            $cls         = $target;
            $methodCall  = 'show';
            $argsRowPath = null;
            if (str_contains($target, '::')) {
                [$cls, $methodPart] = explode('::', $target, 2);
                if (preg_match('/^(\w+)\(\{(\w+)\}\)$/', $methodPart, $mm)) {
                    $methodCall  = $mm[1];
                    $argsRowPath = $mm[2];
                } else {
                    $methodCall = $methodPart;
                }
            }
            $argValue = $argsRowPath !== null
                ? (string) ($row[$argsRowPath] ?? $id)
                : (string) $id;
            $params = [($argsRowPath ?? 'id') => $argValue];
            $action = \Mad\Ui\MadAction::to($cls, $methodCall, $params);
            $attrs  = $drawer ? $action->onget() : $action->auto();
        } elseif ($method !== '') {
            $h     = htmlspecialchars($method, ENT_QUOTES);
            // Chave de texto (UUID/ULID/código) entra na expressão JS como
            // string JSON — crua viraria SyntaxError que o Alpine engole.
            $idJs  = htmlspecialchars(\Mad\Grid\MadDataGrid::rowIdJs($id), ENT_QUOTES);
            $attrs = 'data-mad-click="' . $h . '(' . $idJs . ')"';
            if ($confirm !== '') {
                $attrs .= ' data-mad-confirm="' . htmlspecialchars($confirm, ENT_QUOTES) . '"';
            }
        }

        return [
            'label'   => $label,
            'icon'    => $icon,
            'variant' => $variant,
            'confirm' => $confirm,
            'attrs'   => $attrs,
        ];
    }

    /**
     * Navega path "a.b.c" no objeto/array. Ex: 'cliente.nome' → $item->cliente->nome.
     * Retorna null se algum no for null/missing.
     */
    protected function _resolvePath(mixed $obj, string $path): mixed
    {
        if ($path === '' || $obj === null) return null;
        $parts = explode('.', $path);
        $cur = $obj;
        foreach ($parts as $p) {
            if ($cur === null) return null;
            if (is_object($cur)) {
                if (!isset($cur->{$p})) return null;
                $cur = $cur->{$p};
            } elseif (is_array($cur)) {
                if (!array_key_exists($p, $cur)) return null;
                $cur = $cur[$p];
            } else {
                return null;
            }
        }
        return $cur;
    }

    /**
     * Helper unificado para resolver paths em metas/badges/footers/title.
     * Aceita 3 sintaxes:
     *
     *   1. `cliente.nome`             → dot notation (via _resolvePath)
     *   2. `cliente->nome`            → arrow (render template)
     *   3. `{cliente->nome}`          → render template
     *   4. `{cliente_id->nome}`       → normalizado p/ `{cliente->nome}` (convenção MAD)
     *
     * Retorna string vazia se não resolver (sem exceção).
     */
    protected function _resolveValue(object $item, string $path): string
    {
        if ($path === '') return '';

        // Sintaxe {...} → render template (suporta {a->b} navegação)
        if (str_contains($path, '{')) {
            return $this->_renderTemplate($item, $path);
        }

        // Sintaxe arrow sem {}: cliente->nome → wrap em {} e render
        if (str_contains($path, '->')) {
            return $this->_renderTemplate($item, '{' . $path . '}');
        }

        // Dot notation tradicional
        $v = $this->_resolvePath($item, $path);
        return $v !== null ? (string) $v : '';
    }

    /**
     * Renderiza template `{a->b}` num record.
     *
     * - Record legado com render() nativo: delega pro método do record.
     * - Model Eloquent (sem render()): emula o idioma — normaliza
     *   `{a_id->b}`/`{a->b}` para path `a.b` e navega com data_get().
     *   Relações lazy resolvem via __get usando a conexão registrada em
     *   config/database.php (lazy, sem open explicito durante o render).
     *
     * Retorna string vazia em falha (sem exceção) — mesmo contrato do legado.
     *
     * $escapeValues: escapa os VALORES interpolados (não o markup do template)
     * — usar quando o resultado for impresso cru ({!! !!}), ex. título HTML.
     * O branch legado render() não suporta escape por-valor (record decide).
     */
    protected function _renderTemplate(object $item, string $template, bool $escapeValues = false): string
    {
        $normalized = self::_normalizeIdArrow($template);

        if (method_exists($item, 'render')) {
            try {
                return (string) $item->render($normalized);
            } catch (\Throwable $e) {
                return '';
            }
        }

        return (string) preg_replace_callback(
            '/\{([^{}]+)\}/',
            function (array $m) use ($item, $escapeValues): string {
                $path = str_replace('->', '.', trim($m[1]));
                try {
                    $v = data_get($item, $path);
                } catch (\Throwable $e) {
                    return '';
                }
                if ($v === null) return '';
                return $escapeValues ? e((string) $v) : (string) $v;
            },
            $normalized
        );
    }

    /**
     * Normaliza `{campo_id->algo}` → `{campo->algo}`.
     * Convenção MAD builder: campos FK terminados em `_id` referem-se à
     * relação sem o sufixo. Ex: `cliente_id` na tabela ⇒ relação `cliente`.
     */
    private static function _normalizeIdArrow(string $template): string
    {
        return preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)_id->([^}]+)\}/',
            fn(array $m): string => '{' . $m[1] . '->' . $m[2] . '}',
            $template
        );
    }

    /**
     * Badges default (quando $cardBadges esta vazio):
     *   - #ID muted (desativavel via `no-id-badge` no <mad-kanban-card>)
     *   - Estado colorido auto via stageField (desativavel via `no-state-badge`)
     */
    protected function _defaultBadges(): array
    {
        $card        = $this->_inlineConfig['card'] ?? [];
        $noId        = !empty($card['noIdBadge']);
        $noState     = !empty($card['noStateBadge']);
        $badges      = [];

        if (!$noId) {
            $badges[] = ['type' => 'id'];
        }

        if (!$noState && ($state = $this->_stateBadgeConfig()) !== null) {
            $badges[] = $state;
        }

        return $badges;
    }

    /**
     * Badge de estado: nome e cor da etapa do card, pela relação com a tabela
     * de etapas. null = o model não tem a relação (não há o que mostrar).
     */
    protected function _stateBadgeConfig(): ?array
    {
        $stageRel = $this->_inferStageRelation();
        if (!$stageRel) {
            return null;
        }
        $title = $this->stageTitleField !== '' ? $this->stageTitleField : 'nome';
        $color = $this->stageColorField !== '' ? $this->stageColorField : 'cor';

        return [
            'type'      => 'text',
            'path'      => "{$stageRel}.{$title}",
            'colorPath' => "{$stageRel}.{$color}",
        ];
    }

    /**
     * Badges que o card mostra: os declarados ou, sem nenhum, o par padrão
     * (#ID + Estado). `<mad-kanban-badge type="state">` declarado ganha o mesmo
     * caminho da etapa que o par padrão usa — antes ele caía no badge de texto
     * sem `path` e sumia do card (o canvas do Studio mostrava, o app não).
     * `color`/`color-path` declarados no badge continuam valendo.
     */
    protected function _effectiveBadges(array $cardBadges): array
    {
        if (empty($cardBadges)) {
            return $this->_defaultBadges();
        }
        $out = [];
        foreach ($cardBadges as $cfg) {
            if (is_array($cfg) && ($cfg['type'] ?? '') === 'state'
                && empty($cfg['path']) && empty($cfg['value'])) {
                $state = $this->_stateBadgeConfig();
                if ($state === null) {
                    continue;
                }
                if (!empty($cfg['colorPath'])) {
                    $state['colorPath'] = (string) $cfg['colorPath'];
                } elseif (!empty($cfg['color'])) {
                    unset($state['colorPath']);
                    $state['color'] = (string) $cfg['color'];
                }
                $cfg = $state;
            }
            $out[] = $cfg;
        }

        return $out;
    }

    protected function _resolveBadge(object $item, array $cfg, int|string $id): ?array
    {
        $type = $cfg['type'] ?? 'text';

        if ($type === 'id') {
            return ['label' => '#' . $id, 'muted' => true];
        }

        // Resolve label
        $label = '';
        if (!empty($cfg['path'])) {
            $label = $this->_resolveValue($item, (string) $cfg['path']);
        } elseif (!empty($cfg['value'])) {
            $label = (string) $cfg['value'];
        }
        if ($label === '') return null;

        // Resolve color — sanitizada: vai parar dentro de style="" no blade.
        $color = '#6b7280';
        if (!empty($cfg['colorPath'])) {
            $c = $this->_resolveValue($item, (string) $cfg['colorPath']);
            if ($c !== '') $color = $this->safeColor($c, $color);
        } elseif (!empty($cfg['color'])) {
            $color = $this->safeColor((string) $cfg['color'], $color);
        }

        return ['label' => $label, 'color' => $color, 'muted' => false];
    }

    /**
     * Valida um valor de cor CSS vindo de dados (DB/config) antes de ser
     * interpolado em style="" — fecha CSS injection (`red;background:url(...)`)
     * mantendo hex/rgb[a]/hsl[a]/nome. Fora do padrão → $fallback (literal
     * confiável do chamador, pode ser var(--token)).
     */
    public function safeColor(?string $color, string $fallback = ''): string
    {
        $c = trim((string) $color);
        if ($c === '') return $fallback;
        $ok = preg_match('/^#[0-9a-fA-F]{3,8}$/', $c)
           || preg_match('/^(rgb|rgba|hsl|hsla)\([0-9,.\s%\/]+\)$/i', $c)
           || preg_match('/^[a-zA-Z]{3,25}$/', $c);
        return $ok ? $c : $fallback;
    }

    protected function _resolveMeta(object $item, array $cfg): ?array
    {
        $val = !empty($cfg['path']) ? $this->_resolveValue($item, (string) $cfg['path']) : '';
        if ($val === '' || $val === null) return null;

        // Aplica formatador conforme type (usa MadChartFormatter — mesmo dos charts).
        $display = $this->_formatMetaValue($val, $cfg);

        $sub = '';
        if (!empty($cfg['subPath'])) {
            $sub = $this->_resolveValue($item, (string) $cfg['subPath']);
        }

        $position = strtolower((string) ($cfg['position'] ?? 'left'));
        if (!in_array($position, ['left', 'right', 'center'], true)) {
            $position = 'left';
        }

        return [
            'icon'     => $cfg['icon'] ?? 'circle',
            'value'    => $display,
            'sub'      => $sub,
            'muted'    => !empty($cfg['muted']),
            'position' => $position,
        ];
    }

    /**
     * Formata o valor do meta conforme cfg.
     *
     * Tipos suportados:
     *   text (default)     — passthrough
     *   money / currency   — prefix+decimals (ex: R$ 1.234,56)
     *   date               — formato DateTime (default d/m/Y)
     *   datetime           — d/m/Y H:i (ou custom format)
     *   integer            — 1234
     *   numeric            — DEC decimais
     *   percent            — DEC% (ex: 12,3%)
     *   abbreviate         — 1,2K / 2,3M / 1,5B
     *
     * Attrs respeitados:
     *   prefix, decimals, format
     */
    protected function _formatMetaValue(mixed $val, array $cfg): string
    {
        $type = strtolower((string) ($cfg['type'] ?? 'text'));
        if ($type === '' || $type === 'text') return (string) $val;

        // Date: format custom via DateTime (não passa por MadChartFormatter)
        if (in_array($type, ['date', 'datetime'], true)) {
            $fmt = (string) ($cfg['format'] ?? ($type === 'datetime' ? 'd/m/Y H:i' : 'd/m/Y'));
            try {
                return (new \DateTime((string) $val))->format($fmt);
            } catch (\Throwable $e) {
                return (string) $val;
            }
        }

        // Resto via MadChartFormatter
        $spec = match ($type) {
            'money', 'currency' => 'currency:' . ($cfg['prefix'] ?? 'R$ ') . ':' . ((int) ($cfg['decimals'] ?? 2)),
            'integer', 'int'    => 'integer',
            'numeric', 'number' => 'numeric:' . ((int) ($cfg['decimals'] ?? 2)),
            'percent', 'pct'    => 'percent:' . ((int) ($cfg['decimals'] ?? 1)),
            'abbreviate', 'abbr', 'short' => 'abbreviate:' . ((int) ($cfg['decimals'] ?? 1)),
            default             => '',
        };

        if ($spec === '') return (string) $val;
        return \Mad\Chart\MadChartFormatter::make($spec)($val);
    }

    protected function _resolveFooter(object $item, array $cfg): ?array
    {
        $type = $cfg['type'] ?? 'text';
        $val  = !empty($cfg['path']) ? $this->_resolveValue($item, (string) $cfg['path']) : '';
        if ($val === '' || $val === null) return null;

        $display = (string) $val;

        if ($type === 'money') {
            $prefix = $cfg['prefix'] ?? 'R$';
            $dec    = (int) ($cfg['decimals'] ?? 2);
            $display = htmlspecialchars($prefix) . '&nbsp;'
                     . number_format((float) $val, $dec, ',', '.');
        } elseif ($type === 'date') {
            $fmt = $cfg['format'] ?? 'd/m/Y';
            try {
                $display = (new \DateTime((string) $val))->format($fmt);
            } catch (\Throwable $e) {
                $display = (string) $val;
            }
        }

        return [
            'type'    => $type,
            'icon'    => $cfg['icon'] ?? '',
            'display' => $display,
            'class'   => $cfg['class'] ?? '',
        ];
    }

    /**
     * Nome da relação do card com a etapa, a partir do stageField.
     * Ex: stageField='status_os_id' → 'statusOs' (o model gerado declara a
     * relação em camelCase) ou 'status_os' (convenção antiga, snake).
     *
     * Devolvia sempre o snake: com etapa de nome composto o badge de estado
     * procurava `$card->status_os`, que não é relação nenhuma, e sumia do card.
     * Agora vale o método que existe no model e devolve uma relação; sem
     * nenhum, fica o snake (comportamento anterior).
     */
    private function _inferStageRelation(): string
    {
        if ($this->_stageRelationName !== null) {
            return $this->_stageRelationName;
        }
        $f = $this->stageField;
        if ($f === '') {
            return $this->_stageRelationName = '';
        }
        $base = str_ends_with($f, '_id') ? substr($f, 0, -3) : $f;

        try {
            $modelClass = $this->_resolveModelFqcn($this->model);
            $probe      = new $modelClass();
            foreach (array_unique([$base, \Illuminate\Support\Str::camel($base)]) as $cand) {
                if (method_exists($probe, $cand)
                    && $probe->{$cand}() instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
                    return $this->_stageRelationName = $cand;
                }
            }
        } catch (\Throwable) {
            // model não resolve ou o método exige argumentos: fica o snake
        }

        return $this->_stageRelationName = $base;
    }

    /** Memo de _inferStageRelation() (por instância; não vai pro estado). */
    private ?string $_stageRelationName = null;

    /**
     * Hook executado após mover um card entre colunas.
     * Ex: salvar histórico de mudança de etapa.
     *
     * Sobrescreva com `int|string $cardId` para quadros com chave de texto —
     * alargar o parâmetro na subclasse é permitido; o pai fica `int` porque
     * páginas geradas antes de 5.73 declaram `int` e alargar aqui quebraria
     * todas na carga da classe.
     */
    protected function afterCardMove(int $cardId, string $oldStageId, string $newStageId): void
    {
        // Override para logging/auditoria.
    }

    /** Assinatura do hook por classe: 'call' | 'noop' | 'narrow'. */
    private static array $_afterMoveMode = [];

    /** Classes já avisadas (o warn é 1× por classe, não 1× por move). */
    private static array $_afterMoveWarned = [];

    /**
     * Chama afterCardMove() só quando a assinatura EFETIVA aceita a chave.
     *
     * O stub do gerador emite `afterCardMove(int $cardId, …)` em toda página
     * de kanban já publicada. Alargar o parâmetro no PAI faria o PHP fatalar
     * na carga dessas classes (parâmetro é contravariante: o filho pode
     * alargar, nunca estreitar). Então o pai fica `int` e o despacho decide:
     * chave inteira chama sempre; chave de texto só chama quem declarou
     * `int|string` (ou `mixed`/sem tipo). Quem ficou em `int` é PULADO com um
     * aviso — melhor um gancho ignorado e logado do que um TypeError que o
     * catch do onCardMove transformaria em "não foi possível mover".
     */
    private function _dispatchAfterCardMove(int|string $cardId, string $oldStageId, string $newStageId): void
    {
        // Serial que chegou como string pela wire ("42") continua int pro hook.
        if (is_string($cardId) && preg_match('/^(?:0|[1-9][0-9]*)$/', $cardId)
            && (string) (int) $cardId === $cardId) {
            $cardId = (int) $cardId;
        }
        if (is_int($cardId)) {
            $this->afterCardMove($cardId, $oldStageId, $newStageId);
            return;
        }

        $cls = static::class;
        self::$_afterMoveMode[$cls] ??= self::_afterMoveModeFor($cls);

        if (self::$_afterMoveMode[$cls] === 'call') {
            $this->afterCardMove($cardId, $oldStageId, $newStageId);
            return;
        }
        if (self::$_afterMoveMode[$cls] === 'noop') {
            return; // ninguém sobrescreveu — o hook do pai é vazio
        }

        if (!isset(self::$_afterMoveWarned[$cls])) {
            self::$_afterMoveWarned[$cls] = true;
            Log::warning(
                "[MadKanban] {$cls}::afterCardMove declarado com int em quadro de "
                . 'chave de texto — troque para int|string. O gancho foi ignorado neste move.'
            );
        }
    }

    /** @return 'call'|'noop'|'narrow' */
    private static function _afterMoveModeFor(string $cls): string
    {
        try {
            $ref = new \ReflectionMethod($cls, 'afterCardMove');
        } catch (\Throwable) {
            return 'noop';
        }

        // Não sobrescrito: o hook do pai é no-op, não há o que avisar.
        if ($ref->getDeclaringClass()->getName() === self::class) {
            return 'noop';
        }

        $params = $ref->getParameters();
        if ($params === []) {
            return 'narrow';
        }

        $type = $params[0]->getType();
        if ($type === null) {
            return 'call'; // sem tipo declarado aceita qualquer coisa
        }

        $names = $type instanceof \ReflectionNamedType
            ? [$type->getName()]
            : array_map(fn ($t) => $t->getName(), $type->getTypes());

        foreach ($names as $name) {
            if (in_array(strtolower($name), ['string', 'mixed'], true)) {
                return 'call';
            }
        }

        return 'narrow';
    }

    /**
     * Classe do form para abrir ao clicar no card.
     * Retorne null para desabilitar click.
     *
     * Quando definido via <mad-kanban click-target="..."> no Blade, esse valor
     * tem prioridade sobre o override PHP.
     */
    public function cardClickTarget(): ?string
    {
        $ct = $this->_inlineConfig['clickTarget'] ?? null;
        return ($ct !== null && $ct !== '') ? (string) $ct : null;
    }

    /**
     * Query customizada para carregar os cards do board (hook de extensão).
     * Retorno não-vazio substitui a auto-query: deve conter os cards do board
     * INTEIRO — eles são particionados por coluna via $stageField. Array vazio
     * (default) usa a auto-query builder-native (F4d).
     */
    protected function query(): array
    {
        return [];
    }

    /**
     * Recarrega os dados do kanban (mantém os filtros ativos).
     */
    public function onReload(): void
    {
        $this->loadData();
    }

    // ── Lifecycle ────────────────────────────────────────────────────────

    public function mount(array $params = []): void
    {
        // MadFiltersTrait — init form + carrega filter state
        $this->_initFiltersForm();
        if ($this->defaultToCurrentPeriod && $this->mes === '' && $this->ano === '') {
            $this->mes = date('m');
            $this->ano = date('Y');
        }
        $this->loadFilterSession();
        $req = array_merge($_GET ?? [], $_POST ?? []);
        $this->hydrateFiltersFromArray($req);
        $this->hydrateFiltersFromArray($params);
        $this->syncFormFields();

        // Carrega dados só se props criticas presentes. Quando o Blade tem
        // <mad-kanban model="..." stage-model="..." ...>, deixa o
        // _renderInlineKanban configurar e carregar (mount fica idempotente).
        if ($this->model !== '' && $this->stageModel !== '') {
            $this->loadData();
        }
    }

    /**
     * `kbCfgKey` aponta para a config deste quadro guardada na sessão e viaja no
     * estado cifrado: só o servidor a escreve. Como prop pública, ela também
     * aceitava valor mandado pelo navegador junto dos campos — e a requisição
     * seguinte montava esta tela com a config de OUTRA aberta na mesma sessão.
     */
    protected function _lockedStateProps(): array
    {
        return array_merge(parent::_lockedStateProps(), ['kbCfgKey']);
    }

    public function hydrate(): void
    {
        // O state do wire serializa só props PÚBLICAS — o board config
        // (model/stageModel/stageField/etc, protected) vem dos attrs do
        // <mad-kanban> no Blade e é cacheado em sessão pelo
        // _renderInlineKanban. Restaura antes de recarregar: sem isso,
        // kanbans declarativos hidratam com model vazio e a query fatala.
        // kbCfgKey (pública, veio no state) aponta a config DESTE board;
        // fallback: ponteiro "última config da classe" (boards antigos).
        $cfg = $this->kbCfgKey !== ''
            ? session('mad_kb_cfg.' . $this->kbCfgKey)
            : null;
        if (!is_array($cfg)) {
            $latest = session('mad_kb_cfg_latest.' . static::class);
            $cfg    = is_string($latest) && $latest !== '' ? session('mad_kb_cfg.' . $latest) : null;
        }
        if (is_array($cfg)) {
            $this->_applyInlineKanbanConfig($cfg);
        }

        if ($this->model !== '' && $this->stageModel !== '') {
            $this->loadData();
        }
    }

    /** MadComponent hook — espelha mad:model no form->fields. */
    public function updated(string $prop, mixed $value): void
    {
        parent::updated($prop, $value);
        $this->_filtersUpdatedHook($prop, $value);
    }

    /** MadFiltersTrait hook — apos apply, recarrega cards. */
    protected function applyFiltersChanged(): void
    {
        $this->loadData();
    }

    // ── Data loading ─────────────────────────────────────────────────────

    public function loadData(): void
    {
        // Fail-fast: stageField/valueField entram interpolados em selectRaw
        // (_computeStageTotals) — valida identificador antes de qualquer query.
        $this->_assertIdentifier($this->stageField, 'stageField');
        if ($this->valueField !== '') {
            $this->_assertIdentifier($this->valueField, 'valueField');
        }

        // onSearch é aplicado por-stage em _buildBaseCardQuery (F4d) — não global.
        $this->_loadStages();
        $this->_loadAllStageCards();
        $this->_computeStageTotals();
        $this->_dataLoaded = true;
    }

    private function _loadStages(): void
    {
        $stageClass = $this->_resolveModelFqcn($this->stageModel);
        $field = $this->stageOrderField !== '' ? $this->stageOrderField : 'ordem';
        if (!$this->_modelHasColumn($stageClass, $field)) {
            $field = $this->_keyName($stageClass);   // tabela de etapas sem a coluna de ordem
        }
        $dir   = strtolower($this->stageOrderDirection) === 'desc' ? 'desc' : 'asc';
        $q     = $stageClass::query()->orderBy($field, $dir);
        $this->_stages = QuerySource::recordsFromQuery($q) ?: [];
    }

    private function _loadAllStageCards(): void
    {
        $modelClass = $this->_resolveModelFqcn($this->model);
        $orderField = $this->_cardOrderColumn($modelClass) ?? $this->_keyName($modelClass);

        foreach ($this->_stages as $stage) {
            $this->_stageCards[$this->stageId($stage)] = [];
        }

        // Hook query(): retorno não-vazio é o board inteiro — particionado por
        // stageField. (Antes era chamado por-stage e o MESMO array caía em
        // todas as colunas.)
        $custom = $this->query();
        if (!empty($custom)) {
            foreach ($custom as $c) {
                $sid = (string) ($c->{$this->stageField} ?? '');
                if (array_key_exists($sid, $this->_stageCards)) {
                    $this->_stageCards[$sid][] = $c;
                }
            }
        } else {
            foreach ($this->_stages as $stage) {
                $sid = $this->stageId($stage);

                // Card query builder-native (F4d): base (período/unit/auto-filters +
                // onSearch) + filtro do stage + ordenação + cards-per-load.
                $q = $modelClass::query();
                $this->_buildBaseCardQuery($q);
                $q->where($this->stageField, '=', $sid)
                  ->orderBy($orderField, 'asc')
                  ->limit($this->cardsPerLoad);

                $this->_stageCards[$sid] = QuerySource::recordsFromQuery($q) ?: [];
            }
        }

        // Anti-N+1: as relações que o card vai navegar (path="cliente.nome",
        // badge color-path="estado.cor"…) são conhecidas ANTES do render —
        // eager load em lote (1 SELECT IN por relação pro board inteiro) em
        // vez de 1 SELECT por card × relação.
        $this->_eagerLoadCardRelations($modelClass);
    }

    /** Eager load em lote das relações referenciadas pela config dos cards. */
    private function _eagerLoadCardRelations(string $modelClass): void
    {
        try {
            $relations = $this->_cardRelationNames($modelClass);
            if (empty($relations)) {
                return;
            }

            $all = [];
            foreach ($this->_stageCards as $cards) {
                foreach ($cards as $card) {
                    if ($card instanceof \Illuminate\Database\Eloquent\Model) {
                        $all[] = $card;
                    }
                }
            }
            if (!empty($all)) {
                (new \Illuminate\Database\Eloquent\Collection($all))->load($relations);
            }
        } catch (\Throwable $e) {
            // eager load é otimização: falhou → lazy load por card segue valendo
        }
    }

    /**
     * Extrai das configs do card (title/badges/metas/footers, inline > props)
     * os prefixos de path que são RELAÇÕES Eloquent do model. Aceita as 3
     * sintaxes de path ('cliente.nome', '{cliente->nome}', 'cliente_id->nome').
     */
    private function _cardRelationNames(string $modelClass): array
    {
        $card    = $this->_inlineConfig['card'] ?? [];
        $strings = [(string) ($card['title'] ?? $this->cardTitle)];

        $collect = function ($cfgs) use (&$strings, &$collect): void {
            foreach ((array) $cfgs as $cfg) {
                if (!is_array($cfg)) {
                    continue;
                }
                foreach (['path', 'colorPath', 'color-path', 'subPath', 'sub-path', 'value'] as $k) {
                    if (!empty($cfg[$k]) && is_string($cfg[$k])) {
                        $strings[] = $cfg[$k];
                    }
                }
                if (!empty($cfg['metas'])) { // <mad-kanban-meta-group>
                    $collect($cfg['metas']);
                }
            }
        };
        $collect($this->_effectiveBadges((array) ($card['badges'] ?? $this->cardBadges)));
        $collect($card['meta']   ?? $this->cardMeta);
        $collect($card['footer'] ?? $this->cardFooter);

        $probe     = new $modelClass;
        $relations = [];
        foreach ($strings as $s) {
            if ($s === '' || !preg_match_all('/\{?\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*(?:->|\.)/', $s, $m)) {
                continue;
            }
            foreach ($m[1] as $name) {
                // convenção MAD: {cliente_id->nome} navega a relação 'cliente'
                foreach (array_unique([$name, (string) preg_replace('/_id$/', '', $name)]) as $cand) {
                    if ($cand === '' || isset($relations[$cand]) || !method_exists($probe, $cand)) {
                        continue;
                    }
                    try {
                        if ($probe->{$cand}() instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
                            $relations[$cand] = true;
                        }
                    } catch (\Throwable $e) {
                        // método não é relação (exige args etc) — ignora
                    }
                }
            }
        }
        return array_keys($relations);
    }

    /**
     * Contagem + total por coluna numa ÚNICA query agrupada (anti-N+1):
     * o legado fazia, POR estágio, um COUNT + um SELECT * só pra somar o
     * value-field em PHP — 2~3 queries × coluna e hidratação inútil.
     */
    private function _computeStageTotals(): void
    {
        $modelClass = $this->_resolveModelFqcn($this->model);
        $hasValue   = !empty($this->valueField);

        foreach ($this->_stages as $stage) {
            $sid = $this->stageId($stage);
            $this->_stageCounts[$sid] = 0;
            if ($hasValue) {
                $this->_stageTotals[$sid] = 0.0;
            }
        }

        $q = $modelClass::query();
        $this->_buildBaseCardQuery($q);

        $stageField = $this->_assertIdentifier($this->stageField, 'stageField');
        $valueField = $hasValue ? $this->_assertIdentifier($this->valueField, 'valueField') : '';
        $select = "{$stageField} as __sid, count(*) as __cnt"
            . ($hasValue ? ", sum({$valueField}) as __sum" : '');
        // toBase(), não getQuery(): getQuery() devolve a consulta SEM os global
        // scopes do model — num app Multi-unidade a coluna somava os cards das
        // outras unidades (e os excluídos), enquanto os cards e o recálculo
        // depois de arrastar (Eloquent count/sum) já eram escopados.
        $rows = $q->toBase()->selectRaw($select)->groupBy($this->stageField)->get();

        foreach ($rows as $row) {
            $sid = (string) ($row->__sid ?? '');
            if (!array_key_exists($sid, $this->_stageCounts)) {
                continue; // estágio fora do board
            }
            $this->_stageCounts[$sid] = (int) ($row->__cnt ?? 0);
            if ($hasValue) {
                $this->_stageTotals[$sid] = (float) ($row->__sum ?? 0);
            }
        }
    }

    /** Base do card query (F4d): período + unit + auto-filters + onSearch builder. */
    private function _buildBaseCardQuery($q): void
    {
        $model = $this->model !== '' ? $this->_resolveModelFqcn($this->model) : null;
        if ($this->autoMergeDashFilters) {
            $this->applyPeriodoToQuery($q, $model);
            $this->applyUnitToQuery($q, $model);
            $this->applyAutoFiltersToQuery($q, $model);
        }
        $this->_applyOnSearch($q, $model);
        $this->_applySearchQuery($q);
    }

    /**
     * Aplica a closure `$this->searchQuery` que um `onSearch()` sem argumento
     * montou — o stub do kanban gerado escreve os filtros da busca ali, igual
     * à listagem. Antes só a MadDataGrid a aplicava: no kanban a busca era
     * ignorada e o board mostrava todos os cards.
     */
    private function _applySearchQuery($q): void
    {
        if (!is_callable($this->searchQuery)) {
            return;
        }
        try {
            ($this->searchQuery)($q);
        } catch (\Throwable $e) {
            // Mesma política do _applyOnSearch: loga e segue (a tela não cai).
            error_log('[MadKanban::searchQuery] ' . static::class . ': ' . $e->getMessage());
        }
    }

    // ── Actions ──────────────────────────────────────────────────────────

    /**
     * Chamado pelo JS quando um card é dropado (entre colunas ou reordenado na mesma).
     */
    public function onCardMove(int|string $cardId, string $newStageId, string $orderJson): MadResponse
    {
        $db = $this->_cardConnection();
        $oldStageId   = '';
        $changedStage = false;

        // 1. Salvar mudancas
        try {
            DB::connection($db)->transaction(function () use ($cardId, $newStageId, $orderJson, &$oldStageId, &$changedStage) {
                $modelClass = $this->_resolveModelFqcn($this->model);

                // Escopo do board (unit/período/auto-filters/onSearch): só um
                // card VISÍVEL para o usuário pode ser movido — find() cru
                // permitiria mover registro de fora do escopo (IDOR).
                $scoped = $modelClass::query();
                $this->_buildBaseCardQuery($scoped);
                $record = $scoped->find($cardId);
                if (!$record) {
                    throw new \Exception(mad_t('mad.kanban.record_not_found', ['id' => $cardId, 'model' => $modelClass]));
                }
                $oldStageId = (string) $record->{$this->stageField};

                $changedStage = ($oldStageId !== $newStageId);

                // Atualizar stage se mudou de coluna
                if ($changedStage) {
                    $record->{$this->stageField} = $newStageId;
                }

                // Atualizar ordem dentro do stage — só com uma coluna de ordem
                // que EXISTE na tabela (e não é a PK: sobrescreveria o id de
                // cada card). Sem ela, arrastar só troca a etapa: o default
                // 'ordem' gravava numa coluna inexistente e o move inteiro era
                // recusado ("no such column: ordem").
                $orderedIds  = json_decode($orderJson, true) ?: [];
                $pkName      = method_exists($record, 'getKeyName')
                    ? (string) $record->getKeyName()
                    : (defined("{$modelClass}::PRIMARYKEY") ? (string) constant("{$modelClass}::PRIMARYKEY") : 'id');
                $orderColumn = $this->_cardOrderColumn($modelClass);
                $canReorder  = $orderColumn !== null
                            && $orderColumn !== $pkName
                            && !empty($orderedIds);

                if ($canReorder) {
                    // 1 whereIn escopado em vez de N find() — e ids fora do
                    // escopo do usuário falham igual a card inexistente.
                    $scopedIn = $modelClass::query();
                    $this->_buildBaseCardQuery($scopedIn);
                    $rows = $scopedIn->whereIn($pkName, $orderedIds)->get()->keyBy($pkName);
                    foreach ($orderedIds as $seq => $id) {
                        $r = ((string) $id === (string) $cardId) ? $record : ($rows[$id] ?? null);
                        if (!$r) {
                            throw new \Exception(mad_t('mad.kanban.record_not_found', ['id' => $id, 'model' => $modelClass]));
                        }
                        $r->{$orderColumn} = $seq + 1;
                        $r->save();
                    }
                } elseif ($changedStage) {
                    $record->save();
                }

                if ($changedStage) {
                    $this->_dispatchAfterCardMove($cardId, $oldStageId, $newStageId);
                }
            });
        } catch (\Throwable $e) {
            // Move recusado: toast de erro + op p/ o JS desfazer o move
            // otimista do drag (o MadWire nunca rejeita a promise — o
            // rollback é orientado a op). Partial response: sem full render.
            // Erro técnico (banco/PHP) vira aviso amigável e o detalhe vai só pro
            // log — o diálogo mostrava o SQL e o caminho do arquivo do banco.
            // Recusa de propósito (regra do app) passa com o texto dela.
            $err = MadMessage::error(
                mad_t('mad.kanban.error_title'),
                \Mad\Ui\MadUserError::message($e, mad_t('mad.kanban.move_failed'), static::class . '::onCardMove')
            );
            $err->ops[] = ['op' => 'kanban_move_failed', 'cardId' => (string) $cardId];
            // Contadores REAIS das colunas envolvidas, DEPOIS do rollback do
            // card: o valor do servidor é o último a valer na tela, mesmo com
            // um JS antigo em cache que desfazia os contadores do jeito errado
            // (a tela ficava 3/1 com 2/2 cards).
            $this->_appendStageCountOps($err, [$oldStageId, $newStageId]);
            $this->_skipFullRender = true;
            return $err;
        }

        // 2. Se mudou de coluna, recomputar contadores/totais
        $response = new MadResponse();

        if ($changedStage) {
            $this->_appendStageCountOps($response, [$oldStageId, $newStageId]);
        }

        // Re-render card SEMPRE (mesmo same-stage reorder) — garante:
        //   1. afterCardMove() pode ter alterado campos derivados (refleti no DOM)
        //   2. response tem pelo menos 1 op → handler usa partial path (linha
        //      562 do MadComponentHandler exige !empty($allOps)). Sem isso,
        //      same-stage reorder cai no full HTML — desperdicio gigante.
        try {
            $response->manageCard($cardId, static::class, $this);
        } catch (\Throwable $e) {
            // silencia falha de render — card otimistico no DOM continua valido
        }

        // Skip full render — drag-drop ja atualiza DOM via JS, so precisa de ops parciais
        $this->_skipFullRender = true;

        return $response;
    }

    /**
     * Chamado pelo JS quando o scroll atinge o final de uma coluna.
     */
    public function onLoadMore(string $stageId, int $offset): MadResponse
    {
        $modelClass = $this->_resolveModelFqcn($this->model);
        $orderField = $this->_cardOrderColumn($modelClass) ?? $this->_keyName($modelClass);

        $q = $modelClass::query();
        $this->_buildBaseCardQuery($q);
        $q->where($this->stageField, '=', $stageId)
          ->orderBy($orderField, 'asc')
          ->limit($this->cardsPerLoad)
          ->offset($offset);
        $items = QuerySource::recordsFromQuery($q) ?: [];

        $html = '';
        foreach ($items as $item) {
            $html .= $this->renderCard($item);
        }

        // Skip full render — action retorna apenas op append_cards (partial response)
        $this->_skipFullRender = true;

        // SEMPRE emite a op (mesmo página vazia): sem ela o cliente nunca
        // recebe mad-kanban-loaded e o loadingMore da coluna trava true p/
        // sempre. hasMore autoritativo: página cheia ⇒ provavelmente há mais.
        $response = new MadResponse();
        $response->ops[] = [
            'op'      => 'append_cards',
            'stageId' => $stageId,
            'html'    => $html,
            'hasMore' => count($items) === $this->cardsPerLoad,
        ];
        return $response;
    }

    /**
     * Chamado pelo JS quando o usuário arrasta o cabeçalho de uma coluna
     * (`stages-reorderable`). Grava a sequência nova (1..N) na coluna de ordem
     * da tabela de etapas (`stage-order-field`, default `ordem`) e chama
     * afterStageMove(). Antes a opção do painel não fazia nada: o framework
     * guardava a flag e nenhum código a lia.
     *
     * A tela já está na ordem nova (move otimista do drag): sucesso devolve só
     * ops parciais. Recusa mostra o erro e redesenha o board na ordem do banco
     * — é o próprio rollback, sem op dedicada no cliente.
     *
     * Ordem `desc` grava a sequência invertida, para a tela continuar na ordem
     * em que o usuário soltou.
     */
    public function onStageMove(string $stageId, string $orderJson): MadResponse
    {
        try {
            if (!$this->stagesReorderable) {
                throw new \RuntimeException(mad_t('mad.kanban.stages_locked'));
            }
            $stageClass = $this->_resolveModelFqcn($this->stageModel);
            $field      = $this->_stageOrderColumn($stageClass);
            if ($field === null) {
                throw new \RuntimeException(mad_t('mad.kanban.stage_move_failed'));
            }

            $ordered = array_values(array_unique(array_map('strval', array_filter(
                (array) (json_decode($orderJson, true) ?: []),
                static fn ($v) => is_scalar($v) && (string) $v !== ''
            ))));
            if (!in_array((string) $stageId, $ordered, true)) {
                throw new \RuntimeException(mad_t('mad.kanban.stage_not_found', ['id' => $stageId]));
            }

            // Só etapas DESTE quadro (o board lista a tabela inteira de etapas;
            // id de fora é recusado em vez de gravado às cegas).
            $pk   = $this->_keyName($stageClass);
            $rows = $stageClass::query()->whereIn($pk, $ordered)->get()->keyBy(fn ($r) => (string) $r->{$pk});
            foreach ($ordered as $id) {
                if (!isset($rows[$id])) {
                    throw new \RuntimeException(mad_t('mad.kanban.stage_not_found', ['id' => $id]));
                }
            }

            $desc = strtolower($this->stageOrderDirection) === 'desc';
            $n    = count($ordered);
            $conn = (new $stageClass())->getConnectionName() ?: (string) config('database.default');
            DB::connection($conn)->transaction(function () use ($ordered, $rows, $field, $desc, $n, $stageId) {
                foreach ($ordered as $seq => $id) {
                    $r = $rows[$id];
                    $r->{$field} = $desc ? $n - $seq : $seq + 1;
                    $r->save();
                }
                $this->afterStageMove((string) $stageId, $ordered);
            });
        } catch (\Throwable $e) {
            // Redesenha o board na ordem do banco = desfaz o move otimista.
            $this->forceFullRender();
            return MadMessage::error(
                mad_t('mad.kanban.error_title'),
                \Mad\Ui\MadUserError::message($e, mad_t('mad.kanban.stage_move_failed'), static::class . '::onStageMove')
            );
        }

        // A tela já está na ordem nova. Uma op (sem efeito no cliente) mantém a
        // resposta no caminho parcial — sem ops, o handler manda o HTML inteiro.
        $this->_skipFullRender = true;
        $response = new MadResponse();
        $response->ops[] = ['op' => 'kanban_stages_moved', 'stageId' => (string) $stageId];
        return $response;
    }

    /**
     * Hook executado depois que o usuário reordena as colunas do quadro, dentro
     * da mesma transação da gravação (lançar desfaz a ordem nova).
     *
     * @param string       $stageId    etapa que foi arrastada
     * @param list<string> $orderedIds ids das etapas na ordem nova, da esquerda para a direita
     */
    protected function afterStageMove(string $stageId, array $orderedIds): void
    {
        // Override para logging/auditoria.
    }

    /**
     * Coluna de ordem das ETAPAS que dá pra gravar: `stage-order-field`
     * (default `ordem`), identificador válido, diferente da chave e existente
     * na tabela. null = o quadro ordena pela chave e não há o que gravar.
     */
    private function _stageOrderColumn(string $stageClass): ?string
    {
        $field = trim($this->stageOrderField) !== '' ? trim($this->stageOrderField) : 'ordem';
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $field) || $field === $this->_keyName($stageClass)) {
            return null;
        }

        return $this->_modelHasColumn($stageClass, $field) ? $field : null;
    }

    // ── Colunas de ordem / contadores ────────────────────────────────────

    /**
     * Coluna de ordem dos cards que dá pra usar DE VERDADE: a declarada em
     * card-order-field (default 'ordem'), desde que seja um identificador,
     * não seja a chave e EXISTA na tabela do model. null = sem coluna de ordem:
     * o board ordena pela chave e arrastar só troca a etapa.
     */
    private function _cardOrderColumn(string $modelClass): ?string
    {
        $field = trim($this->cardOrderField);
        if ($field === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $field)) {
            return null;
        }
        if ($field === $this->_keyName($modelClass)) {
            return null;
        }

        return $this->_modelHasColumn($modelClass, $field) ? $field : null;
    }

    /**
     * Conexão da TRANSAÇÃO do move = a do model dos cards, que é onde o save
     * grava. Era `database` (vazio → 'business'): num app com o model em outra
     * conexão (o gerado usa a do diagrama, ex.: 'assistec') a transação não
     * cobria as gravações — um afterCardMove que lançava deixava a etapa
     * trocada no banco enquanto a tela desfazia o move.
     */
    private function _cardConnection(): string
    {
        try {
            $cls  = $this->_resolveModelFqcn($this->model);
            $name = (new $cls())->getConnectionName();
            if (is_string($name) && $name !== '') {
                return $name;
            }
        } catch (\Throwable) {
            // model não resolve: o find() escopado adiante falha com a mensagem certa
        }

        return (string) config('database.default');
    }

    /** Chave primária do model (fallback 'id'). */
    private function _keyName(string $modelClass): string
    {
        try {
            return (string) (new $modelClass())->getKeyName() ?: 'id';
        } catch (\Throwable) {
            return 'id';
        }
    }

    /**
     * A coluna existe na tabela do model (na conexão dele)? Uma introspecção
     * por tabela e por request (DataScope::memo — um worker persistente não
     * guarda o schema de ontem). Falha de introspecção conta como "não existe":
     * o pior caso é o board sem reordenar, nunca o SQL quebrado.
     */
    private function _modelHasColumn(string $modelClass, string $column): bool
    {
        if ($column === '' || !class_exists($modelClass)) {
            return false;
        }
        try {
            $model = new $modelClass();
            $conn  = $model->getConnectionName();
            $table = $model->getTable();
        } catch (\Throwable) {
            return false;
        }

        $cols = \Mad\Database\DataScope::memo('kanban.columns', ($conn ?? '') . '|' . $table, static function () use ($conn, $table): array {
            try {
                return array_map('strtolower', \Illuminate\Support\Facades\Schema::connection($conn)->getColumnListing($table));
            } catch (\Throwable) {
                return [];
            }
        });

        return in_array(strtolower($column), $cols, true);
    }

    /**
     * Ops com o contador (e o total, se houver value-field) REAIS das etapas
     * informadas — card query escopada (base + onSearch + etapa). Best-effort:
     * falha mantém o que está na tela.
     *
     * @param list<string> $stageIds
     */
    private function _appendStageCountOps(MadResponse $response, array $stageIds): void
    {
        $stageIds = array_values(array_unique(array_filter(array_map('strval', $stageIds), fn ($s) => $s !== '')));
        if ($stageIds === []) {
            return;
        }

        $stageCounts = [];
        $stageTotals = [];
        try {
            $modelClass = $this->_resolveModelFqcn($this->model);
            foreach ($stageIds as $sid) {
                // Card query do stage builder-native (base + onSearch + stage).
                $countQ = $modelClass::query();
                $this->_buildBaseCardQuery($countQ);
                $countQ->where($this->stageField, '=', $sid);
                $stageCounts[$sid] = (int) $countQ->count();

                if (!empty($this->valueField)) {
                    // sum() no SQL (grammar-wrapped) — antes hidratava TODOS
                    // os records da coluna só pra somar em PHP.
                    $sumQ = $modelClass::query();
                    $this->_buildBaseCardQuery($sumQ);
                    $sumQ->where($this->stageField, '=', $sid);
                    $stageTotals[$sid] = (float) $sumQ->sum($this->valueField);
                }
            }
        } catch (\Throwable $e) {
            // recompute é best-effort — falha mantém contadores do DOM
        }

        foreach ($stageCounts as $sid => $count) {
            $response->html("[data-stage-count=\"{$sid}\"]", (string) $count);
        }
        if (!empty($this->valueField)) {
            foreach ($stageTotals as $sid => $total) {
                $response->html("[data-stage-summary=\"{$sid}\"]",
                    $this->formatStageTotal((float) $total));
            }
        }
    }

    // ── Helpers para template ────────────────────────────────────────────

    /**
     * Gera os atributos HTML obrigatórios para um card (drag, click, data attrs).
     * Usar no renderCard(): <div class="mad-kanban-card" {!! $this->cardAttrs($item) !!}>
     */
    public function cardAttrs(object $item): string
    {
        $id      = $this->cardId($item);
        // Id dentro de expressão JS passa pelo MESMO contrato do grid
        // (MadDataGrid::rowIdJs): inteiro sai cru, qualquer outra chave sai
        // como string JSON. Cru, um UUID viraria SyntaxError que o Alpine
        // engole — o card ficava sem clique e sem drag, calado.
        $idJs    = htmlspecialchars(\Mad\Grid\MadDataGrid::rowIdJs($id), ENT_QUOTES);
        $idAttr  = htmlspecialchars((string) $id, ENT_QUOTES);
        // stage id vem do DB — escapado no atributo; os handlers Alpine leem
        // $el.dataset.stageId em vez de interpolar o valor dentro da expressão
        // JS (um id com aspas quebraria/injetaria na expressão).
        $stageId = htmlspecialchars((string) $item->{$this->stageField}, ENT_QUOTES);
        $drag    = $this->cardsDraggable ? 'true' : 'false';

        return implode(' ', [
            "data-card-id=\"{$idAttr}\"",
            "data-stage-id=\"{$stageId}\"",
            "draggable=\"{$drag}\"",
            "@dragstart=\"onDragStart(\$event, {$idJs}, \$el.dataset.stageId)\"",
            "@dragend=\"onDragEnd(\$event)\"",
            "@click=\"onCardClick(\$event, {$idJs})\"",
        ]);
    }

    /**
     * Chave da ETAPA (a coluna do board) como string — a chave REAL do model
     * de etapas (getKey()), não `->id`: tabela de etapas com PK `codigo`
     * deixava toda coluna com id vazio (cards fora das colunas, contadores 0,
     * drop sem destino).
     */
    public function stageId(object $stage): string
    {
        $key = method_exists($stage, 'getKey') ? $stage->getKey() : ($stage->id ?? null);

        return $key === null ? '' : (string) $key;
    }

    /**
     * Chave primária do card, SEM cast — UUID/ULID/código sobrevive.
     *
     * Model Eloquent → getKey() (respeita $primaryKey customizado); qualquer
     * outro objeto (stdClass de query crua, record legado) → propriedade `id`.
     * Antes o id era `(int) $item->id`: com PK de texto todo card virava 0 e o
     * board inteiro emitia `onCardClick($event, 0)`.
     */
    public function cardId(object $item): int|string
    {
        $key = method_exists($item, 'getKey') ? $item->getKey() : ($item->id ?? null);

        if ($key === null) return 0;
        if (is_int($key))  return $key;

        return (string) $key;
    }

    /**
     * Builder base + auto-filters + search mesclados (F4d) — para metric cards na
     * view via :query (Query Builder Eloquent).
     */
    public function getBaseQueryMerged(): \Illuminate\Database\Eloquent\Builder
    {
        $modelClass = $this->_resolveModelFqcn($this->model);
        $q = $modelClass::query();
        $this->_buildBaseCardQuery($q);
        return $q;
    }

    /** @return object[] */
    public function getStages(): array
    {
        return $this->_stages;
    }

    /** @return object[] */
    public function getCardsForStage(string $stageId): array
    {
        return $this->_stageCards[$stageId] ?? [];
    }

    public function getStageCounts(): array
    {
        return $this->_stageCounts;
    }

    public function getStageTotals(): array
    {
        return $this->_stageTotals;
    }

    public function getCardsPerLoad(): int
    {
        return $this->cardsPerLoad;
    }

    public function isDraggable(): bool
    {
        return $this->cardsDraggable;
    }

    public function hasTopScroll(): bool
    {
        return $this->topScroll;
    }

    public function getStageTitleField(): string { return $this->stageTitleField !== '' ? $this->stageTitleField : 'nome'; }
    public function getStageColorField(): string { return $this->stageColorField !== '' ? $this->stageColorField : 'cor';  }
    public function isStagesReorderable(): bool  { return $this->stagesReorderable; }

    /**
     * Formata um valor (total da coluna) conforme $valueFormat.
     * Usa MadChartFormatter — mesmo sistema de format dos charts.
     */
    public function formatStageTotal(float $value): string
    {
        if ($value == 0.0) return '';
        return \Mad\Chart\MadChartFormatter::make($this->valueFormat)($value);
    }

    /**
     * Resolve actions declaradas via <mad-kanban-stage-action> para um stage.
     * Aplica display-condition + when-* (mesma lógica de _resolveAction).
     *
     * Filtra out as marcadas com flag `empty-state` (renderizadas separadamente
     * via getStageEmptyActions quando a coluna está vazia).
     *
     * @return list<array{label:string,icon:string,variant:string,confirm:string,attrs:string}>
     */
    public function getStageActions(object $stage): array
    {
        $cfgs = $this->_inlineConfig['stageActions'] ?? [];
        if (empty($cfgs)) return [];

        $out = [];
        foreach ($cfgs as $cfg) {
            if (!empty($cfg['emptyState'])) continue;
            $a = $this->_resolveStageAction($stage, $cfg);
            if ($a) $out[] = $a;
        }
        return $out;
    }

    /**
     * Resolve actions marcadas com flag `empty-state` — renderizadas como
     * placeholder dashed dentro do body da coluna quando ela está vazia.
     *
     * @return list<array{label:string,icon:string,variant:string,confirm:string,attrs:string}>
     */
    public function getStageEmptyActions(object $stage): array
    {
        $cfgs = $this->_inlineConfig['stageActions'] ?? [];
        if (empty($cfgs)) return [];

        $out = [];
        foreach ($cfgs as $cfg) {
            if (empty($cfg['emptyState'])) continue;
            $a = $this->_resolveStageAction($stage, $cfg);
            if ($a) $out[] = $a;
        }
        return $out;
    }

    /**
     * Renderiza HTML do <mad-kanban-toolbar> (Blade arbitrário acima do board).
     * Variaveis no escopo: $kanban (a controller).
     */
    public function getToolbarHtml(): string
    {
        $b64 = $this->_inlineConfig['toolbar'] ?? '';
        if ($b64 === '') return '';
        $tpl = base64_decode((string) $b64);
        if (trim($tpl) === '') return '';
        return \Mad\View\MadBlade::renderString($tpl, ['kanban' => $this]);
    }

    /**
     * Resolve uma stage action (similar a _resolveAction de card, mas com
     * stage como contexto). `{stageId}` no target é substituído pelo id.
     */
    protected function _resolveStageAction(object $stage, array $cfg): ?array
    {
        $row = method_exists($stage, 'toArray') ? $stage->toArray() : (array) $stage;
        $sid = $this->stageId($stage);

        // display-condition (aceita o record do stage; string ou callable)
        $dc = \Mad\Grid\GridAction::hostCallable($cfg['displayCondition'] ?? ($cfg['display-condition'] ?? null), [static::class]);
        if ($dc && is_callable($dc)) {
            $visible = (bool) self::_callRowCallback($dc, $stage, $row);
            if (!$visible) return null;
        }

        // when-field
        $wf = $cfg['whenField'] ?? ($cfg['when-field'] ?? '');
        if ($wf !== '') {
            $val = (string) ($row[$wf] ?? '');
            if (isset($cfg['whenValue']) || isset($cfg['when-value'])) {
                $expected = (string) ($cfg['whenValue'] ?? $cfg['when-value']);
                if ($val !== $expected) return null;
            }
            $in = $cfg['whenIn'] ?? ($cfg['when-in'] ?? null);
            if ($in !== null) {
                $set = is_array($in) ? $in : array_map('trim', explode(',', (string) $in));
                if (!in_array($val, $set, true)) return null;
            }
            $nin = $cfg['whenNin'] ?? ($cfg['when-nin'] ?? null);
            if ($nin !== null) {
                $set = is_array($nin) ? $nin : array_map('trim', explode(',', (string) $nin));
                if (in_array($val, $set, true)) return null;
            }
        }

        $label   = (string) ($cfg['label']   ?? '');
        $icon    = (string) ($cfg['icon']    ?? '');
        $variant = (string) ($cfg['variant'] ?? '');
        $confirm = (string) ($cfg['confirm'] ?? '');
        $target  = (string) ($cfg['target']  ?? '');
        $method  = (string) ($cfg['method']  ?? '');
        $drawer  = !empty($cfg['drawer']);

        $attrs = '';
        if ($target !== '') {
            $cls         = $target;
            $methodCall  = 'show';
            $argsRowPath = null;
            if (str_contains($target, '::')) {
                [$cls, $methodPart] = explode('::', $target, 2);
                if (preg_match('/^(\w+)\(\{(\w+)\}\)$/', $methodPart, $mm)) {
                    $methodCall  = $mm[1];
                    $argsRowPath = $mm[2];
                } else {
                    $methodCall = $methodPart;
                }
            }
            // {stageId} é alias para o id do stage atual.
            // SEMPRE envia 'id' (compat com onEdit(int $id) etc); placeholders
            // nomeados extras viram params adicionais — exceto 'id'/'stageId'
            // que ja sao representados pelo 'id'.
            $argValue = match ($argsRowPath) {
                null       => $sid,
                'stageId'  => $sid,
                'id'       => $sid,
                default    => (string) ($row[$argsRowPath] ?? $sid),
            };
            $params = ['id' => $sid];
            if ($argsRowPath !== null && !in_array($argsRowPath, ['id', 'stageId'], true)) {
                $params[$argsRowPath] = $argValue;
            }
            $action = \Mad\Ui\MadAction::to($cls, $methodCall, $params);
            $attrs  = $drawer ? $action->onget() : $action->auto();
        } elseif ($method !== '') {
            $h     = htmlspecialchars($method, ENT_QUOTES);
            $attrs = 'data-mad-click="' . $h . '(\'' . addslashes($sid) . '\')"';
            if ($confirm !== '') {
                $attrs .= ' data-mad-confirm="' . htmlspecialchars($confirm, ENT_QUOTES) . '"';
            }
        }

        return [
            'label'   => $label,
            'icon'    => $icon,
            'variant' => $variant,
            'confirm' => $confirm,
            'attrs'   => $attrs,
        ];
    }

    /**
     * Invoca callable de display-condition passando args conforme aridade
     * detectada via reflection.
     *
     *   1 arg  → fn(array $row): bool  (sem tipo: o registro) — legado MAD
     *   2 args → fn(?object $object, array $row): bool — record-compat
     *
     * Mesma regra do grid: GridAction::callRowCallback.
     */
    private static function _callRowCallback(callable $fn, ?object $object, array $row): mixed
    {
        return \Mad\Grid\GridAction::callRowCallback($fn, $object, $row);
    }

    // ── Entry point do <mad-kanban> declarativo (MadKanbanCompiler) ──────

    /**
     * Renderiza o board a partir do config compilado pelo MadKanbanCompiler.
     *
     * Aplica overrides de board (model/stage/etc) — quando algum atributo de
     * dados muda, força reload. Salva config de card em $_inlineConfig (usado
     * por _resolveCardVars e cardClickTarget).
     *
     * @param array $config
     */
    public function _renderInlineKanban(array $config): string
    {
        $needsReload = $this->_applyInlineKanbanConfig($config) || !$this->_dataLoaded;

        // Cache p/ hydrate() e renderSingleCardStatic (manageCard) reconstruir
        // config após drag-drop — o state do wire só carrega props públicas e
        // o JS chama static que cria nova instância sem pipeline Blade.
        // Chave = hash da identidade da config (não só a classe): dois boards
        // da mesma classe com configs diferentes não se sobrescrevem mais.
        $this->kbCfgKey = md5(static::class . '|' . json_encode(array_intersect_key($config, array_flip([
            'model', 'database', 'stageModel', 'stageField', 'cardOrderField',
            'valueField', 'valueFormat', 'cardView', 'card', 'clickTarget',
            'stageActions', 'toolbar',
        ]))));
        session([
            'mad_kb_cfg.' . $this->kbCfgKey          => $config,
            'mad_kb_cfg_latest.' . static::class      => $this->kbCfgKey,
        ]);

        if ($needsReload) {
            $this->loadData();
        }

        return \Mad\View\MadBlade::render('components.kanban', ['__component' => $this]);
    }

    /**
     * Aplica o config compilado do <mad-kanban> (board overrides + card slot)
     * nas props do componente. Retorna true se algum atributo de DADOS mudou
     * (exige reload). Compartilhado por _renderInlineKanban, hydrate() e
     * renderSingleCardStatic.
     */
    protected function _applyInlineKanbanConfig(array $config): bool
    {
        $needsReload = false;

        foreach (['model', 'database', 'stageModel', 'stageField',
                  'stageTitleField', 'stageColorField',
                  'stageOrderField', 'stageOrderDirection',
                  'cardOrderField', 'valueField'] as $k) {
            if (array_key_exists($k, $config) && $this->$k !== $config[$k]) {
                $this->$k    = (string) $config[$k];
                $needsReload = true;
            }
        }
        if (isset($config['stagesReorderable'])) {
            $this->stagesReorderable = (bool) $config['stagesReorderable'];
        }
        if (isset($config['cardsPerLoad']) && $this->cardsPerLoad !== (int) $config['cardsPerLoad']) {
            $this->cardsPerLoad = (int) $config['cardsPerLoad'];
            $needsReload        = true;
        }
        if (isset($config['cardsDraggable'])) {
            $this->cardsDraggable = (bool) $config['cardsDraggable'];
        }
        if (isset($config['topScroll'])) {
            $this->topScroll = (bool) $config['topScroll'];
        }
        if (isset($config['valueFormat']) && $config['valueFormat'] !== '') {
            $this->valueFormat = (string) $config['valueFormat'];
        }
        if (isset($config['cardView']) && $config['cardView'] !== '') {
            $this->cardView = (string) $config['cardView'];
        }

        // Card slot config — guarda pra _resolveCardVars / cardClickTarget
        $inline = [];
        if (isset($config['card']))         $inline['card']         = $config['card'];
        if (isset($config['clickTarget']))  $inline['clickTarget']  = $config['clickTarget'];
        if (isset($config['stageActions'])) $inline['stageActions'] = $config['stageActions'];
        if (isset($config['toolbar']))      $inline['toolbar']      = $config['toolbar'];
        if (!empty($inline)) {
            $this->_inlineConfig = $inline;
        }

        return $needsReload;
    }

    // ── renderSingleCard (para manageCard op) ────────────────────────────

    /**
     * Renderiza um único card a partir do ID.
     * Usado pelo MadResponse::manageCard().
     *
     * Com $instance (caminho drag-drop: onCardMove passa $this), usa o board
     * vivo já configurado — sem tocar sessão. Sem instância, restaura
     * $_inlineConfig do cache de sessão (ponteiro "última config da classe");
     * sem isso, kanbans declarativos perdem a config de card e renderizam o
     * template default vazio. Caveat documentado: com 2 boards da mesma classe
     * na sessão, o caminho SEM instância usa a config mais recente.
     */
    public static function renderSingleCardStatic(string $kanbanClass, int|string $cardId, ?MadKanban $instance = null): string
    {
        /** @var MadKanban $kanban */
        $kanban = $instance ?? new $kanbanClass();

        if ($instance === null) {
            $latest = session('mad_kb_cfg_latest.' . $kanbanClass);
            $cfg    = is_string($latest) && $latest !== '' ? session('mad_kb_cfg.' . $latest) : null;
            if (is_array($cfg)) {
                // Aplica overrides de board (model/stageField/etc) e config de card.
                $kanban->_applyInlineKanbanConfig($cfg);
            }
        }

        $modelClass = $kanban->_resolveModelFqcn($kanban->model);
        // Eloquent: construtor recebe array de atributos — carrega por id via find().
        // Legado (record com ctor-load): mantém o idioma `new $modelClass($id)`.
        $record = is_subclass_of($modelClass, \Illuminate\Database\Eloquent\Model::class)
            ? $modelClass::find($cardId)
            : new $modelClass($cardId);

        if ($record === null) {
            return '';
        }

        return $kanban->renderCard($record);
    }

    // ── Internals ────────────────────────────────────────────────────────

    protected function _db(): string
    {
        if (!empty($this->database)) return $this->database;
        return defined('MAIN_DATABASE') ? MAIN_DATABASE : 'business';
    }

    /**
     * Valida que $field é um identificador SQL simples (coluna, opcionalmente
     * qualificada com tabela). Campos de config entram interpolados em
     * selectRaw — sem esse guard, config dinâmica viraria injeção.
     */
    private function _assertIdentifier(string $field, string $what): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $field)) {
            throw new \InvalidArgumentException(
                "MadKanban: {$what} '{$field}' não é um identificador SQL válido."
            );
        }
        return $field;
    }

    /**
     * Resolve short-name de model ('Pedido') → FQCN (App\Models\Pedido).
     * Se já for FQCN existente, retorna como veio. Em falha (model
     * inexistente), retorna o valor original — o erro natural estoura no
     * ponto de uso, igual ao legado.
     */
    private function _resolveModelFqcn(string $model): string
    {
        if ($model === '') return $model;
        try {
            return ModelOptionsLoader::resolveModelClass($model);
        } catch (\Throwable $e) {
            return $model;
        }
    }

    /**
     * Quando true, _needsFullRender retorna false mesmo com _dataLoaded.
     * Action seta para forcar partial response (so ops, sem full HTML).
     */
    private bool $_skipFullRender = false;

    public function _needsFullRender(): bool
    {
        return $this->_forceFullRender || ($this->_dataLoaded && !$this->_skipFullRender);
    }
}
