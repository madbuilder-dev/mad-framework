@php
    $label      = $label      ?? '';
    $value      = $value      ?? '';
    $icon       = $icon       ?? null;
    $trend      = $trend      ?? null;
    $trendLabel = $trendLabel ?? null;
    // 'up'|'down'|'flat' — use computeDelta()['trend']; dispensa sniff do label.
    $trendDirection = $trendDirection ?? null;
    // true = metrica onde SUBIR e ruim (custo, churn): inverte so a COR.
    $trendInvert    = filter_var($trendInvert ?? false, FILTER_VALIDATE_BOOL);
    $variant    = $variant    ?? 'default';
    $iconColor  = $iconColor  ?? null;
    $iconBg     = $iconBg     ?? null;
    // Linha de contexto sob o valor ("R$ 5,1M previsto", "2 vencem esta semana").
    $context    = trim((string) ($context ?? ''));
    $class      = $class      ?? '';
    $style      = $style      ?? '';
    // click-filter="campo=valor" / click-target="Tela" — ver Mad\Ui\MadKpi.
    $__click    = \Mad\Ui\MadKpi::click($clickFilter ?? null, $clickTarget ?? null, (string) $label);

    $iconColors = [
        'default' => ['bg' => 'color-mix(in srgb,var(--mad-primary) 8%,transparent)',  'fg' => 'var(--mad-primary)'],
        'success' => ['bg' => 'color-mix(in srgb,var(--mad-success) 10%,transparent)', 'fg' => 'var(--mad-success)'],
        'warning' => ['bg' => 'color-mix(in srgb,var(--mad-warning) 10%,transparent)', 'fg' => 'var(--mad-warning)'],
        'error'   => ['bg' => 'color-mix(in srgb,var(--mad-danger)  10%,transparent)', 'fg' => 'var(--mad-danger)'],
        'info'    => ['bg' => 'color-mix(in srgb,var(--mad-info)    10%,transparent)', 'fg' => 'var(--mad-info)'],
    ];
    $c = $iconColors[$variant] ?? $iconColors['default'];
    if ($iconColor) { $c['fg'] = $iconColor; }
    if ($iconBg)    { $c['bg'] = $iconBg; }

    // Direcao: prop explicita vence; fallback legado sniffa o 1o char do
    // label (fragil com label localizado — prefira :trend-direction).
    if (in_array($trendDirection, ['up', 'down', 'flat'], true)) {
        $trendUp   = $trendDirection === 'up';
        $trendDown = $trendDirection === 'down';
    } else {
        $trendUp   = $trend && str_starts_with($trend, '+');
        $trendDown = $trend && (str_starts_with($trend, '-') || str_starts_with($trend, '−'));
    }
    // Cor = bom/ruim (invertivel); icone = direcao real, nunca inverte.
    $trendClass = $trendUp   ? ($trendInvert ? 'mad-stat-change-down' : 'mad-stat-change-up')
                : ($trendDown ? ($trendInvert ? 'mad-stat-change-up' : 'mad-stat-change-down') : '');
    $trendIcon  = $trendUp ? 'trending-up' : ($trendDown ? 'trending-down' : 'minus');
@endphp

<div class="mad-stat{{ $__click['clickable'] ? ' mad-kpi-clickable' : '' }} {{ $class }}"{!! $__click['attrs'] !!} @if($style) style="{{ $style }}" @endif>
    <div class="mad-stat-body">

        <div class="mad-stat-main">
            <p class="mad-stat-label" title="{{ $label }}">{{ $label }}</p>
            <p class="mad-stat-value" title="{{ $value }}"><span class="mad-kpi-num" style="--mad-kpi-len:{{ \Mad\Ui\MadKpi::valueWidth((string) $value) }}">{{ $value }}</span></p>
            @if($context !== '')
                <p class="mad-stat-context">{{ $context }}</p>
            @endif

            @if($trend)
                <div class="mad-stat-change {{ $trendClass }}" style="display:inline-flex;align-items:center;gap:4px;">
                    <i data-lucide="{{ $trendIcon }}" style="width:13px;height:13px;"></i>
                    <span style="font-weight:600;">{{ $trend }}</span>
                    @if($trendLabel)
                        <span style="color:var(--mad-text-subtle);">{{ $trendLabel }}</span>
                    @endif
                </div>
            @endif
        </div>

        @if($icon)
            <div class="mad-stat-icon" style="background:{{ $c['bg'] }};">
                <i data-lucide="{{ $icon }}" class="mad-stat-icon-glyph" style="color:{{ $c['fg'] }};"></i>
            </div>
        @endif

    </div>
</div>
