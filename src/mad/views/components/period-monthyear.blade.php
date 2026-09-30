{{-- <mad-period-monthyear> — Segmented control for month/year period selection. --}}
{{-- Auto-reads month/year from the active MadDashboard subclass via            --}}
{{-- MadRenderContext. Includes inline popover for custom month+year selection. --}}
{{--                                                                            --}}
{{-- Props:                                                                     --}}
{{--   variant        string   'default' (gray pill) | 'outline' | 'dark'       --}}
{{--   size           string   'sm' | 'md' (default) | 'lg'                     --}}
{{--   submit         string   'onShow' — action invoked after preset/custom    --}}
{{--   presets        array    ['all','month','year','prior-year','custom']     --}}
{{--   popover-align  string   'left' | 'right' — popover anchor side           --}}
{{--   labels         array    Override labels: ['all'=>'Todos','month'=>...]   --}}
{{--   wrap-form      bool     true (default). Quando false NAO renderiza form  --}}
{{--                           interno no popover Custom — Apply vira mad:click --}}
{{--                           direto (caller ja envolve em <mad-form>).        --}}
{{--                                                                            --}}
{{-- Minimal usage (inside a MadDashboard subclass view):                       --}}
{{--   <mad-period-monthyear />                                                 --}}
{{--                                                                            --}}
{{-- Full usage:                                                                --}}
{{--   <mad-period-monthyear variant="outline" size="md"                        --}}
{{--       :presets="['all', 'month', 'year', 'custom']"                        --}}
{{--       popover-align="right"                                                --}}
{{--       :labels="['all' => 'Todos', 'month' => 'Mês']" />                    --}}

@php
    use Mad\Component\MadRenderContext;
    $component = MadRenderContext::getComponent();

    $variant      = $variant      ?? 'default';
    $size         = $size         ?? 'md';
    $submit       = $submit       ?? 'onShow';
    $presets      = $presets      ?? ['all', 'month', 'year', 'prior-year', 'custom'];
    $popoverAlign = $popoverAlign ?? 'left';
    $labels       = $labels       ?? [];
    $wrapForm     = isset($wrapForm) ? !($wrapForm === false || $wrapForm === 'false' || $wrapForm === '0') : true;

    // Host state
    $month = (string) ($component->mes ?? '');
    $year  = (string) ($component->ano ?? '');

    $currentMonth = (string) date('m');
    $currentYear  = (string) date('Y');
    $priorYear    = (string) ((int) date('Y') - 1);

    $isCurrentMonth = ($month === $currentMonth && $year === $currentYear);
    $isCurrentYear  = ($month === '' && $year === $currentYear);
    $isPriorYear    = ($month === '' && $year === $priorYear);
    $isAll          = ($month === '' && $year === '');
    $isCustom       = !$isCurrentMonth && !$isCurrentYear && !$isPriorYear && !$isAll;

    // Select options (from host MadDashboard)
    $monthOptions = ($component && method_exists($component, 'getOpcoesMes')) ? $component->getOpcoesMes() : [];
    $yearOptions  = ($component && method_exists($component, 'getOpcoesAno')) ? $component->getOpcoesAno() : [];

    // Dynamic custom label (defaults via catalogo mad_t — nada hardcoded)
    $customLabel = $labels['custom'] ?? mad_t('mad.dashf.custom');
    if ($isCustom) {
        $m = $month !== '' && isset($monthOptions[$month]) ? $monthOptions[$month] : '';
        $y = $year  !== '' ? $year : '';
        $customLabel = trim($m . ' ' . $y) ?: ($labels['custom'] ?? mad_t('mad.dashf.custom'));
    }

    // Default labels (overridable via $labels prop)
    $lblAll       = $labels['all']        ?? mad_t('mad.dashf.all');
    $lblMonth     = $labels['month']      ?? mad_t('mad.dashf.month');
    $lblYear      = $labels['year']       ?? mad_t('mad.dashf.year');
    $lblPriorYear = $labels['prior-year'] ?? $priorYear;

    // Aba ATIVA mostra o período resolvido ("Mês · jul/2026", "Ano · 2026") —
    // sem isso, "Mês" selecionado ao lado do preset do ano anterior ("2025")
    // lê-se como um mês de 2025 e o usuário não sabe qual período está vendo.
    if ($isCurrentMonth) {
        $mName = (string) ($monthOptions[$month] ?? $monthOptions[(int) $month] ?? $month);
        $lblMonth .= ' · ' . mb_strtolower(mb_substr($mName, 0, 3)) . '/' . $currentYear;
    }
    if ($isCurrentYear) {
        $lblYear .= ' · ' . $currentYear;
    }

    // Click attrs (calls setMesAno on host)
    $attrSetAll       = 'data-mad-click="setMesAno(\'\', \'\')"';
    $attrSetMonth     = 'data-mad-click="setMesAno(\'' . $currentMonth . '\', \'' . $currentYear . '\')"';
    $attrSetYear      = 'data-mad-click="setMesAno(\'\', \'' . $currentYear . '\')"';
    $attrSetPriorYear = 'data-mad-click="setMesAno(\'\', \'' . $priorYear . '\')"';
    $attrToggleCustom = '@click="openCustom = !openCustom"';

    $hasCustom = in_array('custom', $presets, true);
    $popoverStyle = $popoverAlign === 'right'
        ? 'top:calc(100% + 8px);right:0;left:auto;'
        : 'top:calc(100% + 8px);left:0;right:auto;';
@endphp

@if ($hasCustom)
<div x-data="{ openCustom: false }" style="position:relative;display:inline-block;vertical-align:middle;">
@endif

    <mad-segmented :variant="$variant" :size="$size">
        @foreach ($presets as $preset)
            @if ($preset === 'all')
                <mad-segmented-item :active="$isAll" :attrs="$attrSetAll">{{ $lblAll }}</mad-segmented-item>
            @elseif ($preset === 'month')
                <mad-segmented-item :active="$isCurrentMonth" :attrs="$attrSetMonth">{{ $lblMonth }}</mad-segmented-item>
            @elseif ($preset === 'year')
                <mad-segmented-item :active="$isCurrentYear" :attrs="$attrSetYear">{{ $lblYear }}</mad-segmented-item>
            @elseif ($preset === 'prior-year')
                <mad-segmented-item :active="$isPriorYear" :attrs="$attrSetPriorYear">{{ $lblPriorYear }}</mad-segmented-item>
            @elseif ($preset === 'custom')
                <mad-segmented-item :active="$isCustom" :attrs="$attrToggleCustom">{{ $customLabel }}</mad-segmented-item>
            @endif
        @endforeach
    </mad-segmented>

@if ($hasCustom)
    <div class="mad-pmy-popover"
         x-show="openCustom" x-cloak
         @click.outside="openCustom = false"
         @keydown.escape.window="openCustom = false"
         style="position:absolute;{{ $popoverStyle }}z-index:50;background:var(--mad-surface,#fff);border:1px solid var(--mad-border,#e5e7eb);border-radius:8px;box-shadow:0 10px 25px -5px rgba(0,0,0,.10), 0 8px 10px -6px rgba(0,0,0,.05);padding:14px;min-width:240px;max-width:min(320px,calc(100vw - 24px));">
        <div style="font-size:11px;color:var(--mad-text-subtle,#6b7280);text-transform:uppercase;letter-spacing:.06em;font-weight:600;margin-bottom:10px;display:flex;justify-content:space-between;align-items:center;">
            <span>{{ $labels['popover-title'] ?? mad_t('mad.dashf.custom_period') }}</span>
            <button type="button" @click="openCustom = false" aria-label="{{ mad_t('mad.btn.close') }}"
                style="background:transparent;border:none;color:var(--mad-text-subtle,#6b7280);cursor:pointer;font-size:16px;line-height:1;padding:2px 6px;border-radius:4px;">×</button>
        </div>
        @if ($wrapForm)
            <mad-form :submit="$submit">
                <mad-form-grid :cols="1">
                    <mad-select-field name="mes" :label="$labels['month'] ?? mad_t('mad.dashf.month')" :items="$monthOptions" :placeholder="$labels['month-placeholder'] ?? mad_t('mad.dashf.all')" />
                    <mad-select-field name="ano" :label="$labels['year']  ?? mad_t('mad.dashf.year')"  :items="$yearOptions"  :placeholder="$labels['year-placeholder']  ?? mad_t('mad.dashf.all')" />
                </mad-form-grid>
                <div style="display:flex;justify-content:flex-end;gap:6px;margin-top:10px;">
                    <mad-btn variant="ghost" size="sm" :attrs="'@click.prevent=\'openCustom = false\''">{{ $labels['cancel'] ?? mad_t('mad.btn.cancel') }}</mad-btn>
                    <mad-btn type="submit" variant="primary" size="sm" icon="check">{{ $labels['apply'] ?? mad_t('mad.btn.apply') }}</mad-btn>
                </div>
            </mad-form>
        @else
            {{-- Caller envolve em form pai. Sem <mad-form> aqui pra evitar form aninhado.
                 Apply submete o form pai (button[type=submit] dispara o ancestral). --}}
            <mad-form-grid :cols="1">
                <mad-select-field name="mes" :label="$labels['month'] ?? mad_t('mad.dashf.month')" :items="$monthOptions" :placeholder="$labels['month-placeholder'] ?? mad_t('mad.dashf.all')" />
                <mad-select-field name="ano" :label="$labels['year']  ?? mad_t('mad.dashf.year')"  :items="$yearOptions"  :placeholder="$labels['year-placeholder']  ?? mad_t('mad.dashf.all')" />
            </mad-form-grid>
            <div style="display:flex;justify-content:flex-end;gap:6px;margin-top:10px;">
                <mad-btn variant="ghost" size="sm" :attrs="'@click.prevent=\'openCustom = false\''">{{ $labels['cancel'] ?? mad_t('mad.btn.cancel') }}</mad-btn>
                <mad-btn type="submit" variant="primary" size="sm" icon="check">{{ $labels['apply'] ?? mad_t('mad.btn.apply') }}</mad-btn>
            </div>
        @endif
    </div>
</div>
@endif
