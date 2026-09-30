<?php
namespace Mad\Filters;

use Mad\Form\MadForm;
use Mad\Util\MadTempo;

/**
 * MadFiltersTrait — Filter machinery shared by MadDashboard and MadDataGrid.
 *
 * Consumers must declare (on host class):
 *   - protected string $periodType    ('month-year' | 'date-range' | 'preset' | 'none')
 *   - protected string $dateField
 *   - protected array  $periodFields  (e.g. ['mes' => 'mes', 'ano' => 'ano'])
 *   - protected bool   $defaultToCurrentPeriod
 *   - protected bool   $usePresets
 *   - protected bool   $rememberFilters
 *   - protected bool   $applyUnitFilter
 *   - protected string $unitField
 *   - protected array  $unitFields
 *   - protected array  $skipAutoFilter
 *   - protected array  $autoFilterOps (coluna => operador do filtro automatico:
 *       '=', '!=', 'like', 'starts', 'ends', '>', '>=', '<', '<='. Sem
 *       declaracao, coluna de texto usa 'like' e o resto '='.)
 *   - protected array  $notFilterProps (public props que NAO sao filtros:
 *       ficam fora de hydrate/limpar/snapshot/contagem — opt-out da discovery)
 *   - protected string $database (or override _filtersDb())
 *
 * Trait owns:
 *   - public MadForm $form
 *   - public string  $mes, $ano, $dtIni, $dtFim, $preset
 *
 * Apply trigger:
 *   Override applyFiltersChanged() in host. Default impl calls forceFullRender()
 *   which works for MadDashboard. MadDataGrid overrides to reset page + reload.
 *
 * onSearch contract:
 *   Trait does NOT declare onSearch() (sig collision between hosts).
 *   Trait calls it via reflection inside _applyOnSearch — only when the host
 *   declared a `(Builder $q [, ?string])` version.
 *   The 0-arg `(): void` MadDataGrid version is invoked separately by the grid
 *   itself when building its query.
 */
trait MadFiltersTrait
{
    // ───────────────────────────────────────────────────────────────────
    // STATE PUBLICO (serializado via MadComponent)
    // ───────────────────────────────────────────────────────────────────

    public MadForm $form;
    public string $mes    = '';
    public string $ano    = '';
    public string $dtIni  = '';
    public string $dtFim  = '';
    public string $preset = '';

    /** Props "reservadas" — sao state interno do trait, nao filtros. */
    // requireFilterFields: config do <mad-grid require-filter-fields> — array
    // público que o onLimpar zeraria se fosse tratado como filtro.
    private static array $_MAD_FILTERS_RESERVED = ['form', 'mes', 'ano', 'dtIni', 'dtFim', 'preset', 'requireFilterFields'];

    /** Cache de colunas por (database, modelClass). Vazio = schema desconhecido. */
    private static array $__columnsCache = [];

    /** Cache de tipo por (database, modelClass, coluna). '' = desconhecido. */
    private static array $__columnTypeCache = [];

    /**
     * Operadores vindos do blade (`filter-op=` num filho de
     * <mad-*-filters>), registrados por declareFilterOps() no render do bloco.
     * Estado de INSTANCIA de proposito: registry estatico sobreviveria ao
     * request no Octane e vazaria operador de uma tela pra outra.
     */
    protected array $_madBladeFilterOps = [];

    /** Operadores aceitos no filtro automatico. */
    public const AUTO_FILTER_OPS = ['=', '!=', 'like', 'starts', 'ends', '>', '>=', '<', '<='];

    // ───────────────────────────────────────────────────────────────────
    // HOOKS DO HOST
    // ───────────────────────────────────────────────────────────────────

    /**
     * Trigger chamado quando filtros mudam (apply, clear, setProp, setMesAno).
     * Default: forceFullRender() — funciona pra MadDashboard.
     * MadDataGrid override: $this->page = 1; $this->loadData();
     */
    protected function applyFiltersChanged(): void
    {
        if (method_exists($this, 'forceFullRender')) {
            $this->forceFullRender();
        }
    }

    /** Inicializa $this->form lazy. Hosts chamam isso no mount(). */
    protected function _initFiltersForm(): void
    {
        if (!isset($this->form)) {
            $this->form = new MadForm('form');
        }
    }

    /** Resolve database pra operacoes do trait. */
    protected function _filtersDb(): string
    {
        $db = $this->database ?? '';
        if ($db !== '') return $db;
        return defined('MAIN_DATABASE') ? MAIN_DATABASE : 'business';
    }

    // ───────────────────────────────────────────────────────────────────
    // HYDRATE / SYNC
    // ───────────────────────────────────────────────────────────────────

    /** Aplica valores de uma fonte (request, session, params) nos filters. */
    protected function hydrateFiltersFromArray(array $source): void
    {
        foreach (['mes', 'ano', 'dtIni', 'dtFim', 'preset'] as $k) {
            if (isset($source[$k]) && is_scalar($source[$k]) && (string) $source[$k] !== '') {
                $this->$k = (string) $source[$k];
            }
        }
        foreach ($this->discoverFilterProps() as $name) {
            if (!array_key_exists($name, $source)) continue;
            $val = $this->_coerceFilterValue($name, $source[$name]);
            // Vazio nao sobrescreve (simetrico com as period keys acima) —
            // um campo vazio no request nao apaga valor restaurado da session.
            if ($val === null || $val === '' || $val === []) continue;
            $this->$name = $val;
        }
    }

    /**
     * Coage valor de fonte externa (request/params/session) pro tipo declarado
     * da prop. Retorna null quando incompativel — request malformado
     * (?prop[]=x contra prop string) nao pode fatalar com TypeError.
     */
    private function _coerceFilterValue(string $name, mixed $value): string|array|null
    {
        try {
            $type = (new \ReflectionProperty($this, $name))->getType();
        } catch (\ReflectionException $e) {
            return null;
        }
        $tname = $type instanceof \ReflectionNamedType ? $type->getName() : null;

        if (is_array($value)) {
            if ($tname === 'string') return null;
            // So elementos escalares — nested array estoura no whereIn.
            return array_values(array_filter($value, 'is_scalar'));
        }
        if (!is_scalar($value)) return null;
        $str = (string) $value;
        if ($tname === 'array') {
            // Lista em texto: querystring de Mad.get/overlay (URLSearchParams
            // junta array com vírgula) ou CSV "1,2" em URL — vira itens.
            if ($str === '') return [];
            return array_values(array_filter(array_map('trim', explode(',', $str)), fn ($v) => $v !== ''));
        }
        return $str;
    }

