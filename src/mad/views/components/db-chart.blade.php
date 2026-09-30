@php
    /**
     * <mad-db-chart> — Componente de gráfico ECharts auto-contido.
     *
     * Constrói um MadChart internamente a partir de props Blade,
     * executa a query ORM e renderiza o gráfico — sem precisar
     * de lógica no controller PHP.
     *
     * Padrão análogo ao <mad-db-metric-card>.
     *
     * Props:
     *   type              string      'bar'         bar|line|pie|donut|rose|funnel|treemap|radar|mixed
     *   name              string      auto          ID único do chart
     *   model             string      ''            Classe ORM (ex: 'PedidoVenda')
     *   database          string      MAIN_DATABASE Conexão DB
     *   group-by          string|array ''            Campo(s) de agrupamento
     *   field             string      ''            Campo para sum/avg/min/max
     *   total             string      'count'       count|sum|avg|min|max
     *   filters           array       []            Filtros inline [['campo','op','val'], ...]
     *   joins             array       []            Joins [['alias' => ['left','right']]]
     *   data              array       null          Dados manuais ['Label' => val, ...]
     *   title             string      ''            Título
     *   subtitle          string      ''            Subtítulo
     *   height            int         300           Altura em px
     *   width             string      '100%'        Largura
     *   legend            bool        false         Mostrar legenda
     *   legend-position   string      'bottom'      bottom|right
     *   no-panel          bool        false         Sem card wrapper
     *   percentage        bool        false         Mostrar % (pie/donut/rose)
     *   colors            array       []            Paleta custom
     *   class             string      ''            CSS extra
     *   format            string      ''            'currency'|'currency:R$'|'currency:R$:2:,:.'|'numeric:2'
     *   suffix            string      ''            Sufixo nos valores
     *   abbreviate        bool        false         Valores abreviados (K, M, B)
     *   horizontal        bool        false         Bar horizontal
     *   stacked           bool|string false         Bar empilhado; "internal" = barras
     *                                                ANINHADAS (sobrepostas e centralizadas
     *                                                no slot: maior série mais larga e
     *                                                atrás, menor mais estreita na frente)
     *   nested-widths     array       null          stacked="internal": escada de larguras
     *                                                em % do slot (ordem irrelevante —
     *                                                aplicada do maior valor pro menor;
     *                                                null = automática 72% ×0.85/nível)
     *   nested-cap        int         110           stacked="internal": banda máxima px do
     *                                                teto de largura (barMaxWidth = % disso)
     *   area              bool        false         Line com área
     *   smooth            bool        true          Curva suave (line/area)
     *   line-series       array|string []           mixed modo A: séries (nome da 2ª
     *                                                dimensão do group-by, pós sub-legend-
     *                                                transformer, ou índice int) que viram
     *                                                LINHA sobre as colunas; em type="bar"
     *                                                a prop promove o chart a mixed
     *   line-total        string      ''            mixed modo B: 2ª agregação que vira
     *                                                LINHA (count|sum|avg|min|max) — exige
     *                                                group-by de 1 dimensão; em type="bar"
     *                                                promove a mixed. Exclusivo com
     *                                                line-series
     *   line-field        string      ''            mixed modo B: campo da 2ª agregação
     *                                                (vazio + count → count(*))
     *   line-label        string      ''            mixed modo B: nome da série de linha
     *   line-secondary-axis bool      false         mixed: linha usa eixo Y secundário
     *                                                (direita)
     *                                                Notas mixed: horizontal e
     *                                                stacked="internal" são ignorados;
     *                                                stacked normal empilha só as colunas
     *                                                (linha fica na frente).
     *                                                Notas radar: valores negativos são
     *                                                clampados em 0 no polígono (tooltip com
     *                                                transformer mostra o raw); recomenda
     *                                                ≥3 categorias; filter-prop/filter-mode
     *                                                são ignorados (clique devolve o
     *                                                polígono, não uma categoria —
     *                                                click-action custom continua valendo:
     *                                                params.name = nome da série).
     *   transformer       callable    null          Value transformer (PHP callable)
     *   legend-transformer callable   null          Legend/category transformer (PHP callable)
     *   sub-legend-transformer callable null        Sub-legend transformer (multi-series)
     *   legend-format     string      ''            Format declarativo dos labels:
     *                                                  date|date-short|date-long|datetime|time
     *                                                  year|month|month-short|month-year|month-year-short
     *                                                  weekday|weekday-short|quarter|quarter-year
     *                                                  integer|numeric|currency|percent|abbreviate
     *   options           array       []            Extra ECharts options (merge)
     *   debug-sql         string|array ''           SQL raw executado no HOST (charts :data,
     *                                                onde o componente não vê a query). Exibido
     *                                                no painel SQL Debug quando general.debug=1.
     *                                                Array ['label' => sql] concatena com "-- label".
     */

    // ── Identificação ──────────────────────────────────────────────────────
    $type     = $type     ?? 'bar';
    $name     = $name     ?? ('dbchart_' . uniqid());

    // ── Fonte ORM ──────────────────────────────────────────────────────────
    $model    = $model    ?? '';
    $database = $database ?? (defined('MAIN_DATABASE') ? MAIN_DATABASE : 'business');
    $groupBy  = $groupBy  ?? '';
    $field    = $field    ?? '';
    $total    = $total    ?? 'count';
    $filters  = $filters  ?? [];
    $query    = $query    ?? null;   // Eloquent/Query Builder (builder-native)
    $joins    = $joins    ?? [];

    // ── Dados manuais ──────────────────────────────────────────────────────
    $data     = $data     ?? null;

    // ── Visual ─────────────────────────────────────────────────────────────
    $title    = $title    ?? '';
    $subtitle = $subtitle ?? '';
    $heightRaw = $height ?? 300;
    $autoHeight = ($heightRaw === 'auto');
    $height     = $autoHeight ? 0 : (int)$heightRaw;
    $width    = $width    ?? '100%';
    $legend   = $legend   ?? false;
    $legendPosition = $legendPosition ?? 'bottom';
    $noPanel  = $noPanel  ?? false;
    $percentage = $percentage ?? false;
    $colors   = $colors   ?? [];
    $class    = $class    ?? '';

    // ── Formatação ─────────────────────────────────────────────────────────
    $format     = $format     ?? '';
    $suffix     = $suffix     ?? '';
    $abbreviate = $abbreviate ?? false;

    // ── Tipo-específico ────────────────────────────────────────────────────
    $horizontal   = $horizontal   ?? false;
    $stacked      = $stacked      ?? false;
    $nestedWidths = $nestedWidths ?? null;
    $nestedCap    = $nestedCap    ?? null;
    $area         = $area         ?? false;
    $smooth       = $smooth       ?? true;
    $lineSeries        = $lineSeries        ?? [];
    $lineSecondaryAxis = $lineSecondaryAxis ?? false;
    $lineTotal         = $lineTotal         ?? '';
    $lineField         = $lineField         ?? '';
    $lineLabel         = $lineLabel         ?? '';

    // ── Transformers ───────────────────────────────────────────────────────
    $transformer          = $transformer          ?? null;
    $legendTransformer    = $legendTransformer    ?? null;
    $subLegendTransformer = $subLegendTransformer ?? null;
    $legendFormat         = $legendFormat         ?? '';

    // ── ECharts extras ─────────────────────────────────────────────────────
    $options  = $options  ?? [];

    // ── Debug SQL manual (charts :data — a query roda no host, não aqui) ───
    // Capturado ANTES do bloco de debug abaixo, que reusa a var $debugSql.
    $debugSqlProp = $debugSql ?? null;
    if (is_array($debugSqlProp)) {
        $__dbgParts = [];
        foreach ($debugSqlProp as $__dbgK => $__dbgS) {
            if ((string) $__dbgS === '') continue;
            $__dbgParts[] = (is_string($__dbgK) ? "-- {$__dbgK}\n" : '') . (string) $__dbgS;
        }
        $debugSqlProp = implode("\n\n", $__dbgParts);
    }
    $debugSqlProp = is_string($debugSqlProp) ? trim($debugSqlProp) : '';

    // ── Click-to-filter (drill-through) ────────────────────────────────────
    // Forma 1 — handler custom:
    //   click-action="metodoPhp" → chama MadWire.call(wrapper, click_action,
    //   [params.name, params.value, params.dataIndex])
    //
    // Forma 2 — declarativa (preferida):
    //   filter-prop="cliente_id" filter-mode="direct"          → applyFilterDirect
    //   filter-prop="x" filter-mode="lookup" filter-model="M"  → applyFilterFromLabel
    //   filter-mode="period"                                    → setMesAno (mes/ano)
    //   filter-secondary="categoria_id"                         → 2o filtro por seriesName
    //   filter-toggle=false                                     → re-click NAO limpa
    //
    // Mode auto-detect (se filterMode === '' e filterProp !== ''):
    //   - se filterModel set            → lookup
    //   - se legendFormat e tipo data   → period
    //   - default                       → direct
    $clickAction     = $clickAction     ?? '';
    $filterProp      = $filterProp      ?? '';
    $filterMode      = $filterMode      ?? '';
    $filterModel     = $filterModel     ?? '';
    $filterField     = $filterField     ?? '';
    $filterSecondary = $filterSecondary ?? '';
    $filterToggle    = (isset($filterToggle) && ($filterToggle === false || $filterToggle === 'false' || $filterToggle === '0'))
                        ? false : true;

    // Auto-detect mode quando nao especificado
    if ($filterMode === '' && $filterProp !== '') {
        if ($filterModel !== '') {
            $filterMode = 'lookup';
        } elseif (in_array(strtolower($legendFormat), [
            'date', 'date-short', 'date-long', 'datetime',
            'year', 'month', 'month-short', 'month-year', 'month-year-short',
            'quarter', 'quarter-year',
        ], true)) {
            $filterMode = 'period';
        } else {
            $filterMode = 'direct';
        }
    }
    // filter-mode="period" sem prop tambem e valido — usa mes/ano do dashboard
    if ($filterMode === 'period' && $filterProp === '') {
        // OK — sem prop alvo, setMesAno cuida
    }
    $hasFilter = ($filterMode !== '' && ($filterMode === 'period' || $filterProp !== ''));

    // ── Fonte → builder (100% Query Builder). :query | :filters | model ──
    // :filters vira wheres no builder; a agregação roda sobre o builder (fromQuery).
    if (!\Mad\Database\QuerySource::isQuery($query) && $model && !empty($filters)) {
        try {
            $__m  = \Mad\Form\ModelOptionsLoader::resolveModelClass($model);
            $__qb = $__m::query();
            if (!empty($filters)) {
                \Mad\Database\QuerySource::applyArrayFilters($__qb, $filters);
            }
            $query = $__qb;
        } catch (\Throwable $e) {
            // fallback: mantém model + filtros inline se a resolução falhar
        }
    }

    // ── Build MadChart ─────────────────────────────────────────────────────
    $validTypes = ['bar', 'line', 'pie', 'donut', 'rose', 'funnel', 'treemap', 'radar', 'mixed'];
    if (!in_array($type, $validTypes)) {
        $type = 'bar';
    }

    // Conveniência: bar + line-series/line-total promove a mixed.
    if ($type === 'bar' && (!empty($lineSeries) || $lineTotal !== '')) {
        $type = 'mixed';
    }

    // Radar: drill-through desabilitado — o clique devolve o POLÍGONO
    // (params.name = série, params.value = vetor), não uma categoria.
    if ($type === 'radar') {
        $hasFilter = false;
    }

    $chart = \Mad\Chart\MadChart::{$type}($name);

    // Fonte de dados
    if ($data !== null) {
        $chart->data($data);
    } else {
        if (\Mad\Database\QuerySource::isQuery($query)) {
            $chart->fromQuery($query);
        } elseif ($model) {
            $chart->fromModel($model);
        }
        $chart->database($database);
        if (!empty($joins))   $chart->joins($joins);
        if (!empty($groupBy)) $chart->groupBy($groupBy);
        if (!empty($field)) {
            $chart->{$total}($field);
        } else {
            $chart->count();
        }
    }

    // Visual
    if ($title || $subtitle) $chart->title($title, $subtitle);
    $chart->height($height);
    $chart->width($width);
    if ($legend)     $chart->legend(true, $legendPosition);
    if ($noPanel)    $chart->noPanel();
    if ($percentage) $chart->percentage();
    if ($colors)     $chart->colors($colors);

    // Formatação — primeiro tenta props nativas (currency/numeric) p/
    // preservar formatTooltip do BChart legado; demais formatos caem no
    // MadChartFormatter via transformer().
    $fmtNativo = false;
    if ($format) {
        $parts   = explode(':', $format);
        $fmtType = strtolower($parts[0]);
        if ($fmtType === 'currency' || $fmtType === 'money') {
            $fmtPrefix    = $parts[1] ?? 'R$ ';
            $fmtPrecision = (int)($parts[2] ?? 2);
            $fmtDecimal   = $parts[3] ?? ',';
            $fmtThousand  = $parts[4] ?? '.';
            $chart->currency($fmtPrecision, $fmtPrefix, $fmtDecimal, $fmtThousand);
            $fmtNativo = true;
        } elseif ($fmtType === 'numeric' || $fmtType === 'number') {
            $fmtPrecision = (int)($parts[1] ?? 2);
            $fmtDecimal   = $parts[2] ?? ',';
            $fmtThousand  = $parts[3] ?? '.';
            $chart->numeric($fmtPrecision, $fmtDecimal, $fmtThousand);
            $fmtNativo = true;
        }
        if (!$fmtNativo && !$transformer) {
            // integer, percent, abbreviate, datas, mes/ano etc
            $transformer = \Mad\Chart\MadChartFormatter::make($format);
        }
    }
    if ($suffix)     $chart->suffix($suffix);
    if ($abbreviate) $chart->abbreviate();

    // Legend format — aplicado em labels do eixo X / categorias / fatias.
    if ($legendFormat && !$legendTransformer) {
        $__lf = \Mad\Chart\MadChartFormatter::make($legendFormat);
        $legendTransformer = static fn($label) => $__lf($label);
    }

    // Tipo-específico
    if ($horizontal) $chart->horizontal();
    if ($stacked === 'internal') {
        $chart->stackedInternal(
            is_array($nestedWidths) && $nestedWidths !== [] ? $nestedWidths : null,
            $nestedCap !== null && $nestedCap !== '' ? (int) $nestedCap : null
        );
    } elseif ($stacked) {
        $chart->stacked();
    }
    if ($area)       $chart->area($smooth);
    if ($type === 'mixed') {
        if (!empty($lineSeries)) {
            $chart->seriesAsLine($lineSeries, (bool) $lineSecondaryAxis);
        }
        if ($lineTotal !== '' || $lineField !== '') {
            $chart->lineMetric(
                $lineTotal !== '' ? $lineTotal : 'count',
                $lineField !== '' ? $lineField : null,
                $lineLabel !== '' ? $lineLabel : null,
                (bool) $lineSecondaryAxis
            );
        }
    }

    // Transformers
    if ($transformer)          $chart->transformer($transformer);
    if ($legendTransformer)    $chart->legendTransformer($legendTransformer);
    if ($subLegendTransformer) $chart->subLegendTransformer($subLegendTransformer);

    // ECharts extras
    if ($options) $chart->options($options);

    // ── Build config ───────────────────────────────────────────────────────
    $cfg        = null;
    $chartError = null;
    $beChart    = null;
    try {
        $beChart = $chart->getEngine();
        $cfg     = $chart->toConfig();
    } catch (\Throwable $e) {
        $cfg        = null;
        $chartError = $e->getMessage();
    }

    $chartTitle  = $cfg ? $cfg->title    : $title;
    // Já com unidade: o `.'px'` que ficava nos dois call sites abaixo gerava
    // `300pxpx` pra quem escrevia height="300px" — altura silenciosamente perdida.
    $chartHeight = \Mad\Support\CssUnits::length((string) ($cfg ? $cfg->height : $height), '300px');
    $chartWidth  = \Mad\Support\CssUnits::length((string) ($cfg ? $cfg->width : $width), '100%');
    $chartName   = $cfg ? $cfg->name     : $name;
    $showPanel   = $cfg ? $cfg->showPanel : !$noPanel;
    $hasData     = $cfg ? $cfg->hasData   : false;
    $cfgSubtitle = $cfg ? $cfg->subtitle  : $subtitle;

    // ── Click-to-filter: raw categories + active filter value ──────────────
    // Categorias raw indexadas por dataIndex (para resolver params.dataIndex
    // → valor original sem transformer). Series raw idem para sub-agrupador.
    $rawCategories  = [];
    $rawSeriesNames = [];
    if ($hasFilter && $beChart && $hasData) {
        try {
            $rows = $beChart->getData();
            $dims = method_exists($beChart, 'getGroupDimensions') ? $beChart->getGroupDimensions() : 1;
            // categorias raw na ordem de aparicao
            $seen = [];
            foreach ($rows as $r) {
                $cat = (string) ($r[0] ?? '');
                if (!isset($seen[$cat])) {
                    $seen[$cat] = true;
                    $rawCategories[] = $cat;
                }
            }
            if ($dims > 1) {
                $seenS = [];
                foreach ($rows as $r) {
                    $s = (string) ($r[$dims - 1] ?? '');
                    if (!isset($seenS[$s])) {
                        $seenS[$s] = true;
                        $rawSeriesNames[] = $s;
                    }
                }
            }
        } catch (\Throwable $e) {
            $rawCategories  = [];
            $rawSeriesNames = [];
        }
    }

    // Valor ativo da prop (pra realce visual). Lido via MadRenderContext.
    $activeFilterValue     = null;
    $activeFilterSecondary = null;
    $activeFilterMes       = null;
    $activeFilterAno       = null;
    if ($hasFilter) {
        try {
            $__comp = \Mad\Component\MadRenderContext::getComponent();
            if ($__comp) {
                if ($filterProp !== '' && property_exists($__comp, $filterProp)) {
                    $activeFilterValue = $__comp->{$filterProp};
                }
                if ($filterSecondary !== '' && property_exists($__comp, $filterSecondary)) {
                    $activeFilterSecondary = $__comp->{$filterSecondary};
                }
                if ($filterMode === 'period') {
                    if (property_exists($__comp, 'mes')) $activeFilterMes = (string) $__comp->mes;
                    if (property_exists($__comp, 'ano')) $activeFilterAno = (string) $__comp->ano;
                }
            }
        } catch (\Throwable $e) {
            // ignora
        }
    }

    // ── Debug SQL (general.debug = 1) ──────────────────────────────────────
    // Captura SQL mesmo quando a query lanca excecao — usa o engine direto
    // pois o config nao chega a ser construido nesse caso.
    $debugEnabled = \Mad\Support\MadDebug::sqlPanel();

    $debugSql      = null;
    $debugInlined  = null;
    $debugBinds    = [];
    if ($debugEnabled) {
        if ($cfg && $cfg->sql) {
            $debugSql     = $cfg->sql;
            $debugInlined = $cfg->sqlInlined;
            $debugBinds   = $cfg->sqlBinds;
        } elseif ($beChart && $beChart->getLastSql()) {
            $debugSql     = $beChart->getLastSql();
            $debugInlined = $beChart->getLastSqlInlined();
            $debugBinds   = $beChart->getLastSqlBinds();
        } elseif ($debugSqlProp !== '') {
            // Charts :data — SQL informado pelo host via prop debug-sql.
            $debugSql     = $debugSqlProp;
            $debugInlined = null;
            $debugBinds   = [];
        }
    }
