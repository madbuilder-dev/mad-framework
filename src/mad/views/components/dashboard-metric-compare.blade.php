{{-- Wrapper de mad-db-metric-card que computa delta vs periodo anterior --}}
{{-- e injeta como trend automaticamente.                                     --}}
{{-- Uso (Eloquent Builder — dispensa model/database):                       --}}
{{--   <mad-dashboard-metric-compare :current-query="$builderAtual"           --}}
{{--      :compare-query="$builderAnterior" label="..." total="count" />      --}}
@php
    $model           = $model           ?? '';
    $database        = $database        ?? (defined('MAIN_DATABASE') ? MAIN_DATABASE : 'business');
    $field           = $field           ?? 'id';
    $total           = $total           ?? 'count';
    $currentQuery    = $currentQuery    ?? null;  // Eloquent/Query Builder (novo padrão)
    $compareQuery    = $compareQuery    ?? null;
    $label           = $label           ?? '';
    $icon            = $icon            ?? null;
    $variant         = $variant         ?? 'default';
    $iconColor       = $iconColor       ?? null;
    $iconBg          = $iconBg          ?? null;
    $format          = $format          ?? '';
    $compareLabel    = $compareLabel    ?? mad_t('mad.dashf.vs_previous');
    $trendInvert     = $trendInvert     ?? false;  // subir = ruim (custo/churn)
    $class           = $class           ?? '';

    // ── Helper: SQL com binds inlined (debug only) ─────────────────────────
    $__inline = function (string $sql, array $binds): string {
        foreach ($binds as $k => $v) {
            $q = is_null($v) ? 'NULL'
                : (is_numeric($v) ? (string) $v
                : "'" . str_replace("'", "''", (string) $v) . "'");
            // addcslashes: '$'/'\' no bind viravam backreference e corrompiam
            // o SQL de debug.
            $qq = addcslashes($q, '\\$');
            if (is_int($k)) {
                $sql = preg_replace('/\?/', $qq, $sql, 1);
            } else {
                $sql = preg_replace('/:' . preg_quote(ltrim((string)$k, ':'), '/') . '\b/', $qq, $sql);
            }
        }
        return $sql;
    };

    // ── Fallback legado: model= sem :current-query/:compare-query ─────────
    // O proposito do componente e o trend; sem builders ele renderizava o
    // valor e NUNCA mostrava trend, sem erro. Deriva as janelas do host
    // MadDashboard (baseQuery + comparePeriodQuery).
    if ($model !== ''
        && (!\Mad\Database\QuerySource::isQuery($currentQuery) || !\Mad\Database\QuerySource::isQuery($compareQuery))) {
        $__host = \Mad\Component\MadRenderContext::getComponent();
        if ($__host instanceof \Mad\Dashboard\MadDashboard) {
            try {
                if (!\Mad\Database\QuerySource::isQuery($currentQuery)) {
                    $currentQuery = $__host->baseQuery($model);
                }
                if (!\Mad\Database\QuerySource::isQuery($compareQuery)) {
                    $compareQuery = $__host->comparePeriodQuery($model);
                }
            } catch (\Throwable $e) { /* mantem o que veio */ }
        }
    }

    // ── Computa delta executando 2 queries de agregacao ───────────────────
    $delta            = ['label' => null, 'trend' => 'flat'];
    $__currentSql     = null; $__currentBinds = []; $__currentError = null;
    $__compareSql     = null; $__compareBinds = []; $__compareError = null;

    $__hasQueries  = \Mad\Database\QuerySource::isQuery($currentQuery) && \Mad\Database\QuerySource::isQuery($compareQuery);

    if ($__hasQueries) {
        try {
            // F-NATIVE: agregação via QuerySource (Query Builder)
            // O table/entity é derivado do próprio builder pelo QuerySource.
            $entity = null;

            $aggregate = function ($source, &$sqlOut, &$bindsOut, &$errOut)
                use ($database, $entity, $total, $field): float
            {
                try {
                    [$value, $sqlOut, $bindsOut] = \Mad\Database\QuerySource::aggregate(
                        $source, $total, $field, $database, $entity
                    );
                    return $value;
                } catch (\Throwable $e) {
                    $errOut = $e->getMessage();
                    return 0.0;
                }
            };

            $current  = $aggregate($currentQuery, $__currentSql, $__currentBinds, $__currentError);
            $previous = $aggregate($compareQuery, $__compareSql, $__compareBinds, $__compareError);

            $delta = \Mad\Dashboard\MadDashboard::computeDelta($current, $previous);
        } catch (\Throwable $e) {
            $delta = ['label' => null, 'trend' => 'flat'];
        }
    }

    $trendValue = $delta['label'] ?? null;

    // ── Debug habilitado? (general.debug do app E APP_DEBUG) ───────────────
    $__debugEnabled = \Mad\Support\MadDebug::sqlPanel();

    $__currentInlined = ($__debugEnabled && $__currentSql) ? $__inline($__currentSql, $__currentBinds) : null;
    $__compareInlined = ($__debugEnabled && $__compareSql) ? $__inline($__compareSql, $__compareBinds) : null;

    $__cmpName = 'metric_cmp_' . substr(md5((string) $label . $field . $total), 0, 8);