    /** Sincroniza form->fields com props publicas pra renderizar selected. */
    protected function syncFormFields(): void
    {
        if (!isset($this->form) || !($this->form instanceof MadForm)) return;
        foreach (['mes', 'ano', 'dtIni', 'dtFim', 'preset'] as $k) {
            $this->form->set($k, $this->$k);
        }
        foreach ($this->discoverFilterProps() as $name) {
            $this->form->set($name, $this->$name);
        }
    }

    /**
     * Hook que cada host chama do seu metodo `updated($prop, $value)`.
     * Espelha mudancas em form->fields automaticamente.
     */
    protected function _filtersUpdatedHook(string $prop, mixed $value): void
    {
        if (!isset($this->form) || !($this->form instanceof MadForm)) return;

        $periodKeys = ['mes', 'ano', 'dtIni', 'dtFim', 'preset'];
        if (in_array($prop, $periodKeys, true)
            || in_array($prop, $this->discoverFilterProps(), true)) {
            $this->form->set($prop, $value);
        }

        // Preset "fechado" (nao-custom) torna dtIni/dtFim obsoletos — limpa
        // pra nao vazarem em currentFilters()/drillTo()/contagem de badges.
        if ($prop === 'preset' && $value !== '' && $value !== 'custom'
            && ($this->dtIni !== '' || $this->dtFim !== '')) {
            $this->dtIni = '';
            $this->dtFim = '';
            $this->form->set('dtIni', '');
            $this->form->set('dtFim', '');
        }
    }

    // ───────────────────────────────────────────────────────────────────
    // AUTO-DISCOVERY DE PROPS
    // ───────────────────────────────────────────────────────────────────