@endphp

@if($chartError)
    {{-- Erro ao montar gráfico --}}
    <div class="mad-card {{ $class }}">
        <div class="mad-card-content" style="padding:1.5rem;color:var(--mad-danger,#dc3545);">
            <strong>[mad-db-chart] {{ $name }}</strong>
            <p style="margin:.5rem 0 0;font-size:.85rem;">{{ $chartError }}</p>
        </div>
        @if($debugEnabled && $debugSql)
            @include('components.partials.db-chart-debug', [
                'debugSql' => $debugSql,
                'debugInlined' => $debugInlined,
                'debugBinds' => $debugBinds,
                'chartName' => $name,
            ])
        @endif
    </div>

@elseif($cfg && !$hasData)
    {{-- Estado vazio --}}
    <div class="mad-card {{ $class }}" style="width:{{ $chartWidth }};">
        @if($chartTitle)
            <div class="mad-card-header">
                <div style="display:flex;flex-direction:column;gap:.15rem;min-width:0;">
                    <strong class="mad-card-title">{{ $chartTitle }}</strong>
                    @if($cfgSubtitle)
                        <p class="mad-text-muted" style="margin:0;font-size:.85rem;line-height:1.3;">{{ $cfgSubtitle }}</p>
                    @endif
                </div>
            </div>
        @endif
        <div class="mad-card-content" style="text-align:center;padding:2rem;color:var(--mad-muted);">
            <i data-lucide="chart-bar" style="width:40px;height:40px;margin-bottom:.5rem;opacity:.3;display:block;margin-left:auto;margin-right:auto;"></i>
            <p style="margin:0;font-size:.9rem;">{{ mad_t('mad.chart.no_data') }}</p>
        </div>
        @if($debugEnabled && $debugSql)
            @include('components.partials.db-chart-debug', [
                'debugSql' => $debugSql,
                'debugInlined' => $debugInlined,
                'debugBinds' => $debugBinds,
                'chartName' => $chartName,
            ])
        @endif
    </div>

