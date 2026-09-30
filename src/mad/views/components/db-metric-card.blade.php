@php
    $model      = $model      ?? '';
    $database   = $database   ?? (defined('MAIN_DATABASE') ? MAIN_DATABASE : 'business');
    $field      = $field      ?? 'id';
    $total      = $total      ?? 'count';
    $query      = $query      ?? null;  // Eloquent/Query Builder (novo padrão) — dispensa model/database
    $filters    = $filters    ?? [];
    $label      = $label      ?? '';
    $icon       = $icon       ?? null;
    $variant    = $variant    ?? 'default';
    $iconColor  = $iconColor  ?? null;
    $iconBg     = $iconBg     ?? null;
    $format     = $format     ?? '';
    $trend      = $trend      ?? null;
    $trendLabel = $trendLabel ?? null;
    $trendDirection = $trendDirection ?? null;   // 'up'|'down'|'flat' (computeDelta)
    $trendInvert    = $trendInvert    ?? false;  // subir = ruim (custo/churn)
    $class      = $class      ?? '';
    $style      = $style      ?? '';
    $noDebug    = $noDebug    ?? false;

    // Fonte → builder (100% Query Builder). :query | :filters | model.
    // O QuerySource::aggregate roda direto sobre o builder (entity/connection vêm
    // dele); :filters é aplicado no builder via applyArrayFilters.
    // Erro ao MONTAR o builder (model inexistente, :filters mal formado...).
    // Guardado p/ virar $__debugError la' embaixo: engolir isso em silencio fazia
    // o card render '—' sem pista nenhuma, mesmo com debug ligado.
    $__buildError = null;
    if (!\Mad\Database\QuerySource::isQuery($query) && $model) {
        try {
            $__m  = class_exists($model) ? $model : \Mad\Form\ModelOptionsLoader::resolveModelClass($model);
            $__qb = $__m::query();
            if (!empty($filters)) {
                \Mad\Database\QuerySource::applyArrayFilters($__qb, $filters);
            }
            $query = $__qb;
        } catch (\Throwable $e) {
            $query = null;
            $__buildError = $e->getMessage();
        }
    }

    // Execute aggregate query
    $computedValue  = '—';
    $__debugSql     = null;
    $__debugBinds   = [];
    $__debugError   = $__buildError;
    if (\Mad\Database\QuerySource::isQuery($query)) {
        try {
            [$rawValue, $__debugSql, $__debugBinds] = \Mad\Database\QuerySource::aggregate(
                $query, $total, $field
            );

            // Format value via MadChartFormatter (currency, numeric, percent,
            // abbreviate, integer, datas...). Mantem aliases legados money:/number:.
            if ($format === '') {
                $computedValue = (string) $rawValue;
            } elseif (str_starts_with($format, 'money:')) {
                // Legado: money:R$ → currency:R$
                $fmt = 'currency:' . substr($format, 6);
                $computedValue = (\Mad\Chart\MadChartFormatter::make($fmt))($rawValue);
            } elseif (str_starts_with($format, 'number:')) {
                // Legado: number:N → numeric:N
                $fmt = 'numeric:' . substr($format, 7);
                $computedValue = (\Mad\Chart\MadChartFormatter::make($fmt))($rawValue);
            } else {
                $computedValue = (\Mad\Chart\MadChartFormatter::make($format))($rawValue);
            }
        } catch (\Throwable $e) {
            $computedValue = '—';
            $__debugError  = $e->getMessage();
        }
    }

    // Debug SQL panel — gate único (general.debug do app E APP_DEBUG)
    $__debugEnabled = !$noDebug && \Mad\Support\MadDebug::sqlPanel();

    $__debugInlined = null;
    if ($__debugEnabled && $__debugSql) {
        $__debugInlined = $__debugSql;
        foreach ((array) ($__debugBinds ?? []) as $k => $v) {
            $q = is_null($v) ? 'NULL'
                : (is_numeric($v) ? (string) $v
                : "'" . str_replace("'", "''", (string) $v) . "'");
            // addcslashes: '$'/'\' no valor do bind viravam backreference do
            // preg_replace e corrompiam o SQL de debug.
            $qq = addcslashes($q, '\\$');
            if (is_int($k)) {
                $__debugInlined = preg_replace('/\?/', $qq, $__debugInlined, 1);
            } else {
                $__debugInlined = preg_replace('/:' . preg_quote(ltrim((string)$k, ':'), '/') . '\b/', $qq, $__debugInlined);
            }
        }
    }
    $__debugChartName = 'metric_' . substr(md5((string)($label ?? '') . $field . $total), 0, 8);

    // Visual delegado ao metric-card (fonte unica de markup/trend/cores —
    // era copy-paste integral e ja tinha driftado).
    $__cardData = [
        'label'          => $label,
        'value'          => $computedValue,
        'icon'           => $icon,
        'trend'          => $trend,
        'trendLabel'     => $trendLabel,
        'trendDirection' => $trendDirection,
        'trendInvert'    => $trendInvert,
        'variant'        => $variant,
        'iconColor'      => $iconColor,
        'iconBg'         => $iconBg,
        'class'          => $class,
        'style'          => $style,
    ];
    $__hasDebugOut = $__debugEnabled && ($__debugSql || $__debugError);
@endphp

@if($__hasDebugOut)
{{-- Wrapper unico em modo debug — o card NAO pode virar 2+ children do grid. --}}
<div class="mad-db-metric-wrap">
    @include('components.metric-card', $__cardData)

    @if($__debugError)
        <div style="margin-top:.5rem;padding:.4rem .55rem;background:color-mix(in srgb,var(--mad-danger,#dc3545) 6%,transparent);border:1px solid color-mix(in srgb,var(--mad-danger,#dc3545) 25%,transparent);border-radius:6px;font-size:.7rem;color:var(--mad-danger,#dc3545);">
            <strong style="font-weight:600;">{{ mad_t('mad.chart.debug.error') }}:</strong> {{ $__debugError }}
        </div>
    @endif

    @if($__debugSql)
        @include('components.partials.db-chart-debug', [
            'debugSql'     => $__debugSql,
            'debugInlined' => $__debugInlined,
            'debugBinds'   => $__debugBinds,
            'chartName'    => $__debugChartName,
        ])
    @endif
</div>
@else
@include('components.metric-card', $__cardData)
@endif
