<?php
namespace Mad\Sheet;

use Mad\Database\QuerySource;
use Mad\Form\FieldListColumn;
use Mad\Form\MadNoResultsHelper;
use Mad\Form\ModelOptionsLoader;
use Mad\Http\MadStateCrypt;

/**
 * SheetColumn — VO de uma coluna do <mad-sheet>.
 *
 * Tipos suportados:
 *   text   — input livre (mask/maxlength/force-case)
 *   number — numérico (decimals/min/max/step)
 *   money  — monetário (prefix/suffix/decimals/min/max/seps/fill-direction/allow-negative)
 *   date   — datepicker (display-mask/database-mask/min/max)
 *   combo  — select com opções estáticas (options="1:Ativo,2:Inativo") OU de
 *            model (model/key/display/order-by/where — mesma superfície do
 *            <mad-dbcombo-field>; `source`/`source-label` são aliases legados),
 *            com busca server-side opcional (search/min-length → token
 *            MadDbSearchService) e cascade por linha (depends-on/depends-column)
 *
 * `compute` (fórmula {a} * {b}, gramática do FieldListColumn::compileFormula)
 * torna a coluna calculada: força readonly e o valor NUNCA é persistido —
 * persistência de agregado é papel do hook beforeSaveRow() do controller.
 *
 * Split cliente/servidor (espelha FieldListColumn::toArray): model/database/
 * key/display/order-by/where/depends-column/quickFields NUNCA vão crus pro
 * client — viajam só dentro de tokens MadStateCrypt (searchToken) e do payload
 * opaco do no-results.
 *
 * `total` aceita 'sum' | 'count' (linha de totais client-side).
 */
class SheetColumn
{
    public const TYPES = ['text', 'number', 'money', 'date', 'combo'];

    /** Cap de opções carregadas de model (payload embutido no render). */
    public const OPTIONS_CAP = 500;

    /**
     * Patterns de mask (espelho de _MAD_MASK_PATTERNS em mad-ui.js — os dois
     * PRECISAM contar a mesma história: o JS mascara na digitação e este valida
     * no servidor; divergir aqui reprova no backend o que a UI aceitou).
     *
     * Token `X` = alfanumérico, exigido pelo CNPJ da Receita a partir de
     * julho/2026 (12 posições em [0-9A-Z] + 2 dígitos verificadores).
     */
    private const MASK_PATTERNS = [
        'cpf'      => '999.999.999-99',
        'cnpj'     => 'XX.XXX.XXX/XXXX-99',
        'cep'      => '99999-999',
        'date'     => '99/99/9999',
        'time'     => '99:99',
        'datetime' => '99/99/9999 99:99',
        'placa'    => 'AAA-9999',
        'rg'       => '99.999.999-9',
    ];

    // ── Core ────────────────────────────────────────────────────────────
    public string $field       = '';
    public string $label       = '';
    public string $type        = 'text';
    public string $width       = '';
    public bool   $required    = false;
    public bool   $readonly    = false;
    public int    $decimals    = 2;
    public string $total       = '';
    public string $default     = '';
    public string $placeholder = '';

    /** Opções estáticas do combo: [value => label]. */
    public array $options = [];

    // ── Datasource do combo (superfície do mad-dbcombo-field) ───────────
    /** Model das opções. Short name ou FQCN (registry). */
    public string $model = '';
    /** Conexão das opções ('' = herda a do sheet). */
    public string $database = '';
    /** Coluna de valor (PK). */
    public string $keyField = 'id';
    /** Coluna de label OU mask "{nome} - {sigla}". */
    public string $display = 'nome';
    /** Coluna de ordenação ('' = display quando coluna simples). */
    public string $orderBy = '';
    public string $order = 'asc';
    /** Filtro estático DSL field-list: 'ativo=1|tipo=P|preco:>:0'. */
    public string $where = '';
    /** Filtro rico (:where="$filter_x" do builder) — Closure fn($q) => ... */
    public ?\Closure $whereClosure = null;

    // ── Busca server-side / cascade ─────────────────────────────────────
    public bool $search = false;
    public int  $minLength = 3;
    /** Campo irmão (coluna do sheet) cujo valor filtra este combo. */
    public string $dependsOn = '';
    /** Coluna do banco usada no WHERE do cascade (default = dependsOn). */
    public string $dependsColumn = '';

