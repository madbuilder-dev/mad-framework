<?php
namespace Mad\Grid;

/**
 * GridColumn — Builder fluent de colunas para MadDataGrid.
 */
class GridColumn
{
    public string  $field      = '';
    public string  $fieldKey   = '';  // chave sanitizada para Alpine/JS (sem {, }, ->)
    public string  $label      = '';
    public string  $width      = '';
    public string  $align      = 'left';
    public bool    $sortable   = false;
    public bool    $filterable       = false;
    public string  $filterType       = 'text';  // text | select | date
    public array   $filterOpts       = [];
    public bool    $hasFilterPopover = false;   // novo popover com botões Filtrar/Limpar
    public string  $filterPopoverHtml= '';       // HTML customizado do corpo do popover
    public bool    $filterOpSelect   = false;    // exibir seletor de operador (=, like...)
    public string  $colFilterToken   = '';        // token criptografado {field, op, sub?} para col-filter seguro
    public string  $colFilterField   = '';        // campo real do filtro (para exibir valor ativo)

    // ── Filtro tipado seguro (contrato filter-type=) ──────────────────────────
    // Diferente do `filterable` legado, aqui o operador NUNCA vem do cliente: ele
    // é cunhado no token junto do campo (MadDataGrid::_normalizeFilterTokens) e
    // volta decriptado no onColFilter. Um filtro por coluna — setar um kind zera
    // `filterable`/`hasFilterPopover`.
    public string  $filterKind        = '';    // '' = desligado. text|select|dbcombo|dbsearch
                                               // |multi|date|date-range|number|number-range|bool
    public string  $filterOp          = '';    // operador efetivo (vai no token)
    public string  $filterField       = '';    // coluna real filtrada (default: $field)
    public string  $filterKey         = 'id';
    public string  $filterOrderBy     = '';
    public string  $filterOrder       = '';    // ''|asc|desc — direção do order-by das options
    public array   $filterFilters     = [];    // [['campo','op','val'], ...] regras de carregamento
    public int     $filterMinLength   = 2;     // dbsearch: chars mínimos pra buscar
    public string  $filterTrue        = '1';   // bool: valor gravado p/ "Sim"
    public string  $filterFalse       = '0';   // bool: valor gravado p/ "Não"
    public string  $filterPlaceholder = '';

    public bool    $hasTransform = false;
    public         $transform  = null;    // callable
    // Token built-in do formatter-select ('datetime', 'cpf', ...) — aplicado
    // como FALLBACK no renderValue via Mad\Support\ValueFormatter (attrs de
    // render-type vencem). Setado por transform() quando o ref é built-in.
    public string  $builtinFormat = '';
    /** Token de mídia (file-avatar/…) — render HTML dedicado, ver MadMediaFormatter. */
    public string  $mediaFormat = '';
    public bool    $isBadge    = false;
    public array   $badgeMap   = [];
    public bool    $isMoney    = false;
    public string  $moneyPrefix= '';
    public bool    $isNumber   = false;
    public int     $numberDecimals = 2;
    public bool    $isDate     = false;
    public string  $dateFormat = 'd/m/Y';
    /**
     * Só a exportação preenche (GridExportSourceTypes): a coluna do banco por
     * trás do campo é numérica? null = fonte desconhecida (alias, accessor,
     * query própria). Decide se texto como "12.50" vira número no Excel —
     * varchar e decimal chegam iguais do PDO, só a fonte separa os dois.
     */
    public ?bool   $sourceNumeric = null;
    public string  $totalFunc  = '';      // sum | avg | count | min | max
    public string  $totalMask  = '';
    public string  $group      = '';
    public bool    $hidden     = false;
    public bool    $hideable   = true;    // false = coluna sempre visível, não aparece no chooser
    /**
     * Oculta a coluna quando a TELA (viewport) é mais estreita que N px — o
     * "Ocultar coluna quando a largura da tela estiver abaixo de" do 4.0.
     * 0 = sempre visível. Quem esconde é o madDataGrid no navegador (mesmo
     * gate do seletor de colunas); a exportação ignora.
     */
    public int     $hideBelow  = 0;
    public bool    $editable      = false;
    // text | select | date | number | textarea | money
    // | numeric | dbcombo | dbunique-search | datetime | color | spinner
    public string  $editType      = 'text';
    public array   $editOpts      = [];       // select: ['value' => 'label']
    public string  $editDateFmt   = 'Y-m-d';  // formato interno p/ input[type=date]
    public int     $editDecimals  = 2;        // number/numeric/money: casas decimais
    public int     $editRows      = 3;        // textarea: linhas
    public string  $editMode      = 'dblclick'; // dblclick | click | inline
    public string  $editPrefix    = '';         // money/numeric: prefixo ex: 'R$'
    public string  $editSuffix    = '';         // numeric: sufixo ex: 'kg'