@elseif($cfg && $showPanel)
    {{-- Gráfico com card MAD UI --}}
    <div class="mad-card {{ $class }}" style="width:{{ $chartWidth }};{{ $autoHeight ? 'display:flex;flex-direction:column;height:100%;' : '' }}">
        @if($chartTitle)
            <div class="mad-card-header">
                <div style="display:flex;flex-direction:column;gap:.15rem;min-width:0;">
                    <strong class="mad-card-title">{{ $chartTitle }}</strong>
                    @if($cfgSubtitle)
                        <p class="mad-text-muted" style="margin:0;font-size:.85rem;line-height:1.3;">{{ $cfgSubtitle }}</p>
                    @endif
                </div>
            </div>
        @endif
        <div class="mad-card-content" style="padding:0;{{ $autoHeight ? 'flex:1;min-height:0;' : '' }}">
            <div id="{{ $chartName }}-container" style="width:100%;{{ $autoHeight ? 'height:100%;min-height:200px;' : 'height:'.$chartHeight.';' }}"></div>
        </div>
        @if($debugEnabled && $debugSql)
            @include('components.partials.db-chart-debug', [
                'debugSql' => $debugSql,
                'debugInlined' => $debugInlined,
                'debugBinds' => $debugBinds,
                'chartName' => $chartName,
            ])
        @endif
    </div>