    // ── No results (create / quick register) ────────────────────────────
    public string $noResultsCreateAction = '';
    public string $noResultsCreateLabel  = '';
    public string $noResultsCreateIcon   = '';
    public string $noResultsCreateClass  = '';
    public string $noResultsQuickRegisterAction = '';
    public string $noResultsQuickRegisterLabel  = '';
    public string $noResultsQuickRegisterIcon   = '';
    public string $noResultsQuickRegisterClass  = '';
    public string $noResultsMessage = '';
    /** Field-defs do <mad-quick-form> (capturados pelo MadSheetCompiler). */
    public array $noResultsQuickFields = [];

    // ── Compute ─────────────────────────────────────────────────────────
    /** Fórmula "{quantidade} * {valor}" — força readonly, nunca persiste. */
    public string $compute = '';

    // ── Formatação numérica/monetária ───────────────────────────────────
    public ?string $min = null;
    public ?string $max = null;
    public ?float  $step = null;
    public string  $prefix = '';
    public string  $suffix = '';
    public string  $decimalSep = ',';
    public string  $thousandSep = '.';
    public string  $fillDirection = 'right';
    public bool    $allowNegative = false;

    // ── Texto ───────────────────────────────────────────────────────────
    /** Alias (cpf/cnpj/cpfcnpj/cep/phone/date/time/datetime/placa/rg) ou pattern 9/A/*. */
    public string $mask = '';
    public bool   $stripMask = false;
    public ?int   $maxlength = null;
    /** upper | lower | title. */
    public string $forceCase = '';

    // ── Data ────────────────────────────────────────────────────────────
    public string $displayMask  = 'dd/mm/yyyy';
    public string $databaseMask = 'yyyy-mm-dd';

    // ── Aliases legados (mantidos como espelhos de model/display) ───────
    public string $source = '';
    public string $sourceLabel = 'nome';

    /**
     * Constrói a partir do config compilado do <mad-sheet-col>.
     * Campos desconhecidos são ignorados.
     */
    public static function fromConfig(array $cfg): self
    {
        $col = new self();

        $col->field       = (string) ($cfg['field'] ?? '');
        $col->label       = (string) ($cfg['label'] ?? $col->field);
        // Normaliza na ENTRADA: o width viaja no JSON de config e o Alpine
        // concatena direto no style (`<col :style="'width:' + col.width">`), então
        // '140' sem unidade virava declaração inválida e a coluna ia pra largura
        // automática. Normalizar aqui conserta o lado JS sem tocar no template.
        $col->width       = \Mad\Support\CssUnits::length((string) ($cfg['width'] ?? ''));
        $col->required    = !empty($cfg['required']);
        $col->readonly    = !empty($cfg['readonly']);
        $col->decimals    = isset($cfg['decimals']) ? max(0, (int) $cfg['decimals']) : 2;
        $col->default     = (string) ($cfg['default'] ?? '');
        $col->placeholder = (string) ($cfg['placeholder'] ?? '');

        $type = strtolower((string) ($cfg['type'] ?? 'text'));
        // dbcombo é alias de combo (familiaridade com os fields de form)
        if ($type === 'dbcombo') {
            $type = 'combo';
        }
        $col->type = in_array($type, self::TYPES, true) ? $type : 'text';

        $totalMode  = strtolower((string) ($cfg['total'] ?? ''));
        $col->total = in_array($totalMode, ['sum', 'count'], true) ? $totalMode : '';

        if (!empty($cfg['options']) && is_string($cfg['options'])) {
            $col->options = self::parseStaticOptions($cfg['options']);
        }

        // Datasource: nomes canônicos do dbcombo; source/source-label = legado.
        $col->model    = (string) ($cfg['model'] ?? $cfg['source'] ?? '');
        $col->database = (string) ($cfg['database'] ?? '');
        $col->keyField = (string) ($cfg['key'] ?? 'id');
        $col->display  = (string) ($cfg['display'] ?? $cfg['sourceLabel'] ?? 'nome');
        $col->orderBy  = (string) ($cfg['orderBy'] ?? '');
        $order         = strtolower((string) ($cfg['order'] ?? 'asc'));
        $col->order    = in_array($order, ['asc', 'desc'], true) ? $order : 'asc';
        // Display: mask "{...}" OU identificador simples (fallback 'nome').
        if (strpos($col->display, '{') === false
            && !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $col->display)) {
            $col->display = 'nome';
        }
        // Espelhos legados (leitores antigos).
        $col->source      = $col->model;
        $col->sourceLabel = $col->display;