    // ── Props para dbcombo / dbunique-search ──────────────────────────────
    public string  $editModel     = '';       // classe do model Eloquent
    public string  $editDatabase  = '';       // conexao (default: a do model)
    public string  $editKey       = 'id';
    public string  $editDisplay   = 'nome';   // aceita template '{nome} ({sigla})'
    public string  $editOrderBy   = '';
    public array   $editFilters   = [];       // [['campo','op','val'], ...]
    public int     $editMinLength = 2;        // dbunique-search: chars minimos pra buscar

    // ── Props para spinner / number / numeric ─────────────────────────────
    public ?float  $editMin       = null;
    public ?float  $editMax       = null;
    public ?float  $editStep      = null;     // null = auto pelo decimals

    // ── Props para color ──────────────────────────────────────────────────
    public array   $editColors    = [];       // paleta opcional ['#000', '#fff', ...]
    public bool    $isHtml        = false;
    public         $displayConditionFn = null;  // callable () => bool
    public string  $evaluate   = '';          // expression: '{valor} * {quantidade}'

    // ── Saldo acumulado (running balance) ─────────────────────────────────
    /**
     * '' = coluna comum. 'self' = acumula o PRÓPRIO campo (depois do evaluate).
     * Qualquer outra string é a expressão do delta ('{credito} - {debito}',
     * mesma DSL/sanitizador do evaluate). O valor acumulado é materializado em
     * $row[$field] no fim do loadData(), então tela, totais, CSV, XLSX e PDF
     * leem o mesmo número.
     */
    public string  $running      = '';
    /** '' | 'none' = nunca zera · 'group' (= 'group:0') | 'group:N' = zera na quebra do nível N. */
    public string  $runningReset = '';
    /** Saldo inicial do escopo: número ou expressão ('{saldo_anterior}'). */
    public string  $runningStart = '';

    // Card view: papel da coluna no layout de card
    public string  $cardRole   = '';          // 'title' | 'subtitle' | 'image' | 'badge' | 'highlight' | ''

    // Col-filter dbcombo: model/display para resolver display do filtro ativo
    public string  $filterModel    = '';
    public string  $filterDisplay  = '';
    public string  $filterDatabase = '';

    public static function make(string $field, string $label): self
    {
        $c = new self();
        $c->field    = $field;
        $c->fieldKey = preg_replace('/[^a-zA-Z0-9_]/', '_', $field);
        $c->label    = $label;
        return $c;
    }

    /**
     * `140` e `'140'` significam a MESMA coisa (px). O `is_int()` que morava aqui
     * normalizava só o int, então uma grid montada em PHP com `->width('140')`
     * emitia `style="width:140"` — declaração inválida, descartada, coluna na
     * largura automática. O atributo `width="140"` da tag nunca sofreu disso
     * porque o compilador sufixava px; era divergência interna do mesmo
     * componente.
     */
    public function width(int|string $w): self
    {
        $this->width = \Mad\Support\CssUnits::length((string) $w);
        return $this;
    }

    public function align(string $align): self
    {
        $this->align = $align;
        return $this;
    }

    public function sortable(): self
    {
        $this->sortable = true;
        return $this;
    }

    /**
     * filter() / filter('select', [...]) / filter('date')
     *
     * `$options` aceita string no shorthand "A:Ativo|I:Inativo" — sem isso um
     * `filter-opts="A:Ativo"` (sem os dois-pontos do bind PHP) chegava como
     * string e estourava TypeError em runtime.
     */
    public function filter(string $type = 'text', array|string $options = []): self
    {
        $this->filterable = true;
        $this->filterType = $type;
        $this->filterOpts = is_string($options) ? static::parseOptsMap($options) : $options;
        $this->filterKind = '';   // last-writer-wins entre os dois contratos
        return $this;
    }

    /**
     * Shorthand de opções: "A:Ativo|I:Inativo" → ['A'=>'Ativo','I'=>'Inativo'].
     * Segmento sem ':' vira valor=label. Espelha parseBadgeMap().
     */
    public static function parseOptsMap(string $str): array
    {
        $out = [];
        foreach (explode('|', $str) as $seg) {
            $seg = trim($seg);
            if ($seg === '') continue;
            $pos = strpos($seg, ':');
            if ($pos === false) {
                $out[$seg] = $seg;
                continue;
            }
            $out[substr($seg, 0, $pos)] = substr($seg, $pos + 1);
        }
        return $out;
    }

    /**
     * Ativa o popover de filtro com botões Filtrar/Limpar e opcionalmente conteúdo customizado.
     *
     * @param string $customHtml HTML personalizado para o corpo do popover.
     *                           Se vazio, exibe um input de texto padrão.
     * @param bool   $opSelect   Exibir seletor de operador (=, like, >=, ...).
     */
    public function filterPopover(string $customHtml = '', bool $opSelect = false): self
    {
        $this->hasFilterPopover  = true;
        $this->filterPopoverHtml = $customHtml;
        $this->filterOpSelect    = $opSelect;
        $this->filterKind        = '';   // last-writer-wins entre os dois contratos
        return $this;
    }

