{{-- <mad-dash-filters style="drawer"> renderer --}}
{{-- Button "Filters (N)" → opens drawer with form containing all fields.        --}}
{{-- trigger="manual" → skip default button; user wires own via open-drawer.     --}}
{{-- Single <mad-form> envolve TODOS os filtros (incl. period com wrap-form=false)
     pra garantir que getData() veja o schema completo de todos os campos. --}}
@php
    use Mad\View\MadBlade;
    // Rótulos/botões configuráveis no wrapper (apply-label, clear-label, no-refresh).
    $chrome = \Mad\Dashboard\MadDashFiltersCompiler::chrome($wrapper ?? []);
    $component   = \Mad\Component\MadRenderContext::getComponent();
    $totalActive = ($component instanceof \Mad\Filters\MadFilterable) ? $component->activeFiltersCount($fields) : 0;
    $drawerName  = $wrapper['name']  ?? 'mad-dash-filters-drawer';
    $drawerTitle = $wrapper['title'] ?? mad_t('mad.dashf.filters');
    $trigger     = $wrapper['trigger'] ?? 'auto';
    $btnLabel    = $totalActive > 0
        ? mad_t('mad.dashf.filters_with_count', ['n' => $totalActive])
        : mad_t('mad.dashf.filters');
    $openAttr    = 'onclick="Mad.openDrawer(\'' . $drawerName . '\')"';
    $closeAttr   = 'onclick="Mad.closeDrawer(\'' . $drawerName . '\')"';

    // Layout custom — renderiza inner cru com layout tags do user
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

<mad-drawer :name="$drawerName" :title="$drawerTitle" icon="sliders-horizontal" size="md">
    <mad-form submit="onShow">
        @if ($layout === 'custom')
            {!! MadBlade::renderString($injectWrapFormFalse($rawInner)) !!}
        @else
        <mad-form-stack>
            @foreach ($fields as $field)
                @if ($field['type'] === 'period-monthyear')
                    <div style="padding-bottom:12px;border-bottom:1px solid var(--mad-border,#e5e7eb);margin-bottom:8px;">
                        <div style="font-size:11px;color:var(--mad-text-subtle,#6b7280);text-transform:uppercase;letter-spacing:.06em;font-weight:600;margin-bottom:8px;">{{ mad_t('mad.dashf.period') }}</div>
                        {!! MadBlade::renderString($injectWrapFormFalse(base64_decode($field['raw_html_b64']))) !!}
                    </div>
                @else
                    {!! MadBlade::renderString(base64_decode($field['raw_html_b64'])) !!}
                @endif
            @endforeach
        </mad-form-stack>
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
</mad-drawer>