        // where: string DSL OU Closure (:where="$filter_x") — sem string-cast.
        $w = $cfg['where'] ?? null;
        if ($w instanceof \Closure) {
            $col->whereClosure = $w;
        } elseif (is_string($w)) {
            $col->where = $w;
        }

        // Busca server-side + cascade (depends-on força search; fetch-on-open).
        $col->search        = !empty($cfg['search']);
        $col->minLength     = isset($cfg['minLength']) ? max(0, (int) $cfg['minLength']) : 3;
        $col->dependsOn     = (string) ($cfg['dependsOn'] ?? '');
        $col->dependsColumn = (string) ($cfg['dependsColumn'] ?? '');
        if ($col->dependsOn !== '') {
            $col->search = true;
            if (!isset($cfg['minLength'])) {
                $col->minLength = 0;
            }
        }

        // No results.
        $col->noResultsCreateAction        = (string) ($cfg['noResultsCreateAction'] ?? '');
        $col->noResultsCreateLabel         = (string) ($cfg['noResultsCreateLabel'] ?? '');
        $col->noResultsCreateIcon          = (string) ($cfg['noResultsCreateIcon'] ?? '');
        $col->noResultsCreateClass         = (string) ($cfg['noResultsCreateClass'] ?? '');
        $col->noResultsQuickRegisterAction = (string) ($cfg['noResultsQuickRegisterAction'] ?? '');
        $col->noResultsQuickRegisterLabel  = (string) ($cfg['noResultsQuickRegisterLabel'] ?? '');
        $col->noResultsQuickRegisterIcon   = (string) ($cfg['noResultsQuickRegisterIcon'] ?? '');
        $col->noResultsQuickRegisterClass  = (string) ($cfg['noResultsQuickRegisterClass'] ?? '');
        $col->noResultsMessage             = (string) ($cfg['noResultsMessage'] ?? '');
        if (isset($cfg['noResultsQuickFields']) && is_array($cfg['noResultsQuickFields'])) {
            $col->noResultsQuickFields = $cfg['noResultsQuickFields'];
        }

        // Compute: força readonly (valor display-only, nunca persiste).
        $col->compute = (string) ($cfg['compute'] ?? '');
        if ($col->compute !== '') {
            $col->readonly = true;
        }

        // Numérico/monetário.
        $col->min  = isset($cfg['min'])  ? (string) $cfg['min']  : null;
        $col->max  = isset($cfg['max'])  ? (string) $cfg['max']  : null;
        $col->step = isset($cfg['step']) && is_numeric($cfg['step']) ? (float) $cfg['step'] : null;
        $col->prefix = (string) ($cfg['prefix'] ?? '');
        $col->suffix = (string) ($cfg['suffix'] ?? '');
        // Separadores: 1 char (thousand-sep="" permitido = sem milhar).
        if (isset($cfg['decimalSep']) && $cfg['decimalSep'] !== '') {
            $col->decimalSep = mb_substr((string) $cfg['decimalSep'], 0, 1);
        }
        if (isset($cfg['thousandSep'])) {
            $col->thousandSep = mb_substr((string) $cfg['thousandSep'], 0, 1);
        }
        $fill = strtolower((string) ($cfg['fillDirection'] ?? 'right'));
        $col->fillDirection = in_array($fill, ['right', 'left'], true) ? $fill : 'right';
        $col->allowNegative = !empty($cfg['allowNegative']);

        // Texto.
        $col->mask      = (string) ($cfg['mask'] ?? '');
        $col->stripMask = !empty($cfg['stripMask']);
        $col->maxlength = isset($cfg['maxlength']) ? max(1, (int) $cfg['maxlength']) : null;
        $case           = strtolower((string) ($cfg['forceCase'] ?? ''));
        $col->forceCase = in_array($case, ['upper', 'lower', 'title'], true) ? $case : '';

        // Data.
        if (!empty($cfg['displayMask'])) {
            $col->displayMask = (string) $cfg['displayMask'];
        }
        if (!empty($cfg['databaseMask'])) {
            $col->databaseMask = (string) $cfg['databaseMask'];
        }

        return $col;
    }

    /** Parse de options estáticas: "1:Ativo,2:Inativo" → [1 => 'Ativo', ...]. */
    public static function parseStaticOptions(string $raw): array
    {
        $out = [];
        foreach (explode(',', $raw) as $pair) {
            $pair = trim($pair);
            if ($pair === '') {
                continue;
            }
            [$value, $label] = array_pad(explode(':', $pair, 2), 2, null);
            $value = trim((string) $value);
            $label = $label === null ? $value : trim($label);
            if ($value !== '') {
                $out[$value] = $label;
            }
        }
        return $out;
    }

    /** Aplica o filtro configurado (Closure do builder vence a string DSL). */
    public function applyWhere($query): void
    {
        if ($this->whereClosure !== null) {
            ($this->whereClosure)($query);
            return;
        }
        if ($this->where !== '') {
            FieldListColumn::applyWhereStringToQuery($query, $this->where);
        }
    }

    /**
     * Resolve opções do combo (modo baked). Estáticas vencem; senão carrega
     * do model honrando where/display(mask)/order-by, com cap OPTIONS_CAP.
     * Em modo search as opções NÃO são embutidas (vêm do MadDbSearchService).
     */
    public function resolveOptions(string $sheetDatabase): array
    {
        if (!empty($this->options)) {
            return $this->options;
        }
        if ($this->type !== 'combo' || $this->model === '' || $this->search) {
            return [];
        }

        $modelClass = ModelOptionsLoader::resolveModelClass($this->model);
        $query = $modelClass::query();
        $this->applyWhere($query);
        $query->limit(self::OPTIONS_CAP);

        $orderBy = $this->orderBy !== ''
            ? $this->orderBy
            : (strpos($this->display, '{') === false ? $this->display : null);

        return ModelOptionsLoader::itemsFromQuery($query, $this->keyField, $this->display, $orderBy);
    }

    /**
     * Token criptografado do modo busca server-side (MadDbSearchService).
     * Receita do dbunique-search-field: builder do model + where → compileSql;
     * a config da query NUNCA viaja crua pro client.
     */
    public function buildSearchToken(string $sheetDatabase): string
    {
        if ($this->type !== 'combo' || !$this->search || $this->model === '') {
            return '';
        }

        $querySql = '';
        $queryBindings = [];
        try {
            $modelClass = ModelOptionsLoader::resolveModelClass($this->model);
            $qb = $modelClass::query();
            $this->applyWhere($qb);
            [$querySql, $queryBindings] = QuerySource::compileSql($qb);
        } catch (\Throwable $e) {
            return '';
        }

        return MadStateCrypt::encrypt([
            'database'       => $this->database !== '' ? $this->database : $sheetDatabase,
            'model'          => $this->model,
            'key'            => $this->keyField,
            'display'        => $this->display,
            'order'          => $this->orderBy,
            'column'         => $this->dependsColumn !== '' ? $this->dependsColumn : $this->dependsOn,
            'query_sql'      => $querySql,
            'query_bindings' => $queryBindings,
            'limit'          => self::OPTIONS_CAP,
        ]);
    }

    /** Payload do no-results (create / quick register) — opaco pro client. */
    public function buildNoResultsPayload(string $sheetDatabase): ?string
    {
        if ($this->type !== 'combo') {
            return null;
        }

        return MadNoResultsHelper::buildPayload([
            'name'         => $this->field,
            'model'        => $this->model,
            'database'     => $this->database !== '' ? $this->database : $sheetDatabase,
            'key'          => $this->keyField,
            'display'      => $this->display,
            'message'      => $this->noResultsMessage,
            'createAction' => $this->noResultsCreateAction,
            'createLabel'  => $this->noResultsCreateLabel !== '' ? $this->noResultsCreateLabel : 'Cadastrar novo',
            'createIcon'   => $this->noResultsCreateIcon !== '' ? $this->noResultsCreateIcon : 'plus',
            'createClass'  => $this->noResultsCreateClass !== '' ? $this->noResultsCreateClass : 'mad-btn mad-btn-primary mad-btn-sm',
            'quickAction'  => $this->noResultsQuickRegisterAction,
            'quickLabel'   => $this->noResultsQuickRegisterLabel !== '' ? $this->noResultsQuickRegisterLabel : 'Adicionar',
            'quickIcon'    => $this->noResultsQuickRegisterIcon !== '' ? $this->noResultsQuickRegisterIcon : 'check',
            'quickClass'   => $this->noResultsQuickRegisterClass !== '' ? $this->noResultsQuickRegisterClass : 'mad-btn mad-btn-success mad-btn-sm',
            'quickFields'  => $this->noResultsQuickFields,
        ]);
    }

    /** Fórmula compute compilada pra JS (x-effect) — '' quando não é calculada. */
    public function computeJs(): string
    {
        return $this->compute !== '' ? FieldListColumn::compileFormula($this->compute) : '';
    }

    // ── Validação server-side (mask/limites) ────────────────────────────

    /** Converte máscara de data (dd/mm/yyyy) pro formato PHP date(). */
    public static function maskToPhp(string $mask): string
    {
        return strtr($mask, [
            'yyyy' => 'Y', 'yy' => 'y',
            'mm' => 'm', 'MM' => 'i',
            'dd' => 'd',
            'hh' => 'H', 'HH' => 'H',
            'ii' => 'i',
            'ss' => 's', 'SS' => 's',
        ]);
    }

    /** Limite min/max numérico normalizado ('' / não-numérico → null). */
    public function numericBoundary(string $which): ?float
    {
        $raw = $which === 'min' ? $this->min : $this->max;
        return ($raw !== null && $raw !== '' && is_numeric($raw)) ? (float) $raw : null;
    }

    /** Limite min/max de data (parse na database-mask; fallback strtotime). */
    public function dateBoundary(string $which): ?\DateTimeInterface
    {
        $raw = $which === 'min' ? $this->min : $this->max;
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        // '!' zera hora/min/seg não presentes na mask (senão herdam o "agora"
        // e quebram comparação de datas puras).
        $dt = \DateTime::createFromFormat('!' . self::maskToPhp($this->databaseMask), trim($raw));
        if ($dt) {
            return $dt;
        }
        $ts = strtotime(trim($raw));
        return $ts ? (new \DateTime())->setTimestamp($ts) : null;
    }

    /** Normalização de caixa (espelho de _madApplyForceCase). */
    public function applyForceCase(string $v): string
    {
        return match ($this->forceCase) {
            'upper' => mb_strtoupper($v, 'UTF-8'),
            'lower' => mb_strtolower($v, 'UTF-8'),
            'title' => mb_convert_case(mb_strtolower($v, 'UTF-8'), MB_CASE_TITLE, 'UTF-8'),
            default => $v,
        };
    }

    /** Resolve alias de mask pro pattern concreto (espelho do JS). */
    public function maskPattern(string $value): string
    {
        if ($this->mask === '') {
            return '';
        }
        $key = strtolower(trim($this->mask));
        $digits = preg_replace('/\D/', '', $value);

        if ($key === 'cpfcnpj') {
            // Decide por caracteres ALFANUMÉRICOS (espelho do JS): num CNPJ
            // alfanumérico a contagem de dígitos fica abaixo de 11 e isto
            // escolhia a máscara de CPF, reprovando o documento no shape.
            // Qualquer letra presente já descarta CPF, que é sempre numérico.
            $alnum = \Mad\Support\MadCnpj::sanitize($value);
            if (strlen($alnum) !== strlen((string) $digits)) {
                return self::MASK_PATTERNS['cnpj'];
            }
            return strlen($alnum) <= 11 ? self::MASK_PATTERNS['cpf'] : self::MASK_PATTERNS['cnpj'];
        }
        if (in_array($key, ['phone', 'tel', 'telefone', 'celular'], true)) {
            return strlen($digits) <= 10 ? '(99) 9999-9999' : '(99) 99999-9999';
        }

        return self::MASK_PATTERNS[$key] ?? $this->mask;
    }

    /** Aplica pattern 9/A/* a um valor cru (espelho de _madApplyMaskPattern). */
    public static function applyMaskPattern(string $value, string $pattern): string
    {
        $clean = preg_replace('/[^0-9A-Za-z]/', '', $value);
        $out = '';
        $ci = 0;
        $len = strlen($pattern);
        $cleanLen = strlen($clean);
        for ($i = 0; $i < $len; $i++) {
            if ($ci >= $cleanLen) {
                break;
            }
            $p = $pattern[$i];
            $c = $clean[$ci];
            if ($p === '9') {
                if (ctype_digit($c)) { $out .= $c; $ci++; }
                else { $ci++; $i--; }
            } elseif ($p === 'A') {
                if (ctype_alpha($c)) { $out .= strtoupper($c); $ci++; }
                else { $ci++; $i--; }
            } elseif ($p === 'X') {
                // Alfanumérico — existe pelo CNPJ alfanumérico (julho/2026):
                // 'A' recusaria os dígitos e '9' recusaria as letras.
                if (ctype_alnum($c)) { $out .= strtoupper($c); $ci++; }
                else { $ci++; $i--; }
            } elseif ($p === '*') {
                $out .= $c;
                $ci++;
            } else {
                $out .= $p;
            }
        }
        return $out;
    }

    /**
     * Valida o valor contra a mask configurada: shape completo do pattern +
     * dígitos verificadores pra cpf/cnpj/cpfcnpj.
     */
    public function matchesMask(string $value): bool
    {
        if ($this->mask === '') {
            return true;
        }
        $pattern = $this->maskPattern($value);
        if ($pattern === '') {
            return true;
        }

        // Shape: re-mascarar o valor deve reproduzi-lo E preencher o pattern.
        $masked = self::applyMaskPattern($value, $pattern);
        $expectedLen = strlen($pattern);
        if ($masked !== $value || strlen($masked) !== $expectedLen) {
            return false;
        }

        $key = strtolower(trim($this->mask));
        $digits = preg_replace('/\D/', '', $value);
        // CNPJ conta caracteres ALFANUMÉRICOS, não dígitos: no formato novo
        // (julho/2026) as 12 primeiras posições podem ter letras, e contar só
        // dígitos faria um CNPJ válido cair no ramo do CPF (ou em nenhum).
        $alnum = \Mad\Support\MadCnpj::sanitize($value);

        if ($key === 'cpf' || ($key === 'cpfcnpj' && strlen($digits) === 11 && strlen($alnum) === 11)) {
            return self::cpfCheckDigitsOk($digits);
        }
        if ($key === 'cnpj' || ($key === 'cpfcnpj' && strlen($alnum) === 14)) {
            return self::cnpjCheckDigitsOk($alnum);
        }

        return true;
    }

    /** Dígitos verificadores de CPF (mod 11). */
    public static function cpfCheckDigitsOk(string $digits): bool
    {
        if (strlen($digits) !== 11 || preg_match('/^(\d)\1{10}$/', $digits)) {
            return false;
        }
        for ($t = 9; $t < 11; $t++) {
            $sum = 0;
            for ($i = 0; $i < $t; $i++) {
                $sum += (int) $digits[$i] * (($t + 1) - $i);
            }
            $check = ((10 * $sum) % 11) % 10;
            if ((int) $digits[$t] !== $check) {
                return false;
            }
        }
        return true;
    }

    /**
     * Dígitos verificadores de CNPJ (mod 11), numérico OU alfanumérico.
     *
     * Delega ao Mad\Support\MadCnpj — a implementação anterior fazia
     * `(int) $digits[$i]`, que converte letra em 0, então todo CNPJ
     * alfanumérico (Receita, julho/2026) era reprovado.
     */
    public static function cnpjCheckDigitsOk(string $digits): bool
    {
        return \Mad\Support\MadCnpj::isValid($digits);
    }

    /**
     * Config enviada pro Alpine (madSheet). Options entram já resolvidas;
     * searchToken/noResultsPayload são opacos (MadStateCrypt/base64). Chaves
     * do datasource (model/database/key/display/order-by/where/depends-column/
     * quickFields) NUNCA são embutidas cruas.
     */
    public function toClientConfig(array $resolvedOptions, string $searchToken = '', ?string $noResultsPayload = null): array
    {
        return [
            'field'       => $this->field,
            'label'       => $this->label,
            'type'        => $this->type,
            'width'       => $this->width,
            'required'    => $this->required,
            'readonly'    => $this->readonly,
            'decimals'    => $this->decimals,
            'total'       => $this->total,
            'default'     => $this->default,
            'placeholder' => $this->placeholder,
            'options'     => (object) $resolvedOptions,
            // Numérico/monetário
            'min'           => $this->type === 'date' ? $this->min : $this->numericBoundary('min'),
            'max'           => $this->type === 'date' ? $this->max : $this->numericBoundary('max'),
            'step'          => $this->step,
            'prefix'        => $this->prefix,
            'suffix'        => $this->suffix,
            'decimalSep'    => $this->decimalSep,
            'thousandSep'   => $this->thousandSep,
            'fillDirection' => $this->fillDirection,
            'allowNegative' => $this->allowNegative,
            // Texto
            'mask'      => $this->mask,
            'maxlength' => $this->maxlength,
            'forceCase' => $this->forceCase,
            // Data
            'displayMask'  => $this->displayMask,
            'databaseMask' => $this->databaseMask,
            // Compute (JS compilado; fonte da fórmula fica no servidor)
            'computeJs' => $this->computeJs(),
            // Cascade (só o NOME do campo irmão; a config viaja no token)
            'dependsOn' => $this->dependsOn,
            // Busca server-side
            'searchToken'  => $searchToken,
            'searchMinLen' => $this->minLength,
            // No results (payload opaco)
            'noResultsPayload' => $noResultsPayload,
        ];
    }
}