    // ── Filtro tipado (filter-type=) ─────────────────────────────────────────
    // Equivalentes fluentes do contrato Blade. Todos passam por _filterKind, que
    // garante a exclusividade com o caminho legado: uma coluna tem UM filtro.

    protected function _filterKind(string $kind, string $op): self
    {
        $this->filterKind       = $kind;
        $this->filterOp         = $op;
        $this->filterable       = false;
        $this->hasFilterPopover = false;
        return $this;
    }

    /** Texto. Default `like` (contém); use '=' para casamento exato. */
    public function filterText(string $op = 'like'): self
    {
        return $this->_filterKind('text', $op);
    }

    /** Lista de opções fixas. Aceita array ou "A:Ativo|I:Inativo". */
    public function filterSelect(array|string $opts, string $op = '='): self
    {
        $this->filterOpts = is_string($opts) ? static::parseOptsMap($opts) : $opts;
        return $this->_filterKind('select', $op);
    }

    /** Multi-seleção → IN. */
    public function filterMulti(array|string $opts = [], string $op = 'in'): self
    {
        $this->filterOpts = is_string($opts) ? static::parseOptsMap($opts) : $opts;
        return $this->_filterKind('multi', $op);
    }

    /** Combo carregado de tabela (options resolvidas no render). */
    public function filterDbcombo(string $model, string $display = 'nome', string $database = '', string $key = 'id'): self
    {
        $this->filterModel    = $model;
        $this->filterDisplay  = $display;
        $this->filterDatabase = $database;
        $this->filterKey      = $key;
        return $this->_filterKind('dbcombo', '=');
    }

    /** Busca lazy server-side — para tabela grande demais pra carregar inteira. */
    public function filterDbsearch(string $model, string $display = 'nome', string $database = '', int $minLength = 2): self
    {
        $this->filterModel     = $model;
        $this->filterDisplay   = $display;
        $this->filterDatabase  = $database;
        $this->filterMinLength = $minLength;
        return $this->_filterKind('dbsearch', '=');
    }

    /** Data (dia único) — vira whereDate, não LIKE. */
    public function filterDate(string $op = 'date'): self
    {
        return $this->_filterKind('date', $op);
    }

    /** Intervalo de datas — extremo faltando degrada para >= / <=. */
    public function filterDateRange(): self
    {
        return $this->_filterKind('date-range', 'date between');
    }

    public function filterNumber(string $op = '='): self
    {
        return $this->_filterKind('number', $op);
    }

    public function filterNumberRange(): self
    {
        return $this->_filterKind('number-range', 'between');
    }

    /** Sim/Não. `$true`/`$false` são os valores GRAVADOS no banco. */
    public function filterBool(string $true = '1', string $false = '0'): self
    {
        $this->filterTrue  = $true;
        $this->filterFalse = $false;
        return $this->_filterKind('bool', '=');
    }

    /**
     * Coluna real a filtrar quando o display é outro — ex.: exibe
     * `{estado->nome}` mas filtra `estado_id` (mais rápido, cross-connection-safe).
     */
    public function filterOn(string $field): self
    {
        $this->filterField = $field;
        return $this;
    }

    public function filterOrderBy(string $orderBy, string $order = ''): self
    {
        $this->filterOrderBy = $orderBy;
        $this->filterOrder = in_array(strtolower($order), ['asc', 'desc'], true)
            ? strtolower($order)
            : '';
        return $this;
    }

    /** Regras de carregamento das opções: [['campo','op','valor'], ...]. */
    public function filterFilters(array $filters): self
    {
        $this->filterFilters = $filters;
        return $this;
    }

    public function filterPlaceholder(string $placeholder): self
    {
        $this->filterPlaceholder = $placeholder;
        return $this;
    }

    /**
     * Callable ou string 'Classe::metodo' que recebe ($value, $object, $row, $column, $lastRow).
     *
     * Formas aceitas para string:
     *   - FQCN callable:      'App\Format\Doc::cpfCnpj' (passa intacto)
     *   - Forma curta gerada: 'GridTransformer::statusColor' → prefixa \App\Transformer\
     *   - Token do builder:   'custom:status_color' → \App\Transformer\GridTransformer::statusColor
     * Método/classe ausente degrada silencioso (guard is_callable no renderValue).
     */
    public function transform($fn): self
    {
        // Token de mídia (file-avatar/file-thumb/file-link/file-gallery):
        // render dedicado com HTML raw (miniatura/lightbox/link) — precisa
        // vir ANTES do ValueFormatter, que escaparia o markup.
        if (is_string($fn) && \Mad\Support\MadMediaFormatter::isMediaToken($fn)) {
            $this->mediaFormat = strtolower(trim($fn));
            return $this;
        }
        // Token built-in do formatter-select ('datetime', 'cpf', 'percent'...):
        // não é callable — vira fallback de formatação no renderValue (depois
        // dos attrs de render-type). Refs custom (custom:slug / Classe::metodo)
        // seguem o caminho callable normal.
        if (is_string($fn)
            && ! str_starts_with($fn, 'custom:')
            && ! str_contains($fn, '::')
            && \Mad\Support\ValueFormatter::supports($fn)) {
            $this->builtinFormat = strtolower(trim($fn));
            return $this;
        }
        if (is_string($fn)) {
            $fn = self::resolveTransformRef($fn);
        }
        $this->hasTransform = true;
        $this->transform    = $fn;
        return $this;
    }

