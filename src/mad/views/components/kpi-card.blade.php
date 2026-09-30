{{-- <mad-kpi-card> — KPI estilo plataforma de BI: valor grande, pill de
     variação, sparkline SVG (gerada em PHP, zero JS) e barra de progresso
     opcional (estilo Power BI). Aditivo ao <mad-metric-card> — não o substitui.

     Props:
       label            string   rótulo
       value            string   valor JÁ formatado (vazio = calcula da query)
       value-color      string   cor do valor (default herda)
       :current-query   object   Eloquent/Query Builder do período atual
       :compare-query   object   idem do período anterior (liga a variação)
       model            string   model p/ derivar as queries do dashboard host
       database         string   conexão (default MAIN_DATABASE)
       field            string   coluna agregada (default id)
       total            string   count | sum | avg | min | max (default count)
       format           string   currency:R$ | percent:1 | integer | numeric:2
       compare-mode     string   auto | mtd = mesmo trecho do mês anterior (default auto)
       :progress        num|null 0..100 → barra de progresso
       progress-color   string   cor da barra (default var(--mad-primary))
       goal             string   legenda sob a barra ("Meta 20%")
       :spark           array    série numérica p/ sparkline
       spark-color      string   cor da sparkline (default #2563EB)
       trend            string   texto da variação ("+12,3%")
       trend-label      string   legenda ("vs mês anterior")
       trend-direction  string   up | down | flat
       :trend-invert    bool     true = subir é RUIM (inverte só a cor)
       help             string   tooltip ⓘ de origem/fórmula
       icon             string   ícone lucide opcional
       variant          string   solid (card) | outline (borda colorida)
       sql              string   SQL raw → painel debug quando general.debug=1
       class / style --}}
@php
    $label      = $label ?? '';
    $value      = $value ?? '';
    $valueColor = $valueColor ?? null;
    $progress   = isset($progress) && $progress !== '' && $progress !== null ? (float) $progress : null;
    $progressColor = $progressColor ?? 'var(--mad-primary)';
    $goal       = $goal ?? null;
    $spark      = is_array($spark ?? null) ? $spark : null;
    $sparkColor = $sparkColor ?? '#2563EB';
    $trend      = $trend ?? null;
    $trendLabel = $trendLabel ?? null;
    $trendDirection = $trendDirection ?? null;
    $trendInvert = filter_var($trendInvert ?? false, FILTER_VALIDATE_BOOL);
    $help       = $help ?? null;
    $icon       = $icon ?? null;
    $variant    = ($variant ?? 'solid') === 'outline' ? 'outline' : 'solid';
    $sql        = $sql ?? null;
    $class      = $class ?? '';
    $style      = $style ?? '';

    // ── Fonte de dados própria (aditivo: card sem query segue estático) ────
    $currentQuery = $currentQuery ?? null;   // Eloquent/Query Builder
    $compareQuery = $compareQuery ?? null;
    $model        = $model        ?? '';
    $database     = $database     ?? (defined('MAIN_DATABASE') ? MAIN_DATABASE : 'business');
    $field        = $field        ?? 'id';
    $total        = $total        ?? 'count';
    $format       = $format       ?? '';
    $compareMode  = $compareMode  ?? 'auto';

    // ── Helper: SQL com binds inlined (debug only) ────────────────────────
    // Copia do dashboard-metric-compare: addcslashes evita que '$'/'\' no
    // bind virem backreference do preg_replace e corrompam o SQL de debug.
    $__inline = function (string $s, array $binds): string {
        foreach ($binds as $k => $v) {
            $q = is_null($v) ? 'NULL'
                : (is_numeric($v) ? (string) $v
                : "'" . str_replace("'", "''", (string) $v) . "'");
            $qq = addcslashes($q, '\\$');
            if (is_int($k)) {
                $s = preg_replace('/\?/', $qq, $s, 1);
            } else {
                $s = preg_replace('/:' . preg_quote(ltrim((string) $k, ':'), '/') . '\b/', $qq, $s);
            }
        }
        return $s;
    };

    $__kpiError = null;

    // ── model= sem :current-query/:compare-query ──────────────────────────
    // Dentro de um MadDashboard as duas janelas saem do host (mesma receita do
    // <mad-dashboard-metric-compare>). Fora dele, o card ainda agrega o model
    // inteiro — mas sem janela de período não há comparação (fica sem trend).
    if ($model !== ''
        && (!\Mad\Database\QuerySource::isQuery($currentQuery) || !\Mad\Database\QuerySource::isQuery($compareQuery))) {
        $__host = \Mad\Component\MadRenderContext::getComponent();
        if ($__host instanceof \Mad\Dashboard\MadDashboard) {
            try {
                if (!\Mad\Database\QuerySource::isQuery($currentQuery)) {
                    $currentQuery = $__host->baseQuery($model);
                }
                if (!\Mad\Database\QuerySource::isQuery($compareQuery)) {
                    $compareQuery = $__host->comparePeriodQuery($model, ['compare' => $compareMode]);
                }
            } catch (\Throwable $e) { /* mantem o que veio */ }
        } elseif (!\Mad\Database\QuerySource::isQuery($currentQuery)) {
            try {
                $__m = class_exists($model) ? $model : \Mad\Form\ModelOptionsLoader::resolveModelClass($model);
                $currentQuery = $__m::query();
            } catch (\Throwable $e) {
                $__kpiError = $e->getMessage();
            }
        }
    }

    // ── Agregação: valor atual + delta vs anterior ────────────────────────
    $__curSql = null; $__curBinds = [];
    $__cmpSql = null; $__cmpBinds = [];
    $__current = null;

    if (\Mad\Database\QuerySource::isQuery($currentQuery)) {
        try {
            [$__current, $__curSql, $__curBinds] = \Mad\Database\QuerySource::aggregate(
                $currentQuery, $total, $field, $database, null
            );
        } catch (\Throwable $e) {
            $__current  = null;
            $__kpiError = $__kpiError ?? $e->getMessage();
        }
    }

    // value= do autor SEMPRE vence — a query só preenche o que ficou vazio.
    if (trim((string) $value) === '') {
        if ($__current !== null) {
            if ($format === '') {
                $value = (string) $__current;
            } elseif (str_starts_with($format, 'money:')) {
                // Legado: money:R$ → currency:R$
                $value = (string) (\Mad\Chart\MadChartFormatter::make('currency:' . substr($format, 6)))($__current);
            } elseif (str_starts_with($format, 'number:')) {
                // Legado: number:N → numeric:N
                $value = (string) (\Mad\Chart\MadChartFormatter::make('numeric:' . substr($format, 7)))($__current);
            } else {
                $value = (string) (\Mad\Chart\MadChartFormatter::make($format))($__current);
            }
        } elseif ($__kpiError !== null || \Mad\Database\QuerySource::isQuery($currentQuery)) {
            $value = '—';
        }
    }

    if ($__current !== null && \Mad\Database\QuerySource::isQuery($compareQuery)) {
        try {
            [$__previous, $__cmpSql, $__cmpBinds] = \Mad\Database\QuerySource::aggregate(
                $compareQuery, $total, $field, $database, null
            );
            $__delta = \Mad\Dashboard\MadDashboard::computeDelta((float) $__current, (float) $__previous);
            // trend/trend-direction do autor vencem — só preenche o que faltou.
            if ($trend === null)          $trend          = $__delta['label'];
            if ($trendDirection === null) $trendDirection = $__delta['trend'];
        } catch (\Throwable $e) {
            $__kpiError = $__kpiError ?? $e->getMessage();
        }
    }

    // ── Debug (general.debug do app E APP_DEBUG) ──────────────────────────
    // Gate único em MadDebug: o painel imprime o SQL com valores reais, então
    // não pode nascer ligado num app publicado.
    $kDebug = \Mad\Support\MadDebug::sqlPanel();
    // sql= do autor vence; sem ele, mostra o SQL da query atual E o da janela
    // de comparação — é a metade que se precisa ler pra auditar auto × mtd.
    if ($kDebug && (!is_string($sql) || trim($sql) === '') && $__curSql) {
        $sql = $__inline($__curSql, $__curBinds);
        if ($__cmpSql) {
            $sql .= "\n\n-- período anterior (" . ($compareMode === 'mtd' ? 'mtd: mesmo trecho do mês anterior' : 'auto') . "):\n"
                  . $__inline($__cmpSql, $__cmpBinds);
        }
    }
    $kDebug = $kDebug && ((is_string($sql) && trim($sql) !== '') || $__kpiError !== null);

    $pillClass = 'mad-kpi-pill-flat';
    $arrow     = '→';
    if ($trend !== null && in_array($trendDirection, ['up', 'down'], true)) {
        $up        = $trendDirection === 'up';
        $good      = $trendInvert ? ! $up : $up;
        $pillClass = $good ? 'mad-kpi-pill-up' : 'mad-kpi-pill-down';
        $arrow     = $up ? '↑' : '↓';
    }
    // NUNCA ltrim com char list multibyte ('−' compartilha bytes com '∞')
    $trendText = $trend !== null ? preg_replace('/^[+\-−]+/u', '', (string) $trend) : null;

    // Sparkline: polyline normalizada num viewBox 96x36 (padding 2)
    $sparkLine = null;
    $sparkArea = null;
    $vals = $spark !== null ? array_values(array_map('floatval', $spark)) : [];
    if (count($vals) >= 2) {
        $sMin = min($vals); $sMax = max($vals);
        $range = ($sMax - $sMin) ?: 1.0;
        $w = 96.0; $h = 36.0; $pad = 2.0;
        $stepX = ($w - 2 * $pad) / (count($vals) - 1);
        $pts = [];
        foreach ($vals as $i => $v) {
            $x = $pad + $i * $stepX;
            $y = $pad + (1 - (($v - $sMin) / $range)) * ($h - 2 * $pad);
            $pts[] = round($x, 1) . ',' . round($y, 1);
        }
        $sparkLine = implode(' ', $pts);
        $sparkArea = $sparkLine . ' ' . round($w - $pad, 1) . ',' . $h . ' ' . $pad . ',' . $h;
    }

    $pct = $progress !== null ? max(0, min(100, $progress)) : null;
@endphp
<div class="mad-card mad-kpi-card mad-kpi-{{ $variant }} {{ $class }}"
     @if($variant === 'outline' || $style) style="{{ $variant === 'outline' ? '--mad-kpi-accent:' . ($valueColor ?: 'var(--mad-primary)') . ';' : '' }}{{ $style }}" @endif>
    <div class="mad-kpi">
        <div class="mad-kpi-top">
            {{-- help FORA do label: o label tem overflow:hidden (ellipsis)
                 e cliparia a tooltip absoluta do ⓘ --}}
            <span style="display:inline-flex;align-items:center;min-width:0;gap:6px">
                @if($icon)<i data-lucide="{{ $icon }}" class="mad-kpi-icon"></i>@endif
                <span class="mad-kpi-label" title="{{ $label }}">{{ $label }}</span>@if($help)<span class="mad-kpi-help" tabindex="0">ⓘ<span class="mad-kpi-help-tip">{{ $help }}</span></span>@endif
            </span>
            @if($trendText !== null)
                <span class="mad-kpi-pill {{ $pillClass }}"
                    title="{{ $trendLabel ?? '' }}">{{ $arrow }} {{ $trendText }}</span>
            @endif
        </div>
        <div class="mad-kpi-value" @if($valueColor) style="color:{{ $valueColor }}" @endif>{{ $value }}</div>
        @if($pct !== null)
            <div class="mad-kpi-progress" role="progressbar" aria-valuenow="{{ $pct }}" aria-valuemin="0" aria-valuemax="100">
                <span style="width:{{ $pct }}%;background:{{ $progressColor }}"></span>
            </div>
        @endif
        @if($goal)
            <div class="mad-kpi-foot">{{ $goal }}</div>
        @elseif($trendLabel)
            <div class="mad-kpi-foot">{{ $trendLabel }}</div>
        @endif
        @if($sparkLine)
            <div class="mad-kpi-spark" aria-hidden="true">
                <svg viewBox="0 0 96 36" preserveAspectRatio="none">
                    <polygon points="{{ $sparkArea }}" fill="{{ $sparkColor }}" opacity="0.12"></polygon>
                    <polyline points="{{ $sparkLine }}" fill="none" stroke="{{ $sparkColor }}"
                        stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"
                        vector-effect="non-scaling-stroke"></polyline>
                </svg>
            </div>
        @endif
    </div>
    @if($kDebug)
        @if($__kpiError)
            <div style="margin-top:.5rem;padding:.4rem .55rem;background:color-mix(in srgb,var(--mad-danger,#dc3545) 6%,transparent);border:1px solid color-mix(in srgb,var(--mad-danger,#dc3545) 25%,transparent);border-radius:6px;font-size:.7rem;color:var(--mad-danger,#dc3545);">
                <strong style="font-weight:600;">{{ mad_t('mad.chart.debug.error') }}:</strong> {{ $__kpiError }}
            </div>
        @endif
        @if(is_string($sql) && trim($sql) !== '')
            @include('components.partials.db-chart-debug', [
                'debugSql'     => trim($sql),
                'debugInlined' => '',
                'debugBinds'   => [],
                'chartName'    => $label,
            ])
        @endif
    @endif
</div>