@elseif($cfg)
    {{-- Sem painel — container bare --}}
    <div id="{{ $chartName }}-container" class="{{ $class }}" style="width:{{ $chartWidth }};height:{{ $chartHeight }};"></div>
    @if($debugEnabled && $debugSql)
        @include('components.partials.db-chart-debug', [
            'debugSql' => $debugSql,
            'debugInlined' => $debugInlined,
            'debugBinds' => $debugBinds,
            'chartName' => $chartName,
        ])
    @endif
@endif

@if($cfg && $hasData)
<script>
(function () {
    'use strict';
    const NAME    = '{{ $chartName }}';
    const OPTIONS = {!! $cfg->optionsJson !!};
    const __dv_{{ $chartName }} = {!! $cfg->displayValuesJson !!};

    // Helper global: dispose de TODOS os charts dentro de um root que vai ser
    // descartado (ex.: pane de <mad-dashboard-tabs> antes de reinjetar HTML).
    // Sem isto, trocar o conteúdo de um pane deixa instâncias ECharts órfãs e
    // ResizeObserver/listeners vivos (window.__madCharts é global por NAME).
    if (typeof window.__madDisposeChartsIn !== 'function') {
        window.__madDisposeChartsIn = function (root) {
            if (!root || !root.querySelectorAll) return;
            root.querySelectorAll('[data-echart-inited]').forEach(function (cEl) {
                var cName = (cEl.id || '').replace(/-container$/, '');
                if (!cName) return;
                try { window.__madChartCleanup && window.__madChartCleanup[cName] && window.__madChartCleanup[cName](); } catch (e) {}
                try { window.__madCharts && window.__madCharts[cName] && window.__madCharts[cName].dispose(); } catch (e) {}
                if (window.__madCharts) delete window.__madCharts[cName];
                delete cEl.dataset.echartInited;
            });
        };
    }

    function initChart() {
        const el = document.getElementById(NAME + '-container');
        if (!el || el.dataset.echartInited) return;
        if (typeof echarts === 'undefined') {
            console.error('[mad-db-chart] echarts.min.js não está carregado.');
            return;
        }

        if (window.__madCharts && window.__madCharts[NAME]) {
            try { window.__madCharts[NAME].dispose(); } catch {}
            delete window.__madCharts[NAME];
        }

        el.dataset.echartInited = '1';
        var isDark = document.documentElement.getAttribute('data-theme') === 'dark';
        // Tema custom do app (echarts.registerTheme) via window.__madEchartsTheme{Light,Dark};
        // fallback: comportamento original ('dark' nativo / default).
        var __madTheme = isDark ? (window.__madEchartsThemeDark || 'dark') : (window.__madEchartsThemeLight || null);
        const chart = echarts.init(el, __madTheme, { renderer: 'svg' });
        if (isDark) { OPTIONS.backgroundColor = 'transparent'; }
        chart.setOption(OPTIONS);

        if (!window.__madCharts) window.__madCharts = {};
        window.__madCharts[NAME] = chart;

        @if($clickAction || $hasFilter)
        // ── Click-to-filter (drill-through) ──────────────────────────────
        el.style.cursor = 'pointer';

        @if($hasFilter)
        var __FILTER = {
            mode:           {!! json_encode($filterMode) !!},
            prop:           {!! json_encode($filterProp) !!},
            secondary:      {!! json_encode($filterSecondary) !!},
            model:          {!! json_encode($filterModel) !!},
            field:          {!! json_encode($filterField ?: 'nome') !!},
            toggle:         {!! $filterToggle ? 'true' : 'false' !!},
            rawCategories:  {!! json_encode($rawCategories) !!},
            rawSeriesNames: {!! json_encode($rawSeriesNames) !!},
            activeValue:     {!! json_encode($activeFilterValue !== null ? (string) $activeFilterValue : null) !!},
            activeSecondary: {!! json_encode($activeFilterSecondary !== null ? (string) $activeFilterSecondary : null) !!},
            activeMes:       {!! json_encode($activeFilterMes) !!},
            activeAno:       {!! json_encode($activeFilterAno) !!}
        };

        function __madRawCategory(p) {
            // Resolve raw label by dataIndex; falls back to params.name when index out of range.
            if (__FILTER.rawCategories && __FILTER.rawCategories.length > p.dataIndex) {
                return String(__FILTER.rawCategories[p.dataIndex]);
            }
            return String(p.name != null ? p.name : '');
        }

        function __madRawSeries(p) {
            // For multi-series: params.seriesName may be transformed; find raw by index.
            if (!p.seriesName || !__FILTER.rawSeriesNames.length) return String(p.seriesName || '');
            // ECharts gives seriesIndex
            if (typeof p.seriesIndex === 'number' && __FILTER.rawSeriesNames[p.seriesIndex] !== undefined) {
                return String(__FILTER.rawSeriesNames[p.seriesIndex]);
            }
            return String(p.seriesName);
        }
        @endif

        chart.on('click', function (params) {
            var wrapper = el.closest('[mad-component]');
            if (!wrapper) return;
            if (typeof MadWire === 'undefined' || !MadWire.call) return;

            @if($clickAction)
            // Forma 1 — handler custom
            MadWire.call(wrapper, '{{ $clickAction }}', [
                params.name,
                params.value,
                params.dataIndex
            ]);
            @else
            // Forma 2 — declarativa
            var raw = __madRawCategory(params);

            var directAction = __FILTER.toggle ? 'applyFilterDirect' : 'applyFilterDirectNoToggle';
            var lookupAction = __FILTER.toggle ? 'applyFilterFromLabel' : 'applyFilterFromLabelNoToggle';

            if (__FILTER.mode === 'direct' && __FILTER.prop) {
                MadWire.call(wrapper, directAction, [__FILTER.prop, raw]);
                if (__FILTER.secondary) {
                    var rawS = __madRawSeries(params);
                    if (rawS) {
                        MadWire.call(wrapper, directAction, [__FILTER.secondary, rawS]);
                    }
                }
            } else if (__FILTER.mode === 'lookup' && __FILTER.prop && __FILTER.model) {
                MadWire.call(wrapper, lookupAction,
                    [__FILTER.prop, __FILTER.model, __FILTER.field, raw]);
            } else if (__FILTER.mode === 'period') {
                // Tenta interpretar como periodo. Aceita 'YYYY-MM', 'YYYY', 'MM'.
                if (/^\d{4}-\d{1,2}$/.test(raw)) {
                    var p2 = raw.split('-');
                    var mm = ('0' + p2[1]).slice(-2);
                    MadWire.call(wrapper, 'setMesAno', [mm, p2[0]]);
                } else if (/^\d{4}$/.test(raw)) {
                    MadWire.call(wrapper, 'setMesAno', ['', raw]);
                } else if (/^\d{1,2}$/.test(raw)) {
                    var mm2 = ('0' + raw).slice(-2);
                    MadWire.call(wrapper, 'setProp', ['mes', mm2]);
                }
            }
            @endif
        });

        @if($hasFilter)
        // ── Realce visual da categoria/serie ativa ──────────────────────
        function __madHighlightActive() {
            try {
                // Limpa highlight anterior
                chart.dispatchAction({ type: 'downplay' });

                // Resolve indice da categoria ativa
                var activeIdx = -1;
                if (__FILTER.mode === 'period') {
                    // Monta string esperada: 'YYYY-MM' ou 'YYYY' conforme rawCategories
                    var target = '';
                    if (__FILTER.activeMes && __FILTER.activeAno) {
                        target = __FILTER.activeAno + '-' + ('0' + __FILTER.activeMes).slice(-2);
                    } else if (__FILTER.activeAno) {
                        target = __FILTER.activeAno;
                    } else if (__FILTER.activeMes) {
                        target = ('0' + __FILTER.activeMes).slice(-2);
                    }
                    if (target) {
                        for (var i = 0; i < __FILTER.rawCategories.length; i++) {
                            var raw = String(__FILTER.rawCategories[i]);
                            // Match exact or starts-with (YYYY-MM-DD vs YYYY-MM)
                            if (raw === target || raw.indexOf(target) === 0) {
                                activeIdx = i; break;
                            }
                        }
                    }
                } else if (__FILTER.activeValue !== null && __FILTER.activeValue !== '') {
                    for (var j = 0; j < __FILTER.rawCategories.length; j++) {
                        if (String(__FILTER.rawCategories[j]) === String(__FILTER.activeValue)) {
                            activeIdx = j; break;
                        }
                    }
                }
                if (activeIdx >= 0) {
                    chart.dispatchAction({
                        type: 'highlight',
                        dataIndex: activeIdx
                    });
                    // Marca container pra CSS opcional (ex: badge "filtrado")
                    el.dataset.madFiltered = '1';
                } else {
                    delete el.dataset.madFiltered;
                }
            } catch (e) { /* silencia */ }
        }
        __madHighlightActive();
        @endif
        @endif

        // ── Glue de resize/tema com CLEANUP ──────────────────────────────
        // O morph do wire re-executa este script a cada re-render: sem
        // cleanup, listeners globais e observers acumulavam sem limite e
        // chamavam resize() em charts ja descartados.
        if (!window.__madChartCleanup) window.__madChartCleanup = {};
        if (window.__madChartCleanup[NAME]) {
            try { window.__madChartCleanup[NAME](); } catch {}
        }
        const __cleanupFns = [];
        const __safeResize = () => { try { chart.resize(); } catch {} };

        if (window.ResizeObserver) {
            const target = el.closest('.mad-card') || el.parentElement || el;
            // Guard: chart.resize() pode alterar o tamanho do pai e re-disparar
            // o observer (2 charts no mesmo grid se realimentam) — só resize
            // quando o tamanho realmente mudou, senão loop infinito até OOM.
            let __lastW = -1, __lastH = -1;
            const ro = new ResizeObserver((entries) => {
                const r = entries[0].contentRect;
                if (Math.abs(r.width - __lastW) < 1 && Math.abs(r.height - __lastH) < 1) return;
                __lastW = r.width; __lastH = r.height;
                __safeResize();
            });
            ro.observe(target);
            __cleanupFns.push(() => ro.disconnect());
        } else {
            const onWinResize = () => __safeResize();
            window.addEventListener('resize', onWinResize);
            __cleanupFns.push(() => window.removeEventListener('resize', onWinResize));
        }

        // Auto-height: aguarda layout estabilizar e faz resize
        if (el.style.height === '100%') {
            setTimeout(__safeResize, 50);
        }

        const onTab    = () => setTimeout(__safeResize, 80);
        const onDrawer = (e) => { if (e && e.detail && e.detail.action === 'open') setTimeout(__safeResize, 120); };
        const onModal  = (e) => { if (e && e.detail && e.detail.action === 'open') setTimeout(__safeResize, 120); };
        document.addEventListener('shown.bs.tab', onTab);
        window.addEventListener('maddrawer', onDrawer);
        window.addEventListener('madmodal', onModal);
        __cleanupFns.push(() => {
            document.removeEventListener('shown.bs.tab', onTab);
            window.removeEventListener('maddrawer', onDrawer);
            window.removeEventListener('madmodal', onModal);
        });

        // Toggle de tema em runtime: re-inicializa o chart no tema novo
        // (antes ficava preso no tema lido no init).
        const themeMo = new MutationObserver(() => {
            const nowDark = document.documentElement.getAttribute('data-theme') === 'dark';
            if (nowDark === isDark) return;
            try { window.__madChartCleanup[NAME] && window.__madChartCleanup[NAME](); } catch {}
            delete el.dataset.echartInited;
            initChart();
        });
        themeMo.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
        __cleanupFns.push(() => themeMo.disconnect());

        window.__madChartCleanup[NAME] = function () {
            __cleanupFns.forEach(fn => { try { fn(); } catch {} });
            window.__madChartCleanup[NAME] = null;
        };
    }

    function __madBoot(attempt) {
        if (typeof echarts === 'undefined') {
            // echarts pode carregar DEPOIS deste script (dashboard injetado
            // via AJAX com <script defer>): tenta por ~10s antes de desistir
            // — antes desistia silenciosamente e o chart nunca renderizava.
            if ((attempt || 0) < 40) {
                setTimeout(function () { __madBoot((attempt || 0) + 1); }, 250);
            } else {
                console.error('[mad-db-chart] echarts.min.js não está carregado.');
            }
            return;
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initChart);
        } else {
            initChart();
        }
    }
    __madBoot(0);
})();
</script>
@endif
