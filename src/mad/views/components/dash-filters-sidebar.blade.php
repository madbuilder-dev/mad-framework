{{-- <mad-dash-filters style="sidebar-left|sidebar-right"> renderer --}}
{{-- Sidebar 260px with grouped fields. Must be paired with <mad-dashboard-main>. --}}
{{-- OBRIGATORIO: envolva o par (este bloco + <mad-dashboard-main>) num          --}}
{{--   <div class="mad-dashf-layout-left"> (sidebar a esquerda) ou               --}}
{{--   <div class="mad-dashf-layout-right"> (sidebar a direita).                 --}}
{{-- Sem o wrapper o grid nao existe e a sidebar empilha full-width.             --}}
@php
    use Mad\View\MadBlade;
    // Rótulos/botões configuráveis no wrapper (apply-label, clear-label, no-refresh).
    $chrome = \Mad\Dashboard\MadDashFiltersCompiler::chrome($wrapper ?? []);
    $component    = \Mad\Component\MadRenderContext::getComponent();
    $totalActive  = ($component instanceof \Mad\Filters\MadFilterable) ? $component->activeFiltersCount($fields) : 0;
    $isRight      = ($style === 'sidebar-right');
    $sidebarSide  = $isRight ? 'right' : 'left';

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

{{-- order: main e order:3 — right precisa vir DEPOIS (4), senao a sidebar
     cai na coluna 1fr do grid e o main espremia nos 260px. --}}
<aside class="mad-dashf-sb mad-dashf-sb-{{ $sidebarSide }}"
    @if ($isRight) style="order:4;" @else style="order:1;" @endif>
    <div class="mad-dashf-sb-head">
        <span class="mad-dashf-sb-title">{{ $wrapper['title'] ?? mad_t('mad.dashf.filters') }}</span>
        @if ($totalActive > 0)
            <span class="mad-dashf-sb-count">{{ mad_t('mad.dashf.active_count', ['n' => $totalActive]) }}</span>
        @endif
    </div>

    <mad-form submit="onShow">
        @if ($layout === 'custom')
            {!! MadBlade::renderString($injectWrapFormFalse($rawInner)) !!}
        @else
        @foreach ($fields as $field)
            @php
                $lbl    = $field['attrs']['label'] ?? ucfirst($field['attrs']['name'] ?? '');
                $active = $component instanceof \Mad\Filters\MadFilterable ? $component->isFilterActive($field) : false;
                $isPeriod = $field['type'] === 'period-monthyear';
                $fieldHtml = $injectWrapFormFalse(base64_decode($field['raw_html_b64']));
            @endphp
            <div class="mad-dashf-sb-group">
                @if (!$isPeriod)
                <div class="mad-dashf-sb-glabel">
                    {{ $lbl }}
                    @if ($active)<span class="mad-dashf-sb-gbadge">1</span>@endif
                </div>
                @endif
                {!! MadBlade::renderString($fieldHtml) !!}
            </div>
        @endforeach
        @endif

        <div class="mad-dashf-sb-apply">
            <mad-btn type="submit" variant="primary" size="sm" icon="check" block>{{ $chrome['apply'] }}</mad-btn>
            <div style="margin-top:6px;">
                @if ($chrome['refresh'])
                <mad-btn variant="ghost" size="sm" icon="refresh-cw" block mad:click="onAtualizar">{{ mad_t('mad.btn.refresh') }}</mad-btn>
                @endif
            </div>
            @if ($totalActive > 0)
                <div style="margin-top:6px;">
                    <mad-btn variant="ghost" size="sm" icon="x-circle" block mad:click="onLimpar">{{ $chrome['clearAll'] }}</mad-btn>
                </div>
            @endif
        </div>
    </mad-form>
</aside>