    /**
     * Auto-discovery: TODAS public props que sao filtros (state purposes).
     * Inclui props em $skipAutoFilter — fazem parte do state mesmo se nao auto-aplicam.
     */
    protected function discoverFilterProps(): array
    {
        static $cache = [];
        $class = static::class;
        if (isset($cache[$class])) {
            return $cache[$class];
        }
        $props = [];
        $ref = new \ReflectionClass($class);
        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $p) {
            $name = $p->getName();
            if ($p->isStatic()) continue;
            if ($name !== '' && $name[0] === '_') continue;
            if (in_array($name, self::$_MAD_FILTERS_RESERVED, true)) continue;
            // Opt-out do host: public prop que NAO e filtro (nao hidrata via
            // request, nao reseta no onLimpar, nao entra em snapshot/contagem).
            if (in_array($name, $this->notFilterProps ?? [], true)) continue;
            $type = $p->getType();
            if ($type instanceof \ReflectionNamedType) {
                $tname = $type->getName();
                if ($tname !== 'string' && $tname !== 'array') continue;
            }
            $props[] = $name;
        }
        return $cache[$class] = $props;
    }

    /** Subset de discoverFilterProps() — exclui props em $skipAutoFilter. */
    protected function autoFilterProps(): array
    {
        $skip = $this->skipAutoFilter ?? [];
        if (empty($skip)) return $this->discoverFilterProps();
        return array_values(array_filter(
            $this->discoverFilterProps(),
            fn($n) => !in_array($n, $skip, true)
        ));
    }

    /** Resolve coluna mes/ano pra um model especifico via $periodFields. */
    protected function periodColumnFor(string $which, ?string $model = null): ?string
    {
        $pf = $this->periodFields ?? [];
        [$found, $perModel] = $this->_modelMapFind($pf, $model);
        if ($found && is_array($perModel)) {
            return !empty($perModel[$which]) ? (string) $perModel[$which] : null;
        }
        return !empty($pf[$which]) ? (string) $pf[$which] : null;
    }

    /**
     * Lookup tolerante em mapas keyed-por-model ($periodFields/$unitFields):
     * aceita a chave como veio da blade, o FQCN resolvido ou o basename —
     * antes um override keyed por FQCN silenciosamente nao pegava quando a
     * blade usava short-name. Retorna [found, value].
     */
    private function _modelMapFind(array $map, ?string $model): array
    {
        if ($model === null || $map === []) return [false, null];
        if (array_key_exists($model, $map)) return [true, $map[$model]];

        $cands = [basename(str_replace('\\', '/', $model))];
        try {
            $fqcn = ltrim(\Mad\Form\ModelOptionsLoader::resolveModelClass($model), '\\');
            array_unshift($cands, $fqcn, '\\' . $fqcn);
        } catch (\Throwable $e) {
        }
        foreach ($cands as $c) {
            if ($c !== $model && array_key_exists($c, $map)) return [true, $map[$c]];
        }
        return [false, null];
    }

    // ───────────────────────────────────────────────────────────────────
    // HANDLERS (publicos pra serem chamados via MadWire)
    // ───────────────────────────────────────────────────────────────────

    /** Handler primario de submit do form de filtro. */
    public function onShow(): void
    {
        $this->syncFormFields();
        $this->saveFilterSession();
        $this->applyFiltersChanged();
    }

    /** Alias publico — compatibilidade com submit="onFiltrar". */
    public function onFiltrar(): void { $this->onShow(); }

    /** Re-executa queries do dashboard/listagem sem alterar filtros. */
    public function onAtualizar(): void
    {
        $this->syncFormFields();
        $this->applyFiltersChanged();
    }

    /** Alias publico — compatibilidade com mad:click="onRefresh". */
    public function onRefresh(): void { $this->onAtualizar(); }

    /**
     * Defaults do periodo (boot e "Limpar"). Com $defaultToCurrentPeriod:
     *   month-year → mes/ano correntes (comportamento historico, mantido
     *                mesmo nos outros tipos por compatibilidade);
     *   date-range → dtIni = dia 1 do mes, dtFim = hoje (MTD — a janela que
     *                a comparacao 'mtd' espera). Antes ficava vazio e o
     *                dashboard abria agregando a TABELA INTEIRA, com a pill
     *                em "→ 0,0%" ate o usuario escolher um periodo;
     *   preset     → 'mes' (mes atual).
     */
    protected function _seedDefaultPeriod(): void
    {
        $useCurrent = $this->defaultToCurrentPeriod ?? false;
        $type       = $this->periodType ?? 'month-year';
        $this->mes   = $useCurrent ? date('m') : '';
        $this->ano   = $useCurrent ? date('Y') : '';
        $this->dtIni = $useCurrent && $type === 'date-range' ? date('Y-m-01') : '';
        $this->dtFim = $useCurrent && $type === 'date-range' ? date('Y-m-d') : '';
        $this->preset = $useCurrent && $type === 'preset' ? 'mes' : '';
    }

    /** Reseta todos os filtros pros defaults. */
    public function onLimpar(): void
    {
        $this->_seedDefaultPeriod();

        foreach ($this->discoverFilterProps() as $name) {
            $this->$name = is_array($this->$name) ? [] : '';
        }

        $this->syncFormFields();
        $this->saveFilterSession();
        $this->applyFiltersChanged();
    }

    /** Seta mes + ano numa unica acao (preset buttons). */
    public function setMesAno(string $mes = '', string $ano = ''): void
    {
        $this->mes = $mes;
        $this->ano = $ano;
        $this->syncFormFields();
        $this->saveFilterSession();
        $this->applyFiltersChanged();
    }

    /** Seta uma prop publica generica + persist + apply. */
    public function setProp(string $prop, string $value = ''): void
    {
        if (!property_exists($this, $prop)) return;
        // Prop array (multi-select): string vira lista de 1 (vazio limpa) —
        // atribuicao direta fatalaria com TypeError.
        $this->$prop = is_array($this->$prop)
            ? ($value === '' ? [] : [$value])
            : $value;
        $this->syncFormFields();
        $this->saveFilterSession();
        $this->applyFiltersChanged();
    }

    /** Clear de filtro individual. '_period' limpa mes E ano. */
    public function clearFilter(string $name): void
    {
        // "a|b" limpa múltiplas props de um filtro composto (ex.: daterange
        // com name_start|name_end) num único wire.
        foreach (explode('|', $name) as $one) {
            if ($one === '_period') {
                $this->mes = '';
                $this->ano = '';
            } elseif ($one !== '' && property_exists($this, $one)) {
                $current = $this->$one;
                $this->$one = is_array($current) ? [] : '';
            }
        }
        $this->syncFormFields();
        $this->saveFilterSession();
        $this->applyFiltersChanged();
    }


    // ───────────────────────────────────────────────────────────────────
    // BUILDER-NATIVE — applyPeriodo/Unit/AutoFilters aplicam filtros
    // direto num Eloquent Builder. Usados pelo grid e pelo baseQuery.
    // ───────────────────────────────────────────────────────────────────

    /** Builder-native de applyPeriodo. */
    public function applyPeriodoToQuery($q, ?string $model = null): void
    {
        $pt = $this->periodType ?? 'none';
        $df = $this->dateField ?? '';

        switch ($pt) {
            case 'month-year':
                $mesCol = $this->periodColumnFor('mes', $model);
                $anoCol = $this->periodColumnFor('ano', $model);
                if ($this->mes !== '' && $mesCol && $this->modelHasColumn($model, $mesCol)) {
                    $q->where($mesCol, '=', $this->mes);
                }
                if ($this->ano !== '' && $anoCol && $this->modelHasColumn($model, $anoCol)) {
                    $q->where($anoCol, '=', $this->ano);
                }
                break;

            case 'date-range':
                if ($df === '' || !$this->modelHasColumn($model, $df)) break;
                $iniDb = self::_normalizeDateToDb($this->dtIni);
                $fimDb = self::_normalizeDateToDb($this->dtFim);
                if ($iniDb !== '') {
                    $q->where($df, '>=', $iniDb);
                }
                if ($fimDb !== '') {
                    $q->where($df, '<=', $fimDb . ' 23:59:59');
                }
                break;

            case 'preset':
                $this->applyPresetsToQuery($q);
                break;
        }
    }

    /** Builder-native de applyPresets. */
    protected function applyPresetsToQuery($q): void
    {
        $df = $this->dateField ?? '';
        if ($this->preset === '' || $df === '') {
            return;
        }
        if ($this->preset === 'custom') {
            // Range custom escolhido no dropdown: aplica dtIni/dtFim como o
            // modo date-range — antes retornava sem filtro NENHUM.
            $iniDb = self::_normalizeDateToDb($this->dtIni);
            $fimDb = self::_normalizeDateToDb($this->dtFim);
            if ($iniDb !== '') $q->where($df, '>=', $iniDb);
            if ($fimDb !== '') $q->where($df, '<=', $fimDb . ' 23:59:59');
            return;
        }
        [$start, $end] = $this->presetRange($this->preset);
        if ($start === '' || $end === '') {
            return;
        }
        if (str_ends_with($start, ' 00:00:00')) {
            $start = substr($start, 0, 10);
        }
        $q->where($df, '>=', $start);
        $q->where($df, '<=', $end);
    }

    /** Builder-native de applyUnit. */
    public function applyUnitToQuery($q, ?string $model = null): void
    {
        if (!($this->applyUnitFilter ?? false)) return;
        $unit = session('userunitid');
        if (!$unit) return;

        $col = $this->unitField ?? 'unit_id';
        [$found, $override] = $this->_modelMapFind($this->unitFields ?? [], $model);
        if ($found) {
            if ($override === null) return;
            $col = (string) $override;
        }
        $q->where($col, '=', $unit);
    }

    /**
     * Builder-native de applyAutoFilters: aplica os valores das props públicas
     * direto no builder. O hook onSearch é aplicado por _applyOnSearch.
     */
    public function applyAutoFiltersToQuery($q, ?string $model = null, array $opts = []): void
    {
        $only    = $opts['only']    ?? null;
        $exclude = $opts['exclude'] ?? [];

        foreach ($this->autoFilterProps() as $name) {
            if (is_array($only) && !in_array($name, $only, true)) continue;
            if (in_array($name, $exclude, true)) continue;

            $val = $this->$name;
            if ($val === '' || $val === null || $val === []) continue;
            if ($model !== null && !$this->modelHasColumn($model, $name)) continue;

            // Coluna NUMÉRICA com valor que não é número: a opção do filtro não
            // é um valor da coluna, é uma faixa/categoria que a própria tela
            // trata (ex.: `estoque` com "baixo"/"zerado" no bloco de filtros do
            // usuário). Aplicar mesmo assim dava `where estoque = 'zerado'` —
            // zero linhas no SQLite, as linhas erradas no MySQL (o texto vira
            // 0), erro no Postgres — e nenhum aviso. Então o automático PULA.
            // Schema desconhecido segue como sempre.
            $numeric = $this->_isNumericColumn($model, $name);

            if (is_array($val)) {
                // Defesa em profundidade: nested array em whereIn = QueryException.
                $val = array_values(array_filter($val, 'is_scalar'));
                if ($numeric) {
                    $val = array_values(array_filter($val, 'is_numeric'));
                }
                if ($val === []) continue;
                $q->whereIn($name, $val);
            } else {
                $op = $this->autoFilterOpFor($name, $model);
                // like/starts/ends é busca por TEXTO pedida de propósito
                // (`filter-op=`): "ab" numa coluna de código achar nada é a
                // resposta certa, não um filtro a pular.
                if ($numeric && !is_numeric($val) && !in_array($op, ['like', 'starts', 'ends'], true)) continue;
                $this->_applyAutoFilterWhere($q, $name, (string) $val, $op);
            }
        }
        // onSearch(Builder) é aplicado por _applyOnSearch (camada B); o antigo
        // contrato 2-arg foi removido — o hook hoje recebe só o Query Builder.
    }

    /**
     * Registra os operadores declarados no blade (`filter-op=`). Chamado pelo
     * bloco compilado de <mad-*-filters>, que renderiza ANTES do host (o
     * hoistFiltersOutOfHost garante a ordem), logo antes de a query ser montada.
     *
     * @param array<string,string> $ops coluna => operador
     */
    public function declareFilterOps(array $ops): void
    {
        foreach ($ops as $col => $op) {
            $op = strtolower(trim((string) $op));
            if ($col === '' || !in_array($op, self::AUTO_FILTER_OPS, true)) continue;
            $this->_madBladeFilterOps[(string) $col] = $op;
        }
    }

    /**
     * Congela os filtros ATIVOS como texto em `$exportMeta['filters']` — o
     * `{FILTERS}` das bandas do PDF exportado pela grade.
     *
     * Chamado pelo bloco compilado de <mad-*-filters>, no render. Guardar o
     * TEXTO resolvido (e não a metadata) é deliberado: `$__dashFields` carrega
     * o `inner_b64` de cada <mad-select-field> e inflaria o mad_state, que
     * viaja em toda requisição AJAX da tela.
     *
     * Host sem `$exportMeta` (dashboards) é ignorado — o método existe pra
     * todos os hosts da trait, mas só a grade tem exportação.
     *
     * @param array<int,array<string,mixed>> $fields metadata dos campos
     */
    public function declareFilterFields(array $fields): void
    {
        if (! property_exists($this, 'exportMeta')) {
            return;
        }

        $parts = [];
        foreach ($this->activeFiltersSummary($fields) as $chip) {
            $value = trim((string) ($chip['value'] ?? ''));
            if ($value === '') continue;
            $label = trim((string) ($chip['label'] ?? ''));
            $parts[] = $label !== '' ? ($label . ': ' . $value) : $value;
        }

        $this->exportMeta['filters'] = implode('  ·  ', $parts);
    }

    /**
     * Operador do filtro automatico de uma prop.
     *
     * Precedencia: declaracao do host ($autoFilterOps) > `filter-op=` do blade
     * > default pelo TIPO da coluna.
     *
     * O default e a correcao do bug que motivou isto: o filtro lateral sempre
     * comparou com `=`, entao "parte do nome" nunca achava nada — enquanto o
     * filtro por COLUNA da mesma grid, com a mesma cara, usava `LIKE '%v%'`.
     * Coluna de texto passa a procurar por pedaco; o resto continua igualdade.
     * Schema desconhecido cai em `=` (comportamento historico).
     */
    protected function autoFilterOpFor(string $name, ?string $model = null): string
    {
        $declared = ($this->autoFilterOps ?? [])[$name] ?? ($this->_madBladeFilterOps[$name] ?? '');
        $declared = strtolower(trim((string) $declared));
        if ($declared !== '' && in_array($declared, self::AUTO_FILTER_OPS, true)) {
            return $declared;
        }

        return $this->_isTextColumn($model, $name) ? 'like' : '=';
    }

    /**
     * Aplica um operador do filtro automatico no builder.
     *
     * like/starts/ends vao por `whereLike()`: sem diferenciar maiusculas em
     * qualquer banco (`ilike` no Postgres, onde o `like` cru e case-sensitive).
     */
    protected function _applyAutoFilterWhere($q, string $col, string $val, string $op): void
    {
        switch ($op) {
            case 'like':   $q->whereLike($col, '%' . $val . '%'); break;
            case 'starts': $q->whereLike($col, $val . '%');       break;
            case 'ends':   $q->whereLike($col, '%' . $val);       break;
            default:       $q->where($col, $op, $val);
        }
    }

    /**
     * A coluna do model e textual? Falso tambem quando o schema e desconhecido
     * — na duvida mantem o `=` historico em vez de trocar a semantica de uma
     * tela publicada.
     *
     * Casa pelo RADICAL do tipo nativo porque cada driver escreve o seu:
     * varchar / character varying / nvarchar / bpchar / longtext / citext.
     */
    protected function _isTextColumn(?string $modelClass, string $column): bool
    {
        $type = $this->columnTypeOf($modelClass, $column);
        if ($type === '' || strpos($type, 'json') !== false) return false;

        return $type === 'string'
            || strpos($type, 'char') !== false
            || strpos($type, 'text') !== false;
    }

    /**
     * A coluna do model e numerica? Falso tambem quando o schema e
     * desconhecido (comportamento historico).
     *
     * Casa pelo RADICAL de cada palavra do tipo nativo, sem os digitos do fim:
     * int4/int8/float8 (Postgres), bigint unsigned / tinyint (MySQL),
     * integer / double precision / unsigned big int (SQLite). Palavra inteira
     * de proposito — `point` e `interval` contem "int" e nao sao numero.
     */
    protected function _isNumericColumn(?string $modelClass, string $column): bool
    {
        $type = $this->columnTypeOf($modelClass, $column);
        if ($type === '') return false;

        $radicals = [
            'int', 'integer', 'tinyint', 'smallint', 'mediumint', 'bigint',
            'serial', 'smallserial', 'bigserial',
            'decimal', 'dec', 'numeric', 'number', 'float', 'double', 'real',
            'money', 'smallmoney',
        ];
        foreach (preg_split('/[^a-z0-9]+/', $type, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (in_array(rtrim($word, '0123456789'), $radicals, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tipo da coluna segundo o schema, '' quando indisponivel. Cacheado junto
     * com tableColumns() — a pergunta e por request, nao por linha.
     */
    protected function columnTypeOf(?string $modelClass, string $column): string
    {
        if (!$modelClass || $column === '' || strpos($column, '(') !== false) return '';

        $key = $this->_filtersDb() . '.' . $modelClass . '.' . $column;
        if (isset(self::$__columnTypeCache[$key])) {
            return self::$__columnTypeCache[$key];
        }

        $type = '';
        try {
            $resolved = $modelClass;
            if (!class_exists($resolved)) {
                $resolved = \Mad\Form\ModelOptionsLoader::resolveModelClass($modelClass);
            }
            if (class_exists($resolved)) {
                $instance = new $resolved();
                if ($instance instanceof \Illuminate\Database\Eloquent\Model) {
                    $type = (string) $instance->getConnection()->getSchemaBuilder()
                        ->getColumnType($instance->getTable(), $column);
                }
            }
        } catch (\Throwable $e) {
            // Driver sem introspeccao de tipo (ou coluna inexistente): cai no
            // comportamento historico em vez de derrubar a listagem.
            $type = '';
        }

        return self::$__columnTypeCache[$key] = strtolower($type);
    }

    /**
     * Invoca o hook onSearch aplicando-o num Eloquent Builder.
     * Detecta o contrato:
     *   - onSearch(Builder $q [, $model])  → aplica $q->where direto.
     *   - onSearch()                       → legado (grid/kanban), aplica no builder.
     */
    /** Query\Builder subjacente — tolera Eloquent|Query Builder. */
    protected function _queryBuilder($q)
    {
        return method_exists($q, 'getQuery') ? $q->getQuery() : $q;
    }

    protected function _applyOnSearch($q, ?string $model = null): void
    {
        if (!method_exists($this, 'onSearch')) return;
        try {
            $ref  = new \ReflectionMethod($this, 'onSearch');
            $n    = $ref->getNumberOfParameters();
            $p    = $ref->getParameters();
            $type = (isset($p[0]) && $p[0]->getType()) ? ltrim((string) $p[0]->getType(), '?\\') : '';

            if ($n >= 1 && str_contains($type, 'Builder')) {
                $n >= 2 ? $this->onSearch($q, $model) : $this->onSearch($q);
            } elseif ($n === 0) {
                // onSearch() 0-arg ainda pode setar $this->searchQuery (closure que
                // recebe o builder).
                $this->onSearch();
            }
        } catch (\Throwable $e) {
            // Falha no onSearch do host nao pode passar muda: a query seguiria
            // SEM o refinamento custom (linhas a mais expostas). Loga e segue.
            error_log('[MadFilters::_applyOnSearch] ' . static::class . ': ' . $e->getMessage());
        }
    }

    /**
     * Builder do período/unit/auto-filters atual (:query) — F5 puro-builder.
     * Os hosts (dashboard/grid) usam via :query="$that->baseQuery(Model::class)".
     */
    public function baseQuery(string $model, array $opts = []): \Illuminate\Database\Eloquent\Builder
    {
        return $this->baseQueryNative($model, $opts);
    }

    /**
     * Builder-native de período+unit+auto-filters direto no Eloquent Builder,
     * sem camada intermediária (Fase 4c-1; baseQuery delega aqui).
     */
    public function baseQueryNative(string $model, array $opts = []): \Illuminate\Database\Eloquent\Builder
    {
        $class = \Mad\Form\ModelOptionsLoader::resolveModelClass($model);
        $query = $class::query();
        if (($opts['period'] ?? true) !== false) {
            $this->applyPeriodoToQuery($query, $model);
        }
        if (($opts['unit'] ?? true) !== false) {
            $this->applyUnitToQuery($query, $model);
        }
        $this->applyAutoFiltersToQuery($query, $model, $opts);
        return $query;
    }

    // ───────────────────────────────────────────────────────────────────
    // SCHEMA HELPERS
    // ───────────────────────────────────────────────────────────────────

    /** Lista colunas reais da tabela do model. Cache por request. */
    protected function tableColumns(string $database, string $modelClass): array
    {
        $key = $database . '.' . $modelClass;
        if (isset(self::$__columnsCache[$key])) {
            return self::$__columnsCache[$key];
        }

        $cols = [];
        try {
            // Blades passam short-name ('Cliente'); sem resolver, class_exists
            // falhava e o guard modelHasColumn virava fail-open permanente.
            $resolved = $modelClass;
            if (!class_exists($resolved)) {
                $resolved = \Mad\Form\ModelOptionsLoader::resolveModelClass($modelClass);
            }
            if (!class_exists($resolved)) {
                self::$__columnsCache[$key] = [];
                return [];
            }
            $instance = new $resolved();

            // Model Eloquent puro: lista colunas pela connection do próprio
            // model — sem transação explícita.
            if ($instance instanceof \Illuminate\Database\Eloquent\Model) {
                $cols = $instance->getConnection()->getSchemaBuilder()->getColumnListing($instance->getTable());
                self::$__columnsCache[$key] = $cols;
                return $cols;
            }

            if (method_exists($instance, 'getAttributes')) {
                $attrs = $instance->getAttributes();
                if (is_array($attrs) && count($attrs) > 0) {
                    self::$__columnsCache[$key] = $attrs;
                    return $attrs;
                }
            }

            $entity = $instance->getTable();
            if (!$entity) {
                self::$__columnsCache[$key] = [];
                return [];
            }

            $cols = \Illuminate\Support\Facades\Schema::connection($database)->getColumnListing($entity);
        } catch (\Throwable $e) {
            $cols = [];
        }

        self::$__columnsCache[$key] = $cols;
        return $cols;
    }

    /** Retorna true se a tabela do model tem a coluna (ou se schema desconhecido — fail-open). */
    protected function modelHasColumn(?string $modelClass, string $column): bool
    {
        if (!$modelClass) return true;
        if ($column === '') return false;

        $colName = $column;
        if (strpos($colName, '(') !== false) return true;
        if (strpos($colName, '.') !== false) {
            $colName = substr($colName, strrpos($colName, '.') + 1);
        }

        $cols = $this->tableColumns($this->_filtersDb(), $modelClass);
        if (empty($cols)) return true;
        return in_array($colName, $cols, true);
    }

    // ───────────────────────────────────────────────────────────────────
    // PRESET RANGES
    // ───────────────────────────────────────────────────────────────────

    /**
     * Normaliza data display ('d/m/Y') ou DB ('Y-m-d') pra DB format.
     * Tolerante: aceita strings com/sem hora, fallback strtotime.
     */
    private static function _normalizeDateToDb(string $v): string
    {
        $v = trim($v);
        if ($v === '') return '';
        // Ja em formato DB? Valida componentes (rejeita 9999-99-99).
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(\s.*)?$/', $v, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? substr($v, 0, 10) : '';
        }
        // d/m/Y e d-m-Y estritos: getLastErrors barra rollover (31/02→03/03)
        // e parciais ('04/07').
        foreach (['d/m/Y', 'd-m-Y'] as $fmt) {
            $dt  = \DateTime::createFromFormat('!' . $fmt, substr($v, 0, 10));
            $err = \DateTime::getLastErrors();
            $clean = $err === false || (($err['warning_count'] ?? 0) === 0 && ($err['error_count'] ?? 0) === 0);
            if ($dt && $clean) return $dt->format('Y-m-d');
        }
        // String com barra que nao casou d/m/Y: NAO cair no strtotime — ele
        // interpreta m/d/Y americano ('04/07' viraria 7 de abril).
        if (str_contains($v, '/')) return '';
        $ts = strtotime($v);
        return $ts ? date('Y-m-d', $ts) : '';
    }

    /** Retorna [start, end] em formato 'Y-m-d H:i:s' pra um preset. */
    public function presetRange(string $preset): array
    {
        // Ancora no meio-dia: aritmetica N*86400 nunca troca de dia civil
        // atravessando transicao de DST (offset maximo 1h).
        $noon = strtotime(date('Y-m-d 12:00:00'));
        switch ($preset) {
            case 'hoje':
                return [date('Y-m-d 00:00:00'), date('Y-m-d 23:59:59')];
            case 'ontem':
                return [
                    date('Y-m-d 00:00:00', $noon - 86400),
                    date('Y-m-d 23:59:59', $noon - 86400),
                ];
            case '7d':
                return [
                    date('Y-m-d 00:00:00', $noon - 6 * 86400),
                    date('Y-m-d 23:59:59'),
                ];
            case '30d':
                return [
                    date('Y-m-d 00:00:00', $noon - 29 * 86400),
                    date('Y-m-d 23:59:59'),
                ];
            case 'mes':
                return [date('Y-m-01 00:00:00'), date('Y-m-t 23:59:59')];
            case 'mes_anterior':
                $first = strtotime('first day of last month');
                $last  = strtotime('last day of last month');
                return [
                    date('Y-m-d 00:00:00', $first),
                    date('Y-m-d 23:59:59', $last),
                ];
            case 'trimestre':
                $m = (int) date('m');
                $y = (int) date('Y');
                $q = (int) floor(($m - 1) / 3) + 1;
                $startMonth = ($q - 1) * 3 + 1;
                $endMonth   = $startMonth + 2;
                $endDate    = date('Y-m-t', strtotime(sprintf('%d-%02d-01', $y, $endMonth)));
                return [
                    sprintf('%d-%02d-01 00:00:00', $y, $startMonth),
                    $endDate . ' 23:59:59',
                ];
            case 'ano':
                $y = (int) date('Y');
                return [sprintf('%d-01-01 00:00:00', $y), sprintf('%d-12-31 23:59:59', $y)];
            case 'ano_anterior':
                $y = (int) date('Y') - 1;
                return [sprintf('%d-01-01 00:00:00', $y), sprintf('%d-12-31 23:59:59', $y)];
            default:
                return ['', ''];
        }
    }

    // ───────────────────────────────────────────────────────────────────
    // CHIPS / LABELS / SUMMARY (consumidos por dash-filters-*.blade)
    // ───────────────────────────────────────────────────────────────────

    /** Resolve label exibivel pro valor atual de um filter field metadata. */
    public function resolveFilterLabel(array $field): string
    {
        // daterange: "01/01/2026 → 31/12/2026" (hook filterValueLabel do host
        // vence a formatação default de cada ponta; um lado só = "a partir de"
        // / "até").
        if (($field['type'] ?? '') === 'daterange') {
            $fmt = function (string $prop): string {
                if ($prop === '' || !property_exists($this, $prop)) {
                    return '';
                }
                $v = (string) $this->$prop;
                if ($v === '') {
                    return '';
                }
                if (method_exists($this, 'filterValueLabel')) {
                    try {
                        $custom = $this->filterValueLabel($prop, $v);
                        if (is_string($custom) && $custom !== '') {
                            return $custom;
                        }
                    } catch (\Throwable $e) {
                    }
                }
                return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)
                    ? "{$m[3]}/{$m[2]}/{$m[1]}"
                    : $v;
            };
            $ini = $fmt($field['attrs']['name_start'] ?? '');
            $fim = $fmt($field['attrs']['name_end'] ?? '');
            if ($ini !== '' && $fim !== '') {
                return "{$ini} → {$fim}";
            }
            if ($ini !== '') {
                return "≥ {$ini}";
            }
            if ($fim !== '') {
                return "≤ {$fim}";
            }

            return '';
        }

        // Fora do ramo daterange o rótulo sai da prop nomeada pelo campo. A
        // atribuição existia e foi perdida num refactor: sem ela o `$this->$name`
        // abaixo lia uma variável indefinida e TODO filtro não-daterange saía em
        // branco nos chips e na barra de filtros.
        $name = $field['attrs']['name'] ?? '';
        if ($name === '' || !property_exists($this, $name)) {
            return '';
        }

        // '__single' = item isolado de um filtro multi-valor (recursão abaixo)
        $value = array_key_exists('__single', $field) ? $field['__single'] : $this->$name;
        if ($value === '' || $value === null || $value === []) {
            return '';
        }

        // Hook do host: label custom por prop (ex.: select com :items dinâmico
        // guarda o ID — o inner do compiler não tem <option> literal pra
        // resolver o nome). Retorno não-vazio vence qualquer estratégia abaixo.
        if (method_exists($this, 'filterValueLabel')) {
            try {
                $custom = $this->filterValueLabel($name, $value);
                if (is_string($custom) && $custom !== '') {
                    return $custom;
                }
            } catch (\Throwable $e) {
                // label é cosmético — nunca derruba a toolbar
            }
        }

        $type = $field['type'] ?? '';

        // Multi-valor (ex. select multiple): rótulo de cada item; até 2 itens
        // lista os nomes, acima disso mostra a contagem.
        if (is_array($value)) {
            $labels = [];
            foreach ($value as $item) {
                if ($item === '' || $item === null || is_array($item)) {
                    continue;
                }
                $labels[] = $this->resolveFilterLabel(
                    ['attrs' => ['name' => $name] + ($field['attrs'] ?? []), '__single' => $item] + $field
                );
            }
            $labels = array_values(array_filter($labels, fn ($l) => $l !== ''));
            if ($labels === []) {
                return '';
            }

            if (count($labels) <= 2) {
                return implode(', ', $labels);
            }

            return function_exists('mad_t')
                ? mad_t('mad.filters.selected', ['n' => count($labels)])
                : count($labels) . ' selecionados';
        }

        if ($type === 'dbsearch' || $type === 'dbcombo' || $type === 'dbselect') {
            $model = $field['attrs']['model'] ?? '';
            $display = $field['attrs']['display'] ?? 'nome';
            if ($model === '') return (string) $value;
            try {
                // Short-name 'TesteCliente' → App\Models\TesteCliente; o prefixo
                // '\' cru só funciona pra classes globais (legado).
                try {
                    $full = \Mad\Form\ModelOptionsLoader::resolveModelClass($model);
                } catch (\Throwable $e) {
                    $full = (strpos($model, '\\') === 0) ? $model : ('\\' . $model);
                }
                $rec = $full::find($value);
                if (!$rec) return (string) $value;
                if (strpos($display, '{') !== false) {
                    if (method_exists($rec, 'render')) {
                        return (string) $rec->render($display);
                    }
                    return (string) preg_replace_callback('/\{([^}]+)\}/', function ($m) use ($rec) {
                        return (string) ($rec->{$m[1]} ?? '');
                    }, $display);
                }
                return (string) ($rec->$display ?? $value);
            } catch (\Throwable $e) {
                return (string) $value;
            }
        }
        if ($type === 'select') {
            // Resolve o label da <option> correspondente ao value selecionado
            // (metadata do compiler guarda o inner do <mad-select-field> em b64).
            $inner = base64_decode((string) ($field['inner_b64'] ?? '')) ?: '';
            if ($inner !== '' && preg_match_all('#<option[^>]*\bvalue\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</option>#si', $inner, $m, PREG_SET_ORDER)) {
                foreach ($m as $opt) {
                    if ((string) $opt[2] === (string) $value) {
                        $label = trim(strip_tags($opt[3]));
                        if ($label !== '') return $label;
                    }
                }
            }
            return (string) $value;
        }
        if ($type === 'date' || $type === 'daterange') {
            // Formata DB (Y-m-d) → display no formato do locale ativo.
            $db = self::_normalizeDateToDb((string) $value);
            if ($db !== '') {
                $dt = \DateTime::createFromFormat('Y-m-d', $db);
                if ($dt) return $dt->format(mad_t('mad.tempo.date_format'));
            }
            return (string) $value;
        }
        return (string) $value;
    }

    /** Check if a filter has an active (non-empty) value. */
    public function isFilterActive(array $field): bool
    {
        $name = $field['attrs']['name'] ?? '';
        if (($field['type'] ?? '') === 'period-monthyear') {
            return $this->mes !== '' || $this->ano !== '';
        }
        // daterange: 2 props (name_start/name_end), sem attr name
        if (($field['type'] ?? '') === 'daterange') {
            foreach (['name_start', 'name_end'] as $k) {
                $p = $field['attrs'][$k] ?? '';
                if ($p !== '' && property_exists($this, $p) && (string) $this->$p !== '') {
                    return true;
                }
            }

            return false;
        }
        if ($name === '' || !property_exists($this, $name)) {
            return false;
        }
        $value = $this->$name;
        return !($value === '' || $value === null || $value === []);
    }

    /** Count active filters across a list of field metadata. */
    public function activeFiltersCount(array $fields): int
    {
        $count = 0;
        foreach ($fields as $f) {
            if ($this->isFilterActive($f)) $count++;
        }
        return $count;
    }

    /**
     * Total active filters = period + auto-discovered public props.
     * Convenient pra botoes manuais fora do wrapper.
     */
    public function totalActiveFilters(): int
    {
        $count = $this->_periodIsActive() ? 1 : 0;
        foreach ($this->discoverFilterProps() as $name) {
            $value = $this->$name;
            if (!($value === '' || $value === null || $value === [])) $count++;
        }
        return $count;
    }

    /**
     * Periodo ativo considerando SO os campos do periodType configurado —
     * state stale de outro modo (ex: dtIni sobrando em month-year) nao conta.
     */
    protected function _periodIsActive(): bool
    {
        return match ($this->periodType ?? 'none') {
            'month-year' => $this->mes !== '' || $this->ano !== '',
            'date-range' => $this->dtIni !== '' || $this->dtFim !== '',
            'preset'     => $this->preset !== ''
                            && ($this->preset !== 'custom' || $this->dtIni !== '' || $this->dtFim !== ''),
            default      => false,
        };
    }

    /** Chaves de periodo relevantes pro periodType atual. */
    protected function _activePeriodKeys(): array
    {
        return match ($this->periodType ?? 'none') {
            'month-year' => ['mes', 'ano'],
            'date-range' => ['dtIni', 'dtFim'],
            'preset'     => ['preset', 'dtIni', 'dtFim'],
            default      => [],
        };
    }

    /** Chips para active filters (chips renderer). */
    public function activeFiltersSummary(array $fields): array
    {
        $out = [];
        foreach ($fields as $f) {
            if (!$this->isFilterActive($f)) continue;
            if (($f['type'] ?? '') === 'period-monthyear') {
                $opts = $this->getOpcoesMes();
                $m = $this->mes !== '' && isset($opts[$this->mes]) ? $opts[$this->mes] : '';
                $a = $this->ano !== '' ? $this->ano : '';
                $out[] = [
                    'label'      => $f['attrs']['label'] ?? mad_t('mad.dashf.period'),
                    'value'      => trim($m . ' ' . $a) ?: mad_t('mad.dashf.all'),
                    'clear'      => null,
                    'clear_pair' => true,
                ];
                continue;
            }
            $name = $f['attrs']['name'] ?? '';
            $out[] = [
                'label' => $f['attrs']['label'] ?? ucfirst($name),
                'value' => $this->resolveFilterLabel($f),
                'clear' => $name,
            ];
        }
        return $out;
    }

    // ───────────────────────────────────────────────────────────────────
    // DRILL-DOWN
    // ───────────────────────────────────────────────────────────────────

    /** Filtros atuais como array key=>value (so period keys do periodType ativo). */
    public function currentFilters(): array
    {
        $f = [];
        foreach ($this->_activePeriodKeys() as $k) {
            if ($this->$k !== '') $f[$k] = $this->$k;
        }
        foreach ($this->discoverFilterProps() as $name) {
            $val = $this->$name;
            if ($val !== '' && $val !== null && $val !== []) $f[$name] = $val;
        }
        return $f;
    }

    /** URL pra outra tela preservando filtros. */
    public function drillTo(string $class, string $method = 'show', array $extra = []): string
    {
        // MadRoutes monta a friendly URL direto.
        return \Mad\Routing\MadRoutes::urlFor(
            $class,
            $method,
            array_merge($this->currentFilters(), $extra)
        );
    }

    /** Params pra <mad-btn navigate :params>. */
    public function drillToParams(array $extra = []): array
    {
        return array_merge($this->currentFilters(), $extra);
    }

    // ───────────────────────────────────────────────────────────────────
    // SESSION PERSISTENCE (independente da serializacao do MadComponent)
    // ───────────────────────────────────────────────────────────────────

    /** Key da session pra filtros desta classe. */
    protected function filterSessionKey(string $suffix = 'state'): string
    {
        return 'mad_filters_' . static::class . '_' . $suffix;
    }

    /** Legacy key — formato usado por MadDashboard antes do trait. */
    protected function _legacyFilterSessionKey(string $suffix = 'state'): string
    {
        return 'mad_dashboard_' . static::class . '_' . $suffix;
    }

    /** Snapshot dos valores de filtro (periodo + props publicas descobertas). */
    public function filterStateSnapshot(): array
    {
        $state = [];
        foreach (['mes', 'ano', 'dtIni', 'dtFim', 'preset'] as $k) {
            $state[$k] = $this->$k;
        }
        foreach ($this->discoverFilterProps() as $name) {
            $state[$name] = $this->$name;
        }
        return $state;
    }

    /** Aplica um snapshot salvo (inverso de filterStateSnapshot). */
    public function applyFilterStateSnapshot(array $saved): void
    {
        foreach (['mes', 'ano', 'dtIni', 'dtFim', 'preset'] as $k) {
            if (isset($saved[$k]) && is_scalar($saved[$k])) {
                $this->$k = (string) $saved[$k];
            }
        }
        foreach ($this->discoverFilterProps() as $name) {
            if (!array_key_exists($name, $saved)) continue;
            // Session pode ter snapshot de versao anterior da classe (prop que
            // mudou de tipo) — coercao evita TypeError; vazio AQUI restaura
            // (snapshot representa o estado completo).
            $val = $this->_coerceFilterValue($name, $saved[$name]);
            if ($val !== null) {
                $this->$name = $val;
            }
        }
    }

    public function saveFilterSession(): void
    {
        if (!($this->rememberFilters ?? false)) return;
        session([$this->filterSessionKey('state') => $this->filterStateSnapshot()]);
    }

    public function loadFilterSession(): void
    {
        if (!($this->rememberFilters ?? false)) return;
        $saved = session($this->filterSessionKey('state'));
        if (!is_array($saved) || empty($saved)) {
            // Back-compat: read legacy dashboard key if new key empty.
            $saved = session($this->_legacyFilterSessionKey('state'));
        }
        if (!is_array($saved)) return;
        $this->applyFilterStateSnapshot($saved);
    }

    // ───────────────────────────────────────────────────────────────────
    // OPCOES DE DROPDOWN
    // ───────────────────────────────────────────────────────────────────

    public function getOpcoesMes(): array
    {
        return MadTempo::getMeses();
    }

    public function getOpcoesAno(): array
    {
        return MadTempo::getAnos();
    }

    public function getOpcoesPreset(): array
    {
        return [
            ''             => mad_t('mad.dashf.preset_select'),
            'hoje'         => mad_t('mad.dashf.preset_today'),
            'ontem'        => mad_t('mad.dashf.preset_yesterday'),
            '7d'           => mad_t('mad.dashf.preset_7d'),
            '30d'          => mad_t('mad.dashf.preset_30d'),
            'mes'          => mad_t('mad.dashf.preset_month'),
            'mes_anterior' => mad_t('mad.dashf.preset_prev_month'),
            'trimestre'    => mad_t('mad.dashf.preset_quarter'),
            'ano'          => mad_t('mad.dashf.preset_year'),
            'ano_anterior' => mad_t('mad.dashf.preset_prev_year'),
        ];
    }

    // ───────────────────────────────────────────────────────────────────
    // GETTERS (acesso Blade)
    // ───────────────────────────────────────────────────────────────────

    public function getPeriodType(): string      { return $this->periodType ?? 'none'; }
    public function getDateField(): string       { return $this->dateField ?? ''; }
    public function getRememberFilters(): bool   { return (bool) ($this->rememberFilters ?? false); }
    public function getUsePresets(): bool        { return (bool) ($this->usePresets ?? false); }
}
