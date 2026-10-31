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
    /**
     * Texto da miniatura de mídia (dica, `alt`, legenda do lightbox, texto do
     * link): fixo ou com `{campo}`/`{relacao->campo}` da linha. '' = nome do
     * arquivo, ou nenhum texto quando a imagem está gravada no banco.
     */
    public string  $mediaCaption = '';
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
    /**
     * Total do rodapé calculado a partir das SOMAS de outros campos
     * (`total-formula="{ctb} / {total} * 100"`): cada `{campo}` vale a soma do
     * campo em todas as linhas. Média ponderada, margem, % de contribuição —
     * o que `sum`/`avg` da própria coluna não dão. Hoje só o
     * `<mad-detail-form>` calcula; vence o `totalFunc` quando os dois vêm.
     */
    public string  $totalFormula = '';
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

    // ── Acabamento da célula (fw#494) ──────────────────────────────────────
    /**
     * Linhas de texto antes das reticências. null = o padrão da grade (no ERP
     * ou com `truncate`, 1); 0 = quebra livre, nunca corta; N = até N linhas,
     * em qualquer tema. O valor inteiro aparece na dica quando cortou.
     */
    public ?int    $lines         = null;
    /** Campo (ou máscara `{campo}`) da 2ª linha menor da célula: meta. */
    public string  $subtitleField = '';
    /** Campo (ou máscara `{campo}`) da 2ª linha em fonte mono: código. */
    public string  $codeField     = '';
    /** align() chamado (atributo center/right/align): o tema não mexe no alinhamento. */
    public bool    $alignSet      = false;
    /** grow(): a coluna fica com a sobra da largura quando o texto corta (fw#494b). */
    public bool    $grow          = false;
    /**
     * Forma da coluna na página, calculada a cada render por assignShapes():
     * 'grow' (fica com a sobra), 'token' (valores de uma palavra só: sai
     * inteira) ou '' (texto que cede quando falta largura).
     */
    public string  $shape         = '';
    /**
     * Coluna de texto puro (assignShapes): a célula de valor curto — nome,
     * setor — quebra só entre palavras, sem partir palavra (fw#495). Decidido
     * por célula (cellClass), para a linha redesenhada sair igual à da página.
     */
    public bool    $wrapWords     = false;
    /**
     * Lugar da coluna no cartão do celular (fw#495), calculado a cada render
     * por assignCardRoles(): 'title', 'code' (linha pequena acima do título),
     * 'meta' (rótulo: valor), 'badge' (linha dos selos) ou '' (fora do cartão).
     */
    public string  $cardSlot      = '';

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
        $this->align    = $align;
        $this->alignSet = true;
        return $this;
    }

    /** Até N linhas de texto na célula antes das reticências (0 = sem limite). */
    public function lines(int $n): self
    {
        $this->lines = max(0, $n);
        return $this;
    }

    /** 2ª linha menor sob o valor: campo da linha ou máscara '{campo} · {outro}'. */
    public function subtitle(string $fieldOrMask): self
    {
        $this->subtitleField = trim($fieldOrMask);
        return $this;
    }

    /** 2ª linha em fonte mono sob o valor (código, número do documento). */
    public function code(string $fieldOrMask): self
    {
        $this->codeField = trim($fieldOrMask);
        return $this;
    }

    /** Fica com a largura que sobra quando o texto da grade corta (título, descrição). */
    public function grow(bool $on = true): self
    {
        $this->grow = $on;
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
    /**
     * Texto mostrado na miniatura de mídia no lugar do nome do arquivo.
     *
     *   ->transform('file-avatar')->mediaCaption('{nome}')
     *   ->transform('file-thumb')->mediaCaption('Foto de {cliente->nome}')
     */
    public function mediaCaption(string $caption): self
    {
        $this->mediaCaption = trim($caption);
        return $this;
    }

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

    /** Entrada do mapa para o valor (ver {@see badgeLookup}). */
    private function badgeEntry(mixed $value): mixed
    {
        return self::badgeLookup($this->badgeMap, $value);
    }

    /**
     * Entrada de um mapa de selo para o valor. A chave exata vence; valor
     * Sim/Não — `true`/`false`, `1`/`0`, `'t'`/`'f'` (Postgres sem cast),
     * `'true'`/`'false'` — casa também com a outra grafia da chave: `1` ↔
     * `true`, `0` ↔ `false`. O autor escreve uma, o banco entrega a outra
     * (fw#488). Público porque o `<mad-display-field badge>` usa a mesma regra.
     */
    public static function badgeLookup(array $map, mixed $value): mixed
    {
        $key = self::badgeValue($value);
        if (array_key_exists($key, $map)) {
            return $map[$key];
        }
        $flag = self::badgeFlag($value);
        if ($flag === null) {
            return null;
        }
        foreach ($flag ? ['1', 'true'] : ['0', 'false'] as $alias) {
            if (array_key_exists($alias, $map)) {
                return $map[$alias];
            }
        }
        return null;
    }

    /** Valor Sim/Não reconhecível → true/false; qualquer outro → null. */
    private static function badgeFlag(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 1 || $value === 0) {
            return $value === 1;
        }
        if (is_string($value)) {
            return match (strtolower(trim($value))) {
                '1', 't', 'true'  => true,
                '0', 'f', 'false' => false,
                default           => null,
            };
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
        // O formato padrão (`date` sem valor) segue o idioma do app: em inglês
        // "10/31/2026". Em português/espanhol continua d/m/Y.
        $this->dateFormat = match ($format) {
            'd/m/Y'     => \Mad\Support\ValueFormatter::dateFormat(),
            'd/m/Y H:i' => \Mad\Support\ValueFormatter::dateFormat(true),
            default     => $format,
        };
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

    public function totalFormula(string $formula): self
    {
        $this->totalFormula = trim($formula);
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
     * Formatos built-in curtos, de largura previsível: a célula sai numa linha
     * só. Texto livre e data por extenso continuam quebrando.
     */
    private const NOWRAP_FORMATS = [
        'money', 'currency-usd', 'currency-eur', 'currency-gbp', 'currency-jpy',
        'number', 'number-en', 'integer', 'percent', 'decimal-4',
        'date', 'date-iso', 'date-month-year', 'datetime', 'datetime-seconds', 'time',
        'cpf', 'cnpj', 'cep', 'phone-br',
    ];

    /**
     * Classe (com o espaço na frente) da `<td>` cujo valor não pode quebrar no
     * meio: valor, número, data e os formatos curtos. Numa grade que não cabe
     * na tela cada coluna encolhe até a menor palavra e "R$ 6,00" virava duas
     * linhas — a largura da coluna é só a preferida, não segura a quebra.
     * Coluna com transformador próprio, selo ou HTML fica como estava: o
     * conteúdo é de quem escreveu.
     */
    public function nowrapClass(): string
    {
        if ($this->hasTransform || $this->mediaFormat !== '' || $this->isBadge || $this->isHtml) {
            return '';
        }
        if ($this->isMoney || $this->isNumber || $this->isDate) {
            return ' mad-dg-cell-nowrap';
        }

        return in_array($this->builtinFormat, self::NOWRAP_FORMATS, true) ? ' mad-dg-cell-nowrap' : '';
    }

    /** Formatos de número (sem data/documento): coluna de valor, alinhada à direita no ERP. */
    private const NUMERIC_FORMATS = [
        'money', 'currency-usd', 'currency-eur', 'currency-gbp', 'currency-jpy',
        'number', 'number-en', 'integer', 'percent', 'decimal-4',
    ];

    /** Coluna de valor ou número, sem transformador/HTML próprio. */
    public function isNumeric(): bool
    {
        if ($this->hasTransform || $this->mediaFormat !== '' || $this->isBadge || $this->isHtml) {
            return false;
        }

        return $this->isMoney || $this->isNumber || in_array($this->builtinFormat, self::NUMERIC_FORMATS, true);
    }

    /**
     * text-align da <th>/<td>. Coluna numérica sem alinhamento declarado segue
     * --mad-dg-num-align: o tema ERP põe à direita; no default a var não
     * existe e fica o `left` de sempre.
     */
    public function alignCss(): string
    {
        return $this->isNumeric() && !$this->alignSet
            ? 'var(--mad-dg-num-align, ' . $this->align . ')'
            : $this->align;
    }

    /**
     * Classes extras da <td> (com o espaço na frente): número, célula de 2
     * linhas, forma da coluna e, com o valor da célula, texto curto (fw#495).
     */
    public function cellClass(mixed $value = null): string
    {
        return ($this->isNumeric() ? ' mad-dg-cell-num' : '')
            . ($this->subtitleField !== '' || $this->codeField !== '' ? ' mad-dg-cell-rich' : '')
            . match ($this->shape) { 'grow' => ' mad-dg-col-grow', 'token' => ' mad-dg-cell-token', default => '' }
            . ($this->wrapWords && self::isShortText($value) ? ' mad-dg-cell-short' : '');
    }

    /**
     * Texto curto (nome de pessoa, setor, unidade): até 5 palavras e 32
     * letras, ou uma palavra de até 24 ("Infraestrutura" numa coluna de setor
     * não vira "Infraestrut…").
     */
    public static function isShortText(mixed $value): bool
    {
        if (!is_scalar($value) || ($v = trim((string) $value)) === '' || mb_strlen($v) > 32) return false;
        $words = count(preg_split('/\s+/u', $v));

        return $words === 1 ? mb_strlen($v) <= 24 : $words <= 5;
    }

    /**
     * Largura da grade com o texto cortado (fw#494b): cada coluna de texto
     * ganha uma forma, que o CSS lê (só com truncate ligado, ou no ERP).
     *  - grow: fica com a sobra da largura — a marcada com grow() ou, sem
     *    ela, a 1ª coluna de texto longo de várias palavras na página (o
     *    título); sem isto a tabela repartia a largura e o título saía tão
     *    estreito quanto as colunas curtas;
     *  - token: coluna cujos valores na página são uma palavra só, de até 24
     *    letras (código, tipo): sai inteira, sem reticências;
     *  - '': o resto do texto, que cede até 5em quando falta largura.
     * Nas colunas de texto fora do grow, a célula de valor curto (até 5
     * palavras e 32 letras, ou uma de até 24: nome, setor) quebra só entre
     * palavras, em até 2 linhas — ninguém vira "Gestor de…" nem
     * "Infraestrut…" (wrapWords, fw#495).
     * Valor, data, selo, mídia, HTML, transform, edição e `lines` ficam fora.
     */
    public static function assignShapes(array $columns, array $rows): void
    {
        $stats = [];
        foreach ($columns as $i => $col) {
            if (!$col instanceof self) continue;
            $col->shape = '';
            $col->wrapWords = false;
            if ($col->grow || $col->hasTransform || $col->transform !== null || $col->mediaFormat !== '' || $col->isBadge || $col->isHtml
                || $col->editable || $col->lines !== null || $col->nowrapClass() !== '' || $col->isNumeric()) {
                continue;
            }
            $st = ['seen' => false, 'multi' => false, 'len' => 0, 'words' => 0];
            foreach ($rows as $row) {
                $v = is_array($row) ? ($row[$col->field] ?? null) : null;
                if (!is_scalar($v) || ($v = trim((string) $v)) === '') continue;
                $words = count(preg_split('/\s+/u', $v));
                $st['seen']  = true;
                $st['multi'] = $st['multi'] || $words > 1;
                $st['len']   = max($st['len'], mb_strlen($v));
                $st['words'] = max($st['words'], $words);
            }
            $col->wrapWords = true;
            if ($st['seen']) $stats[$i] = $st;
        }
        // Coluna só de texto curto (nome, setor): não é o título.
        $short = fn (array $st) => $st['len'] <= 32 && $st['words'] <= 5;

        // grow: as declaradas (sem width) ou, sem nenhuma, a 1ª com texto longo
        // de várias palavras na página (o título); sem texto longo, a 1ª de
        // várias palavras.
        $growAt = [];
        foreach ($columns as $i => $col) {
            if ($col instanceof self && $col->grow && trim((string) $col->width) === '') $growAt[] = $i;
        }
        foreach ([fn ($st) => !$short($st), fn ($st) => true] as $pick) {
            if ($growAt !== []) break;
            foreach ($stats as $i => $st) if ($st['multi'] && $pick($st) && trim((string) $columns[$i]->width) === '') { $growAt = [$i]; break; }
        }
        foreach ($columns as $i => $col) {
            if (!$col instanceof self) continue;
            if (in_array($i, $growAt, true)) { $col->shape = 'grow'; $col->wrapWords = false; continue; }
            $st = $stats[$i] ?? null;
            if ($st !== null && !$st['multi'] && $st['len'] <= 24) { $col->shape = 'token'; $col->wrapWords = false; }
        }
    }

    /** Nome de campo de código/número do registro: vai na linha pequena acima do título. */
    private const CARD_CODE_FIELD = '/^(id|cod|codigo|code|num|numero|number|nr|no|n|sku|ref|referencia|protocolo|matricula)(_|$)/i';

    /**
     * Cartão do celular (fw#495): abaixo de 640 px a linha vira cartão (o CSS
     * empilha a própria <tr>; no ERP é o padrão, no default `mobile="cards"`).
     * Cada coluna ganha um lugar, que a célula leva em `data-card`:
     *  - title: a marcada com card-role="title" ou a 1ª coluna de texto que
     *    não é código (nem número, data, valor, documento, selo ou mídia),
     *    de preferência sem transform/HTML;
     *  - code: a marcada com card-role="subtitle" ou, antes do título, a 1ª
     *    com cara de código/número (nome de campo, inteiro ou valores de uma
     *    palavra na página): "Nº 1042" acima do título;
     *  - badge: selo (badge, card-role="badge"); a célula de transform/HTML
     *    que desenhou um selo também vai para a linha dos selos (cardAttrs);
     *  - meta: as demais, "Rótulo: valor" — sem `card-columns` o CSS mostra
     *    as 3 primeiras.
     * `card-columns` escolhe as colunas do cartão (as outras saem); a 1ª de
     * texto da lista vira o título, as metas seguem a ordem da tabela.
     * Mídia e card-role="image" ficam de fora. A forma (assignShapes) vem antes.
     *
     * @param list<string> $cardColumns campos de `card-columns` ([] = todas)
     */
    public static function assignCardRoles(array $columns, array $cardColumns, array $rows): void
    {
        $cols = array_values(array_filter($columns, fn ($c) => $c instanceof self));
        foreach ($cols as $c) $c->cardSlot = '';
        if ($cardColumns !== []) {
            // `nome` e `{nome}` são o mesmo campo dos dois lados: a coluna
            // `field="{nome}"` com `card-columns="nome"` (o exemplo da prop)
            // não casava nada e o cartão saía em branco (fw#513).
            $key = fn ($v): string => trim(trim((string) $v), '{} ');
            $byName = [];
            foreach ($cols as $c) { $byName[$key($c->field)] = $c; $byName[$key($c->fieldKey)] = $c; }
            $picked = [];
            foreach ($cardColumns as $f) {
                $c = $byName[$key($f)] ?? null;
                if ($c !== null && !in_array($c, $picked, true)) $picked[] = $c;
            }
            if ($picked === []) {
                // Nenhum item casou: o cartão mostra todas, nunca vazio.
                error_log('[mad-grid] card-columns não casa com nenhuma coluna: ' . implode(',', $cardColumns));
            } else {
                $cols = $picked;
            }
        }
        $cols = array_values(array_filter($cols, fn ($c) => $c->mediaFormat === '' && $c->cardRole !== 'image'));
        $isBadge  = fn ($c) => $c->isBadge || $c->cardRole === 'badge';
        $isCode   = fn ($c) => preg_match(self::CARD_CODE_FIELD, $c->field) === 1
            || in_array($c->builtinFormat, ['integer'], true)
            || ($c->shape === 'token' && count($rows) > 1);
        $isText   = fn ($c) => !$isBadge($c) && !$c->isNumeric() && !$c->isDate
            && !in_array($c->builtinFormat, self::NOWRAP_FORMATS, true);
        $isPlain  = fn ($c) => !$c->hasTransform && $c->transform === null && !$c->isHtml;

        $title = null;
        foreach ($cols as $c) if ($c->cardRole === 'title') { $title = $c; break; }
        if ($title === null) foreach ($cols as $c) if ($isText($c) && !$isCode($c) && $isPlain($c)) { $title = $c; break; }
        if ($title === null) foreach ($cols as $c) if ($isText($c) && !$isCode($c)) { $title = $c; break; }
        if ($title === null) foreach ($cols as $c) if (!$isBadge($c)) { $title = $c; break; }
        $code = null;
        foreach ($cols as $c) if ($c->cardRole === 'subtitle') { $code = $c; break; }
        if ($code === null && $title !== null) {
            foreach ($cols as $c) {
                if ($c === $title) break;
                if ($isCode($c) && !$isBadge($c)) { $code = $c; break; }
            }
        }
        foreach ($cols as $c) {
            $c->cardSlot = match (true) {
                $c === $title => 'title',
                $c === $code  => 'code',
                $isBadge($c)  => 'badge',
                default       => 'meta',
            };
        }
    }

    /**
     * Atributos do cartão na <td> (fw#495): `data-card` e, na meta e no
     * código, `data-label` com o rótulo. Célula vazia sai do cartão; transform
     * ou HTML que desenhou um selo vai para a linha dos selos. Sem lugar: nada.
     */
    public function cardAttrs(string $rendered): string
    {
        if ($this->cardSlot === '') return '';
        $slot = $this->cardSlot;
        if (trim(strip_tags($rendered, '<img><svg><i>')) === '') {
            $slot = 'off';
        } elseif ($slot === 'meta' && str_contains($rendered, 'mad-badge')) {
            $slot = 'badge';
        }
        $label = in_array($slot, ['meta', 'code'], true) && trim($this->label) !== ''
            ? ' data-label="' . htmlspecialchars(trim(strip_tags($this->label)), ENT_QUOTES) . '"'
            : '';

        return ' data-card="' . $slot . '"' . $label;
    }

    /**
     * Conteúdo da <td> de leitura: o valor dentro de `.mad-dg-text` (o CSS
     * corta em 1 linha no ERP ou com `truncate`; inerte no default) e a 2ª
     * linha do subtitle/code. Mídia e as células que não quebram (valor, data)
     * saem como antes, sem invólucro.
     */
    public function cellHtml(string $rendered, array $row): string
    {
        $rich = $this->subtitleField !== '' || $this->codeField !== '';
        if ($this->mediaFormat !== '' || ($this->nowrapClass() !== '' && !$rich && $this->lines === null)) {
            return $rendered;
        }
        $attr = $this->lines !== null
            ? ' data-lines="' . $this->lines . '"' . ($this->lines > 0 ? ' style="--mad-dg-lines:' . $this->lines . '"' : '')
            : '';
        $html = '<div class="mad-dg-text"' . $attr . '>' . $rendered . '</div>';
        $sub  = [];
        foreach (['mad-dg-sub mad-dg-sub-code' => $this->codeField, 'mad-dg-sub' => $this->subtitleField] as $cls => $src) {
            if ($src === '') continue;
            $text = GridRenderHelpers::resolveTemplate(str_contains($src, '{') ? $src : '{' . $src . '}', $row['__record'] ?? $row, $row);
            if (trim($text) !== '') $sub[] = '<div class="' . $cls . '">' . htmlspecialchars($text, ENT_QUOTES) . '</div>';
        }

        return $html . implode('', $sub);
    }

    /**
     * Renderiza o valor de uma célula aplicando as transformações configuradas.
     *
     * @param mixed      $value   Valor cru da coluna
     * @param array      $row     Linha completa (array)
     * @param array|null $lastRow Linha anterior (para comparações entre linhas)
     * @return string             HTML renderizado
     */
    /**
     * A linha como objeto para o transform: relação carregada no registro vai
     * como OBJETO (`$row->fornecedor->nome_fantasia`), não como o array do
     * `toArray()`. A linha redesenhada pelo `manageRow` entregava o array e o
     * transform quebrava com "Attempt to read property on array" (fw#517).
     *
     * @param array<string, mixed> $row
     */
    public static function rowObject(array $row): object
    {
        $obj    = (object) $row;
        $record = $row['__record'] ?? null;
        if ($record instanceof \Illuminate\Database\Eloquent\Model) {
            foreach ($record->getRelations() as $name => $related) {
                $obj->{$name} = $related;
                $snake = \Illuminate\Support\Str::snake((string) $name);
                if ($snake !== $name && property_exists($obj, $snake)) {
                    $obj->{$snake} = $related;
                }
            }
        }

        return $obj;
    }

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
                self::rowObject($row),
                $row,
                $this,
                $lastRow ? self::rowObject($lastRow) : null
            );
            return $this->isHtml ? (string)$out : htmlspecialchars((string)$out, ENT_QUOTES);
        }

        // Mídia (file-avatar/thumb/link/gallery) — HTML raw dedicado; todo
        // valor dinâmico já sai escapado do MadMediaFormatter.
        if ($this->mediaFormat !== '') {
            $caption = $this->mediaCaption !== ''
                ? GridRenderHelpers::resolveTemplate($this->mediaCaption, $row['__record'] ?? $row, $row)
                : '';
            return \Mad\Support\MadMediaFormatter::render($value, $this->mediaFormat, $caption);
        }

        // Badge — formatos suportados:
        //   'value' => 'success'                             → variant, label=value
        //   'value' => 'success:Ativo'                       → variant:label
        //   'value' => ['variant'=>'success','label'=>'Ativo']→ array
        if ($this->isBadge) {
            $entry = $this->badgeEntry($value);
            $raw   = self::badgeValue($value);
            // Valor vazio sem entrada no mapa: célula vazia, não um selo sem
            // texto (que aparecia como uma bolinha solta).
            if ($entry === null && trim($raw) === '') {
                return '';
            }
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
            [$dec, $tho] = \Mad\Support\ValueFormatter::separators();
            $fmt = number_format($num, 2, $dec, $tho);
            $pre = htmlspecialchars($this->moneyPrefix, ENT_QUOTES);
            return $pre . \Mad\Support\ValueFormatter::moneyGap() . $fmt;
        }

        // Number
        if ($this->isNumber) {
            $num = is_numeric($value) ? (float)$value : 0.0;
            [$dec, $tho] = \Mad\Support\ValueFormatter::separators();
            return number_format($num, $this->numberDecimals, $dec, $tho);
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

        // Coluna `boolean` sem selo nem formato: '1'/'0', como no selo.
        // `(string) false` é '' e a linha desligada saía em branco, parecendo
        // nula (fórum #129).
        return htmlspecialchars(self::badgeValue($value), ENT_QUOTES);
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

        return $d->format('H:i:s') === '00:00:00'
            ? $d->format(\Mad\Support\ValueFormatter::dateFormat())
            : $d->format(\Mad\Support\ValueFormatter::dateFormat(true));
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
            // Valor vazio sem entrada no mapa: célula vazia, não um selo sem
            // texto (que aparecia como uma bolinha solta).
            if ($entry === null && trim($raw) === '') {
                return '';
            }
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