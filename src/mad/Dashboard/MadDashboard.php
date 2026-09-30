<?php
namespace Mad\Dashboard;

use Mad\Component\MadComponent;
use Mad\Filters\MadFilterable;
use Mad\Filters\MadFiltersTrait;

/**
 * MadDashboard — Base class para dashboards reativos (port COMPLETO do
 * legado lib/mad/dashboard/MadDashboard.php, adaptado pro stack Laravel:
 * Eloquent/Query Builder, TSession → session(), transacao nativa do Laravel).
 *
 * Features (via Mad\Filters\MadFiltersTrait):
 *   - Filtro de periodo (month-year | date-range | preset | none)
 *   - Period presets (hoje, ontem, 7d, 30d, mes, trimestre, ano, etc)
 *   - Extra filters auto-detectados (public string/array props) + auto-apply
 *   - Drill-down helper preservando filtros atuais
 *   - Session persistence via $rememberFilters
 *   - Multi-tenant unit filter opt-in
 *
 * Dashboard-only (mantido nesta classe):
 *   - Comparacao automatica vs periodo anterior (computeDelta, comparePeriodQuery)
 *   - Drill-down via chart label (applyFilterFromLabel, applyFilterDirect)
 *   - Widget toggle (show/hide via session)
 *   - Auto-refresh com intervalo configuravel
 *
 * ┌─ Uso minimo ────────────────────────────────────────────────────────────┐
 * │  class DashboardNegociacao extends MadDashboard                         │
 * │  {                                                                      │
 * │      protected bool $defaultToCurrentPeriod = true;                     │
 * │                                                                         │
 * │      public function queryEmNegociacao(): Builder                       │
 * │      {                                                                  │
 * │          return Negociacao::query()                                     │
 * │              ->whereIn('estado_id', [1, 2, 3]);                         │
 * │      }                                                                  │
 * │                                                                         │
 * │      protected function view(): string|array                            │
 * │      {                                                                  │
 * │          return 'crm.dashboard-negociacao';                             │
 * │      }                                                                  │
 * │  }                                                                      │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * Filtros custom (op diferente, coluna alias): declare a prop em
 * $skipAutoFilter e implemente o hook 2-arg
 * `onSearch(Builder $q, ?string $model = null)` — o applyAutoFilters() do
 * trait detecta a assinatura 2-arg e chama (assinatura 0-arg e do DataGrid).
 */
abstract class MadDashboard extends MadComponent implements MadFilterable
{
    use MadFiltersTrait;

    /** Wrapper INTERNAL — dashboards sao paginas completas. */
    protected static string $wrapper = self::INTERNAL;

    // ───────────────────────────────────────────────────────────────────
    // CONFIGURACAO (override por subclasse) — props referenciadas pela trait
    // ───────────────────────────────────────────────────────────────────

    /** Conexao do banco. */
    protected string $database = 'business';

    /**
     * Colunas denormalizadas pra mes/ano. Override pra schema diferente.
     * Setar chave como '' desabilita o filtro daquela dimensao.
     */
    protected array $periodFields = ['mes' => 'mes', 'ano' => 'ano'];

    /**
     * Tipo do filtro de periodo:
     *   - 'month-year' (default): mes + ano via colunas denormalizadas
     *   - 'date-range': dtIni + dtFim via $dateField
     *   - 'preset':     dropdown de presets (hoje/semana/mes/etc) via $dateField
     *   - 'none':       sem filtro de periodo
     */
    protected string $periodType = 'month-year';

    /** Campo de data usado em modos date-range e preset. */
    protected string $dateField = '';

    /** Mes/ano atuais como default no mount(). */
    protected bool $defaultToCurrentPeriod = false;

    /** Habilita dropdown de presets dentro do period-filter blade. */
    protected bool $usePresets = false;

    /** Persiste filtros entre requests via session. */
    protected bool $rememberFilters = false;

    /** Aplica filtro automatico por unit (multi-tenant). */
    protected bool $applyUnitFilter = false;

    /** Nome da coluna de unit no model alvo. */
    protected string $unitField = 'unit_id';

    /** Auto-refresh em segundos. 0 = desligado. Minimo 5. */
    protected int $autoRefreshSec = 0;

    /**
     * Cache do payload da view POR COMBINAÇÃO DE FILTROS (write-through).
     * 0 = off. Para ligar, a subclasse seta o TTL e implementa
     * {@see cacheableViewData()}; o view() consome via {@see viewData()}:
     *
     *   protected int $cacheTtl = 600;
     *   protected function cacheableViewData(): array { return [...arrays...]; }
     *   protected function view(): string|array {
     *       return ['tpl', $this->viewData() + ['serie' => $builder]];
     *   }
     *
     * Builders/closures NÃO entram em cacheableViewData() (não serializam) —
     * ficam no view() e são cobertos pelo Mad\Database\QueryCache do engine.
     * Multi-unidade / tenant em pool: a chave já inclui tenant e unidade
     * ({@see viewCacheKey()}). Escopo de domínio próprio (franquia, carteira
     * do usuário…) ainda vai em {@see cacheKeyExtra()}.
     *
     * BÔNUS (fallback por página): se o global `mad.chart.cache_ttl` está
     * desligado, este TTL também é aplicado ao QueryCache dos charts
     * `:query` DESTA página durante o render — ver
     * {@see applyPageChartCacheTtl()}. Global ligado sempre vence.
     */
    protected int $cacheTtl = 0;

    /** Override per-model do unitField. */
    protected array $unitFields = [];

    /** Props excluidas da auto-discovery — subclasse implementa em onSearch(). */
    protected array $skipAutoFilter = [];

    // ───────────────────────────────────────────────────────────────────
    // LIFECYCLE
    // ───────────────────────────────────────────────────────────────────

    public function mount(array $params = []): void
    {
        $this->_initFiltersForm();

        // 1. Defaults (mes/ano, e dtIni/dtFim = dia 1..hoje em date-range)
        $this->_seedDefaultPeriod();

        // 2. Restore from session (if rememberFilters)
        $this->loadFilterSession();

        // 3. Override from request ($_GET/$_POST)
        $req = array_merge($_GET ?? [], $_POST ?? []);
        $this->hydrateFiltersFromArray($req);

        // 4. Override from params (highest priority — drill-down etc)
        $this->hydrateFiltersFromArray($params);

        $this->syncFormFields();
        $this->onMount($params);
    }

    /** Hook pra subclasse adicionar state custom. */
    protected function onMount(array $params): void
    {
    }

    /**
     * MadComponent hook — espelha mudancas em form->fields automaticamente.
     */
    public function updated(string $prop, mixed $value): void
    {
        parent::updated($prop, $value);
        $this->_filtersUpdatedHook($prop, $value);
    }

    // ───────────────────────────────────────────────────────────────────
    // BACK-COMPAT ALIASES (subclasses antigas chamavam estes nomes)
    // ───────────────────────────────────────────────────────────────────

    /** @deprecated use filterSessionKey() — mantido como alias. */
    protected function sessionKey(string $suffix = 'state'): string
    {
        // Alias aponta pra chave NOVA: quem escreve por aqui nao pode ser
        // sombreado pela chave mad_filters_* (a legada e so fallback de leitura).
        return $this->filterSessionKey($suffix);
    }

    /** @deprecated use saveFilterSession() */
    protected function saveSession(): void { $this->saveFilterSession(); }

    /** @deprecated use loadFilterSession() */
    protected function loadSession(): void { $this->loadFilterSession(); }

    // ───────────────────────────────────────────────────────────────────
    // DASHBOARD-ONLY: COMPARE PERIOD / DELTA
    // ───────────────────────────────────────────────────────────────────


    /**
     * Alias publico chamado pelo Blade gerado pelo editor
     * (`:compare-query="$that->compareQuery('Model', [...])"`) — espelha
     * `baseQuery()` (que vive no MadFiltersTrait) do lado da comparacao.
     *
     * Repassa os opts INTEIROS (only/exclude/unit/compare) — 'compare' e' o
     * modo de janela ('auto' | 'mtd'), ver comparePeriodQuery().
     */
    public function compareQuery(string $model, array $opts = []): \Illuminate\Database\Eloquent\Builder
    {
        return $this->comparePeriodQuery($model, $opts);
    }

    /**
     * Explicit per-model date range, replacing the month/year period only.
     * Runs through baseQuery(), so the page's own "Regras base" override (and
     * every Eloquent scope, unit and automatic filter behind it) still applies —
     * only the month/year period is dropped ('period' => false). Add custom
     * widget/status predicates to the returned Builder. Both empty endpoints
     * clear the temporal constraint; partial/invalid input fails.
     * $dateField accepts 'coluna' or 'tabela.coluna'.
     * opts['compare'] = true selects the immediately preceding equal-length
     * range and REQUIRES both endpoints (an empty range would silently repeat
     * the current window and report a 0% delta).
     */
    public function dateRangeQuery(string $model, string $dateField, ?string $start, ?string $end, array $opts = []): \Illuminate\Database\Eloquent\Builder
    {
        $range = \Mad\Filters\MadDateRange::fromInput($start, $end);
        if (!preg_match('/^[A-Za-z_]\w*(?:\.[A-Za-z_]\w*)?$/D', $dateField) || !$this->modelHasColumn($model, $dateField)) {
            throw new \InvalidArgumentException('O campo de data informado não existe no model do indicador.');
        }
        if (array_key_exists('compare', $opts) && !is_bool($opts['compare'])) throw new \InvalidArgumentException('compare deve ser booleano.');
        if ($opts['compare'] ?? false) {
            if ($range === null) throw new \InvalidArgumentException('compare exige as duas datas do intervalo.');
            $range = $range->previous();
        }
        unset($opts['compare']);
        $query = $this->baseQuery($model, ['period' => false] + $opts);
        if ($range !== null) {
            [$lower, $upper] = $range->bounds();
            $query->where($dateField, '>=', $lower)->where($dateField, '<', $upper);
        }
        return $query;
    }

    /**
     * Janela anterior (periodo de comparacao) como Eloquent Builder pronto —
     * o card usa :compare-query.
     *
     * $opts['compare'] escolhe a janela:
     *   'auto' (default) — janela anterior conforme $periodType (mes anterior
     *                      no month-year, janela de mesmo tamanho no
     *                      date-range, unidade de calendario cheia no preset).
     *   'mtd'            — MESMO trecho do mes anterior, alinhado por dia
     *                      (01..07/09 vs 01..07/08). EXIGE $dateField: sem
     *                      campo de data (month-year puro com colunas
     *                      mes/ano inteiras) nao ha o que alinhar por dia e o
     *                      modo cai em 'auto'.
     */
    public function comparePeriodQuery(string $model, array $opts = []): \Illuminate\Database\Eloquent\Builder
    {
        // Puro-builder: monta a janela anterior direto no Eloquent Builder.
        $class = \Mad\Form\ModelOptionsLoader::resolveModelClass($model);
        $q     = $class::query();
        $mode  = (string) ($opts['compare'] ?? 'auto');

        // MTD ignora o branch por periodType: a janela ja vem em datas.
        // O guard 'mode' e' obrigatorio — previousPeriod('mtd') delega pro
        // 'auto' quando nao ha' janela de datas (month-year so' com mes), e o
        // retorno mes/ano nao tem dtIni/dtFim: sem o guard a comparacao sairia
        // SEM filtro de periodo (tabela inteira), calada.
        if ($mode === 'mtd' && $this->dateField !== ''
            && $this->modelHasColumn($model, $this->dateField)) {
            $prev = $this->previousPeriod('mtd');
            if (($prev['mode'] ?? '') === 'mtd') {
                if (!empty($prev['dtIni'])) $q->where($this->dateField, '>=', $prev['dtIni']);
                if (!empty($prev['dtFim'])) $q->where($this->dateField, '<=', $prev['dtFim'] . ' 23:59:59');

                if (($opts['unit'] ?? true) !== false) {
                    $this->applyUnitToQuery($q, $model);
                }
                $this->applyAutoFiltersToQuery($q, $model, $opts);
                return $q;
            }
        }

        $prev = $this->previousPeriod();

        if ($this->periodType === 'month-year') {
            $mesCol = $this->periodColumnFor('mes', $model);
            $anoCol = $this->periodColumnFor('ano', $model);
            if (!empty($prev['mes']) && $mesCol && $this->modelHasColumn($model, $mesCol)) {
                $q->where($mesCol, '=', $prev['mes']);
            }
            if (!empty($prev['ano']) && $anoCol && $this->modelHasColumn($model, $anoCol)) {
                $q->where($anoCol, '=', $prev['ano']);
            }
        } elseif ($this->periodType === 'date-range' && $this->dateField !== ''
                  && $this->modelHasColumn($model, $this->dateField)) {
            if (!empty($prev['dtIni'])) $q->where($this->dateField, '>=', $prev['dtIni']);
            if (!empty($prev['dtFim'])) $q->where($this->dateField, '<=', $prev['dtFim'] . ' 23:59:59');
        } elseif ($this->periodType === 'preset' && $this->dateField !== ''
                  && $this->modelHasColumn($model, $this->dateField)) {
            if (!empty($prev['start'])) {
                $start = (string) $prev['start'];
                if (str_ends_with($start, ' 00:00:00')) $start = substr($start, 0, 10);
                $q->where($this->dateField, '>=', $start);
            }
            if (!empty($prev['end'])) $q->where($this->dateField, '<=', $prev['end']);
        }

        if (($opts['unit'] ?? true) !== false) {
            $this->applyUnitToQuery($q, $model);
        }
        $this->applyAutoFiltersToQuery($q, $model, $opts);
        return $q;
    }

    /**
     * Desloca uma janela [start, end] UM MES pra tras mantendo o dia, com
     * clamp no ultimo dia do mes de destino:
     *   31/05 → 30/04 · 30/03 → 28/02 (29/02 em bissexto) · 15/01 → 15/12 do
     *   ano anterior.
     *
     * Aceita 'Y-m-d' ou 'Y-m-d H:i:s' (a hora e' ignorada). Extremidade
     * ilegivel devolve '' — o chamador trata como janela impossivel.
     *
     * NAO usar modify('-1 month'): ele estoura pro mes seguinte quando o dia
     * nao existe no destino (31/03 -1 month = 03/03).
     *
     * @return array{start: string, end: string} em 'Y-m-d'
     */
    public static function shiftWindowOneMonth(string $start, string $end): array
    {
        return [
            'start' => self::_shiftDateOneMonth($start),
            'end'   => self::_shiftDateOneMonth($end),
        ];
    }

    /** Um extremo de shiftWindowOneMonth(). '' quando a data nao e' legivel. */
    private static function _shiftDateOneMonth(string $date): string
    {
        $d = substr(trim($date), 0, 10);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) {
            return '';
        }
        $y   = (int) $m[1];
        $mo  = (int) $m[2];
        $day = (int) $m[3];
        if ($mo < 1 || $mo > 12 || $day < 1 || $day > 31) {
            return '';
        }

        $mo--;
        if ($mo < 1) {
            $mo = 12;
            $y--;
        }

        try {
            $firstOfTarget = new \DateTimeImmutable(sprintf('%04d-%02d-01', $y, $mo));
        } catch (\Throwable $e) {
            return '';
        }
        // 't' = dias do mes (28/29/30/31) — resolve fevereiro e bissexto.
        $lastDay = (int) $firstOfTarget->format('t');

        return sprintf('%04d-%02d-%02d', $y, $mo, min($day, $lastDay));
    }

    /**
     * Janela ATUAL em datas ['Y-m-d', 'Y-m-d'] pro modo 'mtd'.
     *
     * Devolve null quando o periodo atual nao e' expressavel em datas (por
     * exemplo month-year com so' mes ou so' ano) — o chamador cai em 'auto'.
     * Devolve [] quando ha' modo de data mas a janela esta' vazia/invalida.
     *
     * @return array{0: string, 1: string}|array{}|null
     */
    private function _mtdCurrentWindow(): ?array
    {
        $pt = $this->periodType ?? 'none';

        if ($pt === 'date-range') {
            $ini = self::_normalizeDateToDb($this->dtIni);
            $fim = self::_normalizeDateToDb($this->dtFim);
            return ($ini === '' || $fim === '') ? [] : [$ini, $fim];
        }

        if ($pt === 'preset') {
            if ($this->preset === '' || $this->preset === 'custom') {
                return [];
            }
            [$start, $end] = $this->presetRange($this->preset);
            if ($start === '' || $end === '') {
                return [];
            }
            return [substr($start, 0, 10), substr($end, 0, 10)];
        }

        if ($pt === 'month-year') {
            // So' mes ou so' ano: nao ha' janela de datas — 'auto' resolve.
            if ($this->mes === '' || $this->ano === '') {
                return null;
            }
            $m = (int) $this->mes;
            $y = (int) $this->ano;
            if ($m < 1 || $m > 12 || $y < 1) {
                return [];
            }
            $first = sprintf('%04d-%02d-01', $y, $m);
            $last  = date('Y-m-t', strtotime($first));
            $today = date('Y-m-d');
            // Mes corrente: "dia 1 ate hoje". Mes passado: mes inteiro.
            // Mes futuro (hoje < dia 1) ficaria com janela invertida — usa o
            // mes inteiro.
            $end = ($today >= $first && $today < $last) ? $today : $last;
            return [$first, $end];
        }

        return null;
    }

    /**
     * Info do periodo anterior. Formato depende de $periodType.
     *
     * $mode:
     *   'auto' (default) — comportamento historico.
     *   'mtd'            — mesmo periodo do mes anterior, alinhado por dia.
     *                      Devolve start/end (com hora) E dtIni/dtFim (data
     *                      pura) juntos, pra qualquer branch consumir.
     */
    public function previousPeriod(string $mode = 'auto'): array
    {
        if ($mode === 'mtd') {
            $win = $this->_mtdCurrentWindow();
            if ($win === null) {
                // Periodo sem datas (month-year parcial, none): 'auto' manda.
                return $this->previousPeriod();
            }
            if ($win === []) {
                return [];
            }
            $shift = self::shiftWindowOneMonth($win[0], $win[1]);
            if ($shift['start'] === '' || $shift['end'] === '') {
                return [];
            }
            return [
                'start' => $shift['start'] . ' 00:00:00',
                'end'   => $shift['end'] . ' 23:59:59',
                'dtIni' => $shift['start'],
                'dtFim' => $shift['end'],
                'mode'  => 'mtd',
            ];
        }

        if ($this->periodType === 'month-year') {
            $m = (int) $this->mes;
            $y = (int) $this->ano;
            if ($this->mes === '' && $this->ano === '') {
                return [];
            }
            if ($this->mes === '') {
                return ['mes' => '', 'ano' => (string) ($y - 1)];
            }
            if ($this->ano === '') {
                // Janela atual sem ano (mes X de TODOS os anos): a anterior
                // tambem fica sem ano — injetar ano criaria delta assimetrico.
                $m--;
                if ($m < 1) $m = 12;
                return [
                    'mes' => str_pad((string) $m, 2, '0', STR_PAD_LEFT),
                    'ano' => '',
                ];
            }
            $m--;
            if ($m < 1) {
                $m = 12;
                $y--;
            }
            return [
                'mes' => str_pad((string) $m, 2, '0', STR_PAD_LEFT),
                'ano' => (string) $y,
            ];
        }

        if ($this->periodType === 'date-range') {
            // dtIni/dtFim podem chegar em formato display ('30/06/2026') —
            // strtotime interpretaria d/m/Y com barras como m/d/Y americano.
            $ini = self::_normalizeDateToDb($this->dtIni);
            $fim = self::_normalizeDateToDb($this->dtFim);
            if ($ini === '' || $fim === '') {
                return [];
            }
            $startTs = strtotime($ini);
            $endTs = strtotime($fim);
            if (!$startTs || !$endTs) {
                return [];
            }
            $diff = $endTs - $startTs;
            $prevEnd = $startTs - 86400;
            $prevStart = $prevEnd - $diff;
            return [
                'dtIni' => date('Y-m-d', $prevStart),
                'dtFim' => date('Y-m-d', $prevEnd),
            ];
        }

        if ($this->periodType === 'preset') {
            if ($this->preset === '' || $this->preset === 'custom') {
                return [];
            }

            // Presets de CALENDÁRIO deslocam pra unidade anterior CHEIA —
            // o shift por tamanho de janela (legado) erraria meses de 30/31
            // dias (junho deslocado virava 02/05..31/05, não maio inteiro).
            $monthShift = function (int $back): array {
                $first = strtotime(date('Y-m-01') . " -{$back} month");
                return [
                    'start' => date('Y-m-01 00:00:00', $first),
                    'end'   => date('Y-m-t 23:59:59', $first),
                ];
            };
            $yearShift = fn (int $back): array => [
                'start' => sprintf('%d-01-01 00:00:00', (int) date('Y') - $back),
                'end'   => sprintf('%d-12-31 23:59:59', (int) date('Y') - $back),
            ];
            switch ($this->preset) {
                case 'mes':          return $monthShift(1);
                case 'mes_anterior': return $monthShift(2);
                case 'ano':          return $yearShift(1);
                case 'ano_anterior': return $yearShift(2);
                case 'trimestre':
                    $m = (int) date('m');
                    $y = (int) date('Y');
                    $q = intdiv($m - 1, 3); // trimestre atual 0-based
                    $q--;
                    if ($q < 0) {
                        $q = 3;
                        $y--;
                    }
                    $startMonth = $q * 3 + 1;
                    $endMonth   = $startMonth + 2;
                    $endDate    = date('Y-m-t', strtotime(sprintf('%d-%02d-01', $y, $endMonth)));
                    return [
                        'start' => sprintf('%d-%02d-01 00:00:00', $y, $startMonth),
                        'end'   => $endDate . ' 23:59:59',
                    ];
            }

            // Presets de janela móvel (hoje/ontem/7d/30d): janela do mesmo
            // tamanho imediatamente anterior.
            [$start, $end] = $this->presetRange($this->preset);
            if ($start === '' || $end === '') {
                return [];
            }
            $startTs = strtotime($start);
            $endTs = strtotime($end);
            $diff = $endTs - $startTs;
            $prevEnd = $startTs - 1;
            $prevStart = $prevEnd - $diff;
            return [
                'start' => date('Y-m-d H:i:s', $prevStart),
                'end' => date('Y-m-d H:i:s', $prevEnd),
            ];
        }

        return [];
    }

    /**
     * Calcula delta percentual + tendencia entre dois valores.
     * Copia exata do legado — labels pt-BR com virgula decimal.
     *
     * Retorna:
     *   [
     *     'delta_pct' => float | null,
     *     'trend'     => 'up' | 'down' | 'flat',
     *     'label'     => '+12,3%' | '-5,7%' | '0,0%' | '+∞',
     *     'sign'      => '+' | '-' | '',
     *   ]
     */
    public static function computeDelta(float $current, float $previous): array
    {
        if (abs($previous) < 1e-9) {
            if (abs($current) < 1e-9) {
                return ['delta_pct' => 0.0, 'trend' => 'flat', 'label' => '0,0%', 'sign' => ''];
            }
            $trend = $current > 0 ? 'up' : 'down';
            $sign = $current > 0 ? '+' : '-';
            return ['delta_pct' => null, 'trend' => $trend, 'label' => $sign . '∞', 'sign' => $sign];
        }
        $deltaPct = (($current - $previous) / abs($previous)) * 100.0;
        if (abs($deltaPct) < 0.05) {
            return ['delta_pct' => $deltaPct, 'trend' => 'flat', 'label' => '0,0%', 'sign' => ''];
        }
        $trend = $deltaPct > 0 ? 'up' : 'down';
        // sign consistente com o caso ±∞: '-' pra queda (o label ja traz o
        // '-' via number_format; consumidores devem usar trend/sign, nao o label).
        $sign  = $deltaPct > 0 ? '+' : '-';
        $label = ($deltaPct > 0 ? '+' : '') . number_format($deltaPct, 1, ',', '.') . '%';
        return ['delta_pct' => $deltaPct, 'trend' => $trend, 'label' => $label, 'sign' => $sign];
    }

    // ───────────────────────────────────────────────────────────────────
    // DASHBOARD-ONLY: CLICK-TO-FILTER (chart drill-through)
    // ───────────────────────────────────────────────────────────────────

    /**
     * Models permitidos no drill-por-label (wire-callable com args do cliente).
     * Default null = qualquer Eloquent Model resolvivel pelo registry.
     * Override retornando lista de FQCNs pra restringir a superficie.
     */
    protected function allowedLabelLookupModels(): ?array
    {
        return null;
    }

    /**
     * Aplica filtro a partir de label de chart, com toggle automatico.
     *
     * Endpoint wire-callable: todos os args chegam do cliente — valida prop
     * (so filter props), model (Eloquent + allowlist opcional) e field
     * (coluna real) antes de tocar no banco.
     */
    public function applyFilterFromLabel(string $prop, string $model, string $field, string $label, bool $toggle = true): void
    {
        if (!property_exists($this, $prop)) return;
        if (!in_array($prop, $this->discoverFilterProps(), true)) return;
        if ($label === '') return;

        $rec = null;
        try {
            // Short-name 'Cliente' → App\Models\Cliente via registry. O antigo
            // fallback '\'.$model cru aceitava classe global arbitraria.
            $fullClass = \Mad\Form\ModelOptionsLoader::resolveModelClass($model);
            if (!is_subclass_of($fullClass, \Illuminate\Database\Eloquent\Model::class)) return;

            $allowed = $this->allowedLabelLookupModels();
            if ($allowed !== null) {
                $norm = array_map(fn ($c) => ltrim((string) $c, '\\'), $allowed);
                if (!in_array(ltrim($fullClass, '\\'), $norm, true)) return;
            }
            if (!$this->modelHasColumn($model, $field)) return;

            $rec = $fullClass::where($field, '=', $label)->first();
        } catch (\Throwable $e) {
            error_log('[MadDashboard::applyFilterFromLabel] ' . $e->getMessage());
            return;
        }
        if (!$rec) return;

        $id = (string) $rec->getKey();
        if ($toggle && $this->$prop === $id) {
            $this->$prop = '';
        } else {
            $this->$prop = $id;
        }
        $this->syncFormFields();
        $this->saveFilterSession();
        $this->forceFullRender();
    }

    /** Variante sem toggle. */
    public function applyFilterFromLabelNoToggle(string $prop, string $model, string $field, string $label): void
    {
        $this->applyFilterFromLabel($prop, $model, $field, $label, false);
    }

    /**
     * Atalho — aplica filtro com valor direto (sem lookup).
     */
    public function applyFilterDirect(string $prop, string $value, bool $toggle = true): void
    {
        if (!property_exists($this, $prop)) return;
        if (!in_array($prop, $this->discoverFilterProps(), true)) return;
        $value = (string) $value;
        if ($toggle && $this->$prop === $value) {
            $this->$prop = '';
        } else {
            $this->$prop = $value;
        }
        $this->syncFormFields();
        $this->saveFilterSession();
        $this->forceFullRender();
    }

    /** Variante sem toggle — sempre seta. */
    public function applyFilterDirectNoToggle(string $prop, string $value): void
    {
        $this->applyFilterDirect($prop, $value, false);
    }

    // ───────────────────────────────────────────────────────────────────
    // DASHBOARD-ONLY: CACHE DO PAYLOAD DA VIEW (por filtros — ver $cacheTtl)
    // ───────────────────────────────────────────────────────────────────

    /**
     * Dados serializáveis da view (arrays/escalares) — a subclasse implementa
     * quando liga o cache. NUNCA retornar Builders/closures aqui.
     */
    protected function cacheableViewData(): array
    {
        return [];
    }

    /**
     * cacheableViewData() com cache por filtros quando $cacheTtl > 0.
     * Falha do store nunca derruba a tela (fallback pro cômputo direto).
     */
    protected function viewData(): array
    {
        $ttl = $this->cacheTtlFor();
        if ($ttl <= 0) {
            return $this->cacheableViewData();
        }

        try {
            return \Illuminate\Support\Facades\Cache::remember(
                $this->viewCacheKey(),
                $ttl,
                fn () => $this->cacheableViewData(),
            );
        } catch (\Throwable $e) {
            @error_log('[MadDashboard viewData] fallback sem cache: ' . $e->getMessage());

            return $this->cacheableViewData();
        }
    }

    /** TTL efetivo — override p/ regras tipo "janela fechada = TTL maior". */
    protected function cacheTtlFor(): int
    {
        return $this->cacheTtl;
    }

    /**
     * Chave: classe + escopo (tenant/unidade) + extra do host + snapshot
     * COMPLETO de filtros.
     *
     * O escopo entra sozinho quando o app tem Multi-unidade ou tenant em pool
     * ligados (ou a tela filtra pela unidade da sessão — $applyUnitFilter):
     * antes a chave não conhecia a unidade e o painel cacheado de uma unidade
     * era servido à outra. Fica AQUI, e não no cacheKeyExtra(), para valer
     * mesmo quando o host sobrescreve o extra. Escopo de domínio próprio
     * (franquia, carteira do usuário…) continua no cacheKeyExtra().
     */
    protected function viewCacheKey(): string
    {
        $scope = \Mad\Database\DataScope::cacheKey(false, (bool) $this->applyUnitFilter);

        return 'maddash:' . str_replace('\\', '.', static::class)
            . ($scope !== '' ? ':' . $scope : '')
            . ':' . $this->cacheKeyExtra()
            . ':' . md5(json_encode($this->filterStateSnapshot(), JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    /** Componente extra da chave (escopo de tenant, versão dos dados etc). */
    protected function cacheKeyExtra(): string
    {
        return '-';
    }

    /**
     * Fallback POR PÁGINA do cache de agregações dos charts (camada B):
     * com o global `mad.chart.cache_ttl` desligado (<= 0) e `$cacheTtl > 0`,
     * aplica o TTL da página na config DESTA request antes do render — os
     * <mad-db-chart>/<mad-db-metric-card> `:query` da tela passam a usar o
     * Mad\Database\QueryCache com o TTL da página. Global ligado SEMPRE
     * vence (nunca rebaixa nem sobe TTL de projeto). Octane-safe: a config
     * é por-request (sandbox), nada vaza entre requests/usuários.
     */
    protected function applyPageChartCacheTtl(): void
    {
        if ($this->cacheTtl <= 0 || ! function_exists('config')) {
            return;
        }
        if ((int) config('mad.chart.cache_ttl', 0) <= 0) {
            config(['mad.chart.cache_ttl' => $this->cacheTtl]);
        }
    }

    /** Aplica o fallback de chart-cache da página antes de todo render. */
    public function render(): string
    {
        $this->applyPageChartCacheTtl();

        return parent::render();
    }

    // ───────────────────────────────────────────────────────────────────
    // DASHBOARD-ONLY: WIDGET VISIBILITY (toggle via session)
    // ───────────────────────────────────────────────────────────────────

    /**
     * Toggle visibility de um widget. Persiste em session.
     */
    public function onToggleWidget(string $key): void
    {
        $hidden = session($this->_widgetSessionKey('hidden_widgets'));
        if (!is_array($hidden)) {
            $hidden = [];
        }
        if (in_array($key, $hidden, true)) {
            $hidden = array_values(array_diff($hidden, [$key]));
        } else {
            $hidden[] = $key;
        }
        session([$this->_widgetSessionKey('hidden_widgets') => $hidden]);
    }

    /**
     * Verifica se um widget esta visivel.
     */
    public function isWidgetVisible(string $widgetKey): bool
    {
        $hidden = session($this->_widgetSessionKey('hidden_widgets'));
        if (!is_array($hidden)) {
            return true;
        }
        return !in_array($widgetKey, $hidden, true);
    }

    /** Lista de widgets escondidos (pra UI de configuracao). */
    public function hiddenWidgets(): array
    {
        $hidden = session($this->_widgetSessionKey('hidden_widgets'));
        return is_array($hidden) ? $hidden : [];
    }

    /** Key da session pra widget toggle (prefixo legado mantido). */
    private function _widgetSessionKey(string $suffix = 'state'): string
    {
        return 'mad_dashboard_' . static::class . '_' . $suffix;
    }

    // ───────────────────────────────────────────────────────────────────
    // DASHBOARD-ONLY: AUTO-REFRESH GETTER
    // ───────────────────────────────────────────────────────────────────

    public function getAutoRefreshSec(): int
    {
        // Clamp do "minimo 5" prometido no docblock de $autoRefreshSec —
        // valores 1-4 viravam desliga-silencioso na blade.
        return $this->autoRefreshSec > 0 ? max(5, $this->autoRefreshSec) : 0;
    }
}