@endphp

{{-- Wrapper unico — evita virar 2 children do grid do dashboard --}}
<div class="mad-dashboard-metric-compare">
    <mad-db-metric-card
        :model="$model"
        :database="$database"
        :field="$field"
        :total="$total"
        :query="$currentQuery"
        :label="$label"
        :icon="$icon"
        :variant="$variant"
        :icon-color="$iconColor"
        :icon-bg="$iconBg"
        :format="$format"
        :trend="$trendValue"
        :trend-label="$compareLabel"
        :trend-direction="$delta['trend'] ?? null"
        :trend-invert="$trendInvert"
        :class="$class"
        :no-debug="true" />

    @if($__debugEnabled && ($__currentSql || $__compareSql || $__currentError || $__compareError))
        <div style="margin-top:.5rem;border:1px solid var(--mad-border,#e5e7eb);border-radius:8px;background:var(--mad-surface-muted,var(--mad-surface,#fafbfc));overflow:hidden;">

            @if($__currentError)
                <div style="padding:.4rem .55rem;background:color-mix(in srgb,var(--mad-danger,#dc3545) 6%,transparent);border-bottom:1px solid color-mix(in srgb,var(--mad-danger,#dc3545) 25%,transparent);font-size:.7rem;color:var(--mad-danger,#dc3545);">
                    <strong style="font-weight:600;">{{ mad_t('mad.chart.debug.error') }} ({{ mad_t('mad.dashf.period_current') }}):</strong> {{ $__currentError }}
                </div>
            @endif

            @if($__compareError)
                <div style="padding:.4rem .55rem;background:color-mix(in srgb,var(--mad-danger,#dc3545) 6%,transparent);border-bottom:1px solid color-mix(in srgb,var(--mad-danger,#dc3545) 25%,transparent);font-size:.7rem;color:var(--mad-danger,#dc3545);">
                    <strong style="font-weight:600;">{{ mad_t('mad.chart.debug.error') }} ({{ mad_t('mad.dashf.period_previous') }}):</strong> {{ $__compareError }}
                </div>
            @endif

            @if($__currentSql)
                @include('components.partials.db-chart-debug', [
                    'debugSql'     => $__currentSql,
                    'debugInlined' => $__currentInlined,
                    'debugBinds'   => $__currentBinds,
                    'chartName'    => $__cmpName . ' — ' . mad_t('mad.dashf.period_current'),
                ])
            @endif

            @if($__compareSql)
                @include('components.partials.db-chart-debug', [
                    'debugSql'     => $__compareSql,
                    'debugInlined' => $__compareInlined,
                    'debugBinds'   => $__compareBinds,
                    'chartName'    => $__cmpName . ' — ' . mad_t('mad.dashf.period_previous'),
                ])
            @endif
        </div>
    @endif
</div>