    /**
     * Resolve as formas curtas emitidas pelo MadBuilder para o FQCN das
     * classes de contexto (App\Transformer\{Document,Grid,Fill}Transformer).
     */
    /**
     * Resolve `custom:<slug>` / forma curta / FQCN para um callable-string.
     *
     * Público desde a Rev. 5 do PDV: o carrinho do `<mad-pdv>` renderiza no
     * cliente e nunca passa por `renderValue`, mas precisa da MESMA regra de
     * resolução. Duplicar a regex seria garantir que as duas divirjam.
     */
    public static function resolveTransformRef(string $ref): string
    {
        $ref = trim($ref);
        // custom:<slug> → GridTransformer::<camel(slug)> (grid é o contexto daqui)
        if (preg_match('/^custom:([A-Za-z0-9_\-]+)$/', $ref, $m)) {
            $studly = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $m[1])));
            return '\\App\\Transformer\\GridTransformer::' . lcfirst($studly);
        }
        // Forma curta sem namespace: GridTransformer::statusColor
        if (preg_match('/^(DocumentTransformer|GridTransformer|FillTransformer)::[A-Za-z0-9_]+$/', $ref)) {
            return '\\App\\Transformer\\' . $ref;
        }
        return $ref;
    }

    /** Callable ou string 'Classe::metodo' que recebe () e retorna bool.
     *  Aceita as mesmas formas curtas do transform ('GridTransformer::x',
     *  'custom:slug') — resolvidas pro FQCN de App\Transformer\*. */
    public function displayCondition($fn): self
    {
        if (is_string($fn)) {
            $fn = self::resolveTransformRef($fn);
        }
        $this->displayConditionFn = $fn;
        return $this;
    }

    /** Verifica se a coluna deve ser exibida (avalia displayCondition se definida). */
    public function checkDisplay(): bool
    {
        if ($this->displayConditionFn !== null && is_callable($this->displayConditionFn)) {
            return (bool)call_user_func($this->displayConditionFn);
        }
        return true;
    }

    /**
     * @param array|string $map Mapa ['val' => 'variant:Label'] OU string pipe
     *                          "val:variant:Label|val2:variant2:Label2" (mesma
     *                          sintaxe do atributo literal badge="..." — cobre
     *                          :badge="$var" quando $var é string).
     */
    public function badge(array|string $map = []): self
    {
        $this->isBadge  = true;
        $this->badgeMap = self::translateBadgeLabels(is_string($map) ? self::parseBadgeMap($map) : $map);
        return $this;
    }

    /**
     * Rótulos do selo no idioma de quem usa o app ("Sim" → "Yes"), pela
     * tradução do app ({@see \Mad\I18n\AppText}). Traduz uma vez, aqui: a
     * grade, o PDF, a planilha e o `<mad-detail-form>` (que monta o selo no
     * navegador a partir deste mapa) leem o mesmo mapa. Selo SEM rótulo mostra
     * o valor gravado — é dado, não texto da tela — e fica como está.
     */
    private static function translateBadgeLabels(array $map): array
    {
        foreach ($map as $value => $entry) {
            if (is_array($entry)) {
                if (isset($entry['label']) && is_string($entry['label'])) {
                    $map[$value]['label'] = \Mad\I18n\AppText::translate($entry['label']);
                }
            } elseif (is_string($entry) && str_contains($entry, ':')) {
                [$variant, $label] = explode(':', $entry, 2);
                $map[$value] = $variant . ':' . \Mad\I18n\AppText::translate($label);
            }
        }
        return $map;
    }

    /**
     * Valor da linha como chave do selo. Coluna `boolean` chega como
     * `true`/`false` (PDO do Postgres, cast do model) e `(string) false` é ''
     * — o "0:danger:Inativo" nunca casava e a célula saía como pílula vazia
     * (fórum #113). Boolean vira '1'/'0'.
     */
    private static function badgeValue(mixed $value): string
    {
        return is_bool($value) ? ($value ? '1' : '0') : (string) $value;
    }

    /** Entrada do mapa para o valor; boolean aceita a chave '1'/'0' ou 'true'/'false'. */
    private function badgeEntry(mixed $value): mixed
    {
        $key = self::badgeValue($value);
        if (array_key_exists($key, $this->badgeMap)) {
            return $this->badgeMap[$key];
        }
        if (is_bool($value)) {
            return $this->badgeMap[$value ? 'true' : 'false'] ?? null;
        }
        return null;
    }

    /** "A:success:Ativo|I:danger" → ['A' => 'success:Ativo', 'I' => 'danger'] */
    public static function parseBadgeMap(string $str): array
    {
        $map = [];
        foreach (explode('|', $str) as $part) {
            $segs = explode(':', trim($part), 3);
            $val  = trim($segs[0]);
            if ($val === '') {
                continue;
            }
            if (count($segs) === 1) {
                $map[$val] = 'secondary';
            } elseif (count($segs) === 2) {
                $map[$val] = trim($segs[1]);
            } else {
                $map[$val] = trim($segs[1]) . ':' . trim($segs[2]);
            }
        }
        return $map;
    }

    public function money(string $prefix = ''): self
    {
        $this->isMoney    = true;
        $this->moneyPrefix = $prefix;
        return $this;
    }

    public function number(int $decimals = 2): self
    {
        $this->isNumber     = true;
        $this->numberDecimals = $decimals;
        return $this;
    }

    public function date(string $format = 'd/m/Y'): self
    {
        $this->isDate     = true;
        $this->dateFormat = $format;
        return $this;
    }

    public function total(string $func): self
    {
        $this->totalFunc = $func;
        return $this;
    }

    public function totalMask(string $mask): self
    {
        $this->totalMask = $mask;
        return $this;
    }

    public function group(string $label): self
    {
        $this->group = $label;
        return $this;
    }

    public function hidden(): self
    {
        $this->hidden = true;
        return $this;
    }

    /** Oculta a coluna em telas mais estreitas que $px (0 = sempre visível). */
    public function hideBelow(int $px): self
    {
        $this->hideBelow = max(0, $px);
        return $this;
    }

    /** Marca a coluna como não-ocultável pelo usuário (não aparece no column chooser). */
    public function notHideable(): self
    {
        $this->hideable = false;
        return $this;
    }

    public function editable(): self
    {
        $this->editable = true;
        return $this;
    }

    /** Edição inline como select. Opções: ['value' => 'label'] */
    public function editSelect(array $options): self
    {
        $this->editable  = true;
        $this->editType  = 'select';
        $this->editOpts  = $options;
        return $this;
    }

    /** Edição inline como input de data. Formato exibido no input (HTML date usa Y-m-d internamente). */
    public function editDate(string $inputFormat = 'Y-m-d'): self
    {
        $this->editable    = true;
        $this->editType    = 'date';
        $this->editDateFmt = $inputFormat;
        return $this;
    }

    /** Edição inline como input numérico. */
    public function editNumber(int $decimals = 2): self
    {
        $this->editable     = true;
        $this->editType     = 'number';
        $this->editDecimals = $decimals;
        return $this;
    }

    /** Edição inline como textarea. */
    public function editTextarea(int $rows = 3): self
    {
        $this->editable  = true;
        $this->editType  = 'textarea';
        $this->editRows  = $rows;
        return $this;
    }

    /** Edição inline como campo monetário/numérico com máscara pt-BR. */
    public function editMoney(int $decimals = 2, string $prefix = ''): self
    {
        $this->editable    = true;
        $this->editType    = 'money';
        $this->editDecimals = $decimals;
        $this->editPrefix  = $prefix;
        return $this;
    }

    /** Edição inline com mad-numeric-field (decimal pt-BR formatado, sem prefixo R$). */
    public function editNumeric(int $decimals = 2, string $prefix = '', string $suffix = ''): self
    {
        $this->editable     = true;
        $this->editType     = 'numeric';
        $this->editDecimals = $decimals;
        $this->editPrefix   = $prefix;
        $this->editSuffix   = $suffix;
        return $this;
    }

    /** Edição inline com mad-dbcombo-field (MAD Select com options carregadas no render). */
    public function editDbcombo(string $model, string $display = 'nome', string $database = '', string $key = 'id'): self
    {
        $this->editable    = true;
        $this->editType    = 'dbcombo';
        $this->editModel   = $model;
        $this->editDisplay = $display;
        $this->editDatabase = $database;
        $this->editKey     = $key;
        return $this;
    }

    /** Edição inline com mad-dbunique-search-field (MAD Select com busca AJAX server-side). */
    public function editDbuniqueSearch(string $model, string $display = 'nome', string $database = '', int $minLength = 2): self
    {
        $this->editable    = true;
        $this->editType    = 'dbunique-search';
        $this->editModel   = $model;
        $this->editDisplay = $display;
        $this->editDatabase = $database;
        $this->editMinLength = $minLength;
        return $this;
    }

    /** Edição inline com mad-datetime-field. */
    public function editDatetime(): self
    {
        $this->editable = true;
        $this->editType = 'datetime';
        return $this;
    }

    /** Edição inline com mad-color-field (Pickr). */
    public function editColor(array $colors = []): self
    {
        $this->editable    = true;
        $this->editType    = 'color';
        $this->editColors  = $colors;
        return $this;
    }

    /** Edição inline com mad-spinner-field (numero com botoes +/-). */
    public function editSpinner(?float $min = null, ?float $max = null, ?float $step = 1): self
    {
        $this->editable = true;
        $this->editType = 'spinner';
        $this->editMin  = $min;
        $this->editMax  = $max;
        $this->editStep = $step;
        return $this;
    }

    /** Filtros para dbcombo/dbunique-search: [['campo','op','val'], ...]. */
    public function editFilters(array $filters): self
    {
        $this->editFilters = $filters;
        return $this;
    }

    /** Order-by da query para dbcombo/dbunique-search. */
    public function editOrderBy(string $orderBy): self
    {
        $this->editOrderBy = $orderBy;
        return $this;
    }

    /** Min/max/step para number/numeric/spinner. */
    public function editMin(float $min): self  { $this->editMin = $min;   return $this; }
    public function editMax(float $max): self  { $this->editMax = $max;   return $this; }
    public function editStep(float $step): self { $this->editStep = $step; return $this; }

    /**
     * Define o modo de ativação da edição inline.
     *
     * @param string $mode  'dblclick' (padrão) | 'click' (ícone lápis) | 'inline' (sempre editável)
     */
    public function editMode(string $mode): self
    {
        $this->editMode = $mode;
        return $this;
    }

    public function html(): self
    {
        $this->isHtml = true;
        return $this;
    }

    /** Define o papel da coluna no card view: title, subtitle, image, badge, highlight. */
    public function cardRole(string $role): self
    {
        $this->cardRole = $role;
        return $this;
    }

    /**
     * Define uma expressão de cálculo para o valor da coluna.
     *
     * Placeholders: {campo} para campos simples, {rel->campo} para relacionamentos.
     * Operadores: + - * / ( )
     *
     * Exemplos:
     *   '{valor} * {quantidade}'
     *   '{produto->tipo_produto->percentual} * {valor} / 100'
     *   '({valor} * {quantidade}) - {desconto}'
     */
    public function evaluate(string $expr): self
    {
        $this->evaluate = $expr;
        return $this;
    }

    /**
     * Transforma a coluna em SALDO ACUMULADO.
     *
     *   ->running()                              // acumula o próprio campo
     *   ->running('{credito} - {debito}')        // acumula o delta da expressão
     *   ->running('self', 'group')               // zera na quebra do nível 0
     *   ->running('self', 'group:1', '1000')     // zera no nível 1, começa em 1000
     */
    public function running(string $expr = 'self', string $reset = '', string $start = ''): self
    {
        $this->running      = $expr !== '' ? $expr : 'self';
        $this->runningReset = $reset;
        $this->runningStart = $start;
        return $this;
    }

    /** A coluna é um saldo acumulado? */
    public function isRunning(): bool
    {
        return $this->running !== '';
    }

    /**
     * Loga UMA vez por (coluna, ref) que o transform declarado não é chamável.
     *
     * Dedupe por processo: `renderValue` roda por CÉLULA — sem isso uma grid de
     * 50 linhas escreveria 50 linhas idênticas no log a cada request.
     *
     * @var array<string, true>
     */
    private static array $badTransforms = [];

    private static function warnBadTransform(string $field, mixed $ref): void
    {
        $refStr = is_string($ref) ? $ref : get_debug_type($ref);
        $key    = $field . '|' . $refStr;
        if (isset(self::$badTransforms[$key])) {
            return;
        }
        self::$badTransforms[$key] = true;

        $hint = '';
        if (is_string($ref) && $ref !== '' && ! str_contains($ref, '::')) {
            $hint = ' Tokens built-in aceitos: '
                  . implode(', ', \Mad\Support\ValueFormatter::tokens()) . '.';
        }

        error_log(
            '[MadGrid] coluna "' . $field . '": transform "' . $refStr . '" não é chamável '
            . 'nem token built-in — valor exibido SEM formatação.' . $hint
        );
    }

    /**
     * Renderiza o valor de uma célula aplicando as transformações configuradas.
     *
     * @param mixed      $value   Valor cru da coluna
     * @param array      $row     Linha completa (array)
     * @param array|null $lastRow Linha anterior (para comparações entre linhas)
     * @return string             HTML renderizado
     */
    public function renderValue(mixed $value, array $row, ?array $lastRow = null): string
    {
        // Transform configurado que NÃO é chamável: typo no nome da classe/método,
        // token fora do catálogo do ValueFormatter ('real', 'brl', 'money|upper'),
        // classe de transformer que não foi gerada. Antes degradava em SILÊNCIO
        // pro valor cru — a coluna aparecia sem formatação e não havia pista
        // nenhuma de onde olhar. Uma linha por coluna, uma vez por processo.
        if ($this->hasTransform && ! is_callable($this->transform)) {
            self::warnBadTransform($this->field, $this->transform);
        }

        // Callable transform — assinatura: ($value, $object, $row, $column, $lastRow)
        if ($this->hasTransform && is_callable($this->transform)) {
            $out = call_user_func(
                $this->transform,
                $value,
                (object) $row,
                $row,
                $this,
                $lastRow ? (object) $lastRow : null
            );
            return $this->isHtml ? (string)$out : htmlspecialchars((string)$out, ENT_QUOTES);
        }

        // Mídia (file-avatar/thumb/link/gallery) — HTML raw dedicado; todo
        // valor dinâmico já sai escapado do MadMediaFormatter.
        if ($this->mediaFormat !== '') {
            return \Mad\Support\MadMediaFormatter::render($value, $this->mediaFormat);
        }

        // Badge — formatos suportados:
        //   'value' => 'success'                             → variant, label=value
        //   'value' => 'success:Ativo'                       → variant:label
        //   'value' => ['variant'=>'success','label'=>'Ativo']→ array
        if ($this->isBadge) {
            $entry = $this->badgeEntry($value);
            $raw   = self::badgeValue($value);
            if ($entry === null) {
                $variant = 'secondary';
                $lbl     = $raw;
            } elseif (is_array($entry)) {
                $variant = $entry['variant'] ?? 'secondary';
                $lbl     = $entry['label']   ?? $raw;
            } elseif (strpos((string)$entry, ':') !== false) {
                [$variant, $lbl] = explode(':', (string)$entry, 2);
            } else {
                $variant = (string)$entry;
                $lbl     = $raw;
            }
            $label = htmlspecialchars($lbl, ENT_QUOTES);
            return "<span class=\"mad-badge mad-badge-{$variant}\">{$label}</span>";
        }

        // Money
        if ($this->isMoney) {
            $num = is_numeric($value) ? (float)$value : 0.0;
            $fmt = number_format($num, 2, ',', '.');
            $pre = htmlspecialchars($this->moneyPrefix, ENT_QUOTES);
            return "{$pre} {$fmt}";
        }

        // Number
        if ($this->isNumber) {
            $num = is_numeric($value) ? (float)$value : 0.0;
            return number_format($num, $this->numberDecimals, ',', '.');
        }

        // Date — no FUSO DO APP. O `toArray()` do Eloquent entrega as datas em
        // ISO UTC ("2026-09-27T23:15:00.000000Z"); formatar sem converter
        // mostrava a hora de Greenwich num app em America/Sao_Paulo. Data sem
        // fuso (o texto cru do banco) já está no fuso do app: não muda.
        if ($this->isDate && !empty($value)) {
            try {
                $d = new \DateTime((string)$value);
                $d->setTimezone(new \DateTimeZone(date_default_timezone_get()));
                return $d->format($this->dateFormat);
            } catch (\Exception $e) {
                // fall through
            }
        }

        // Formatador built-in do formatter-select — FALLBACK de propósito:
        // os attrs de render-type acima (badge/money/number/date) vencem,
        // preservando o render de colunas antigas que carregam attr + token.
        if ($this->builtinFormat !== '') {
            return htmlspecialchars(\Mad\Support\ValueFormatter::apply($value, $this->builtinFormat), ENT_QUOTES);
        }

        // Coluna `html`: o VALOR DO BANCO vai para a tela como HTML. Sai limpo
        // (sem `<script>`, atributo de evento ou URL `javascript:`) — o que
        // está gravado pode ter entrado sem passar pelo formulário, ou antes de
        // a limpeza do Editor HTML valer sempre. O HTML que um transformador
        // monta (acima) é código do desenvolvedor e passa como está.
        if ($this->isHtml) {
            return \Mad\Util\MadHtmlSanitizer::sanitize((string) $value);
        }

        // Rede de protecao: coluna que guarda o ARQUIVO na propria celula
        // (data URI ou base64 cru) sem transformador configurado. Antes o
        // valor caia no escape abaixo e a data URI inteira ia parar dentro do
        // `<td>` — a linha crescia dezenas de milhares de caracteres e o
        // layout da grid ia junto. Miniatura e o render honesto do que a
        // coluna tem.
        if (\Mad\Support\MadMediaFormatter::looksLikeInlineFile($value)) {
            return \Mad\Support\MadMediaFormatter::render($value, 'file-thumb');
        }

        // Rede de protecao: data SERIALIZADA pelo Eloquent numa coluna sem
        // formato (`created_at`, cast de data). O ISO cru não serve a ninguém
        // — "Criada em" da Fila de Aprovação saía "2026-09-27T23:15:00.000000Z".
        $serialized = self::serializedDateText($value);
        if ($serialized !== null) {
            return htmlspecialchars($serialized, ENT_QUOTES);
        }

        return htmlspecialchars((string)$value, ENT_QUOTES);
    }

    /** Data do `toArray()` do Eloquent: ISO 8601 em UTC, com o `T` e o `Z`. */
    private const SERIALIZED_DATE_RE = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z$/';

    /** O valor é uma data serializada pelo Eloquent (e não texto qualquer)? */
    public static function isSerializedDate(mixed $value): bool
    {
        return is_string($value) && preg_match(self::SERIALIZED_DATE_RE, $value) === 1;
    }

    /**
     * Data serializada pelo Eloquent → data no fuso do app: "27/09/2026 20:15",
     * ou só "27/09/2026" quando é meia-noite (cast `date`). Null quando o valor
     * não é uma data serializada.
     */
    public static function serializedDateText(mixed $value): ?string
    {
        if (!self::isSerializedDate($value)) {
            return null;
        }
        try {
            $d = (new \DateTimeImmutable((string) $value))
                ->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        } catch (\Exception) {
            return null;
        }

        return $d->format('H:i:s') === '00:00:00' ? $d->format('d/m/Y') : $d->format('d/m/Y H:i');
    }

    /**
     * Renderiza o valor para exportacao PDF (Dompdf).
     *
     * Diferente de renderValue() que usa as classes CSS `.mad-badge-*` do
     * mad-ui.css, este metodo injeta estilos inline — o Dompdf nao tem
     * acesso a CSS externo.
     *
     * Colunas html/transform-html sao passadas como HTML (Dompdf renderiza).
     * Texto comum e escapado com htmlspecialchars.
     */
    public function renderValueForPdf(mixed $value, array $row, ?array $lastRow = null): string
    {
        // Mídia: PDF não roda lightbox nem serve URL assinada — exporta os
        // NOMES dos arquivos como texto.
        if ($this->mediaFormat !== '') {
            return htmlspecialchars(\Mad\Support\MadMediaFormatter::renderPlain($value), ENT_QUOTES);
        }

        // Badges — estilos inline equivalentes ao mad-ui.css
        if ($this->isBadge) {
            $entry = $this->badgeEntry($value);
            $raw   = self::badgeValue($value);
            if ($entry === null) {
                $variant = 'secondary';
                $lbl     = $raw;
            } elseif (is_array($entry)) {
                $variant = $entry['variant'] ?? 'secondary';
                $lbl     = $entry['label']   ?? $raw;
            } elseif (strpos((string)$entry, ':') !== false) {
                [$variant, $lbl] = explode(':', (string)$entry, 2);
            } else {
                $variant = (string)$entry;
                $lbl     = $raw;
            }

            // Paleta equivalente aos tokens do mad-ui.css (cores sólidas
            // em vez das classes com variáveis CSS que Dompdf não resolve).
            $palette = [
                'success'   => ['bg' => '#dcfce7', 'fg' => '#166534'],
                'info'      => ['bg' => '#dbeafe', 'fg' => '#1e40af'],
                'warning'   => ['bg' => '#fef3c7', 'fg' => '#92400e'],
                'danger'    => ['bg' => '#fee2e2', 'fg' => '#991b1b'],
                'error'     => ['bg' => '#fee2e2', 'fg' => '#991b1b'],
                'primary'   => ['bg' => '#e0e7ff', 'fg' => '#3730a3'],
                'secondary' => ['bg' => '#f1f5f9', 'fg' => '#475569'],
                'default'   => ['bg' => '#f1f5f9', 'fg' => '#475569'],
            ];
            $c = $palette[$variant] ?? $palette['secondary'];
            $label = htmlspecialchars($lbl, ENT_QUOTES);
            $style = "display:inline-block;padding:2px 8px;border-radius:10px;"
                   . "font-size:8px;font-weight:600;"
                   . "background:{$c['bg']};color:{$c['fg']};";
            return "<span style=\"{$style}\">{$label}</span>";
        }

        // Arquivo embutido na celula sem transformador: no PDF vira o NOME,
        // igual a coluna que declara media. Sem isto o fallback abaixo cairia
        // na rede de protecao do renderValue e embutiria a imagem inteira no
        // PDF (megabytes por linha).
        if (!$this->hasTransform && \Mad\Support\MadMediaFormatter::looksLikeInlineFile($value)) {
            return htmlspecialchars(\Mad\Support\MadMediaFormatter::renderPlain($value), ENT_QUOTES);
        }

        // Transform com isHtml — deixa passar (Dompdf renderiza); sem isHtml,
        // renderValue ja retorna com htmlspecialchars aplicado.
        $out = $this->renderValue($value, $row, $lastRow);

        // Se chegou aqui e nao e badge, renderValue ja cuidou do escape.
        return $out;
    }
}