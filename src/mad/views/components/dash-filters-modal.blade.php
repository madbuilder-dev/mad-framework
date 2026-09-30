{{-- <mad-dash-filters style="modal"> renderer --}}
{{-- Button "Filters (N)" → opens modal with form containing all fields.        --}}
{{-- trigger="manual" → skip default button; user wires own via open-modal.    --}}
{{-- Single <mad-form> envolve TODOS os filtros (incl. period com wrap-form=false). --}}
@php
    use Mad\View\MadBlade;
    // Rótulos/botões configuráveis no wrapper (apply-label, clear-label, no-refresh).
    $chrome = \Mad\Dashboard\MadDashFiltersCompiler::chrome($wrapper ?? []);
    $component   = \Mad\Component\MadRenderContext::getComponent();
    $totalActive = ($component instanceof \Mad\Filters\MadFilterable) ? $component->activeFiltersCount($fields) : 0;
    $modalName   = $wrapper['name']  ?? 'mad-dash-filters-modal';
    $modalTitle  = $wrapper['title'] ?? mad_t('mad.dashf.filters');
    $trigger     = $wrapper['trigger'] ?? 'auto';
    $btnLabel    = $totalActive > 0
        ? mad_t('mad.dashf.filters_with_count', ['n' => $totalActive])
        : mad_t('mad.dashf.filters');
    $openAttr    = 'onclick="Mad.openModal(\'' . $modalName . '\')"';
    $closeAttr   = 'onclick="Mad.closeModal(\'' . $modalName . '\')"';

    // Layout custom — renderiza inner cru
    $layout   = $wrapper['layout'] ?? 'auto';
    $rawInner = !empty($wrapper['_raw_inner_b64']) ? base64_decode($wrapper['_raw_inner_b64']) : '';
    if ($layout === 'auto' && $rawInner !== ''
        && preg_match('#<mad-(form-grid|form-section|form-stack|tabs|tabs-list|accordion|card)\b#', $rawInner)) {
        $layout = 'custom';
    }
    $injectWrapFormFalse = function (string $html): string {
        if (strpos($html, 'wrap-form=') !== false) return $html;
        return preg_replace('#<mad-period-monthyear\b#', '<mad-period-monthyear wrap-form="false"', $html);
    };
@endphp

@if ($trigger !== 'manual')
<mad-btn variant="outline" icon="sliders-horizontal" :attrs="$openAttr">{{ $btnLabel }}</mad-btn>
@endif

<mad-modal :name="$modalName" :title="$modalTitle" size="lg">
    <mad-form submit="onShow">
        @if ($layout === 'custom')
            {!! MadBlade::renderString($injectWrapFormFalse($rawInner)) !!}
        @else
        <mad-form-grid :cols="2">
            @foreach ($fields as $field)
                @if ($field['type'] === 'period-monthyear')
                    <div style="grid-column:1/-1;padding-bottom:12px;border-bottom:1px solid var(--mad-border,#e5e7eb);margin-bottom:8px;">
                        <div style="font-size:11px;color:var(--mad-text-subtle,#6b7280);text-transform:uppercase;letter-spacing:.06em;font-weight:600;margin-bottom:8px;">{{ mad_t('mad.dashf.period') }}</div>
                        {!! MadBlade::renderString($injectWrapFormFalse(base64_decode($field['raw_html_b64']))) !!}
                    </div>
                @else
                    {!! MadBlade::renderString(base64_decode($field['raw_html_b64'])) !!}
                @endif
            @endforeach
        </mad-form-grid>
        @endif
        <mad-form-actions align="right">
            @if ($chrome['refresh'])
            <mad-btn variant="ghost" icon="refresh-cw" mad:click="onAtualizar">{{ mad_t('mad.btn.refresh') }}</mad-btn>
            @endif
            @if ($totalActive > 0)
                <mad-btn variant="ghost" icon="x-circle" mad:click="onLimpar">{{ $chrome['clearAll'] }}</mad-btn>
            @endif
            <mad-btn variant="ghost" :attrs="$closeAttr">{{ mad_t('mad.btn.cancel') }}</mad-btn>
            <mad-btn type="submit" variant="primary" icon="check">{{ $chrome['apply'] }}</mad-btn>
        </mad-form-actions>
    </mad-form>
</mad-modal>
