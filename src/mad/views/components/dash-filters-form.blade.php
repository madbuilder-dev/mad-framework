{{-- <mad-grid-filters style="form"> renderer --}}
{{-- Form tradicional inline. Todos os fields visiveis num mad-form-grid.       --}}
{{-- Apply submete tudo de uma vez. Period (mes/ano) renderiza acima do grid.   --}}
{{-- Atributos do wrapper:                                                       --}}
{{--   title   — label da secao (default: "Filtros")                             --}}
{{--   icon    — icone Lucide (default: sliders-horizontal)                      --}}
{{--   cols    — colunas do grid auto (1..4, default: 2)                         --}}
{{--   no-header — sem ícone/título/divisória (só os campos e os botões)        --}}
{{--   apply-label / clear-label — texto de "Aplicar" / "Limpar tudo"            --}}
{{--   no-refresh — esconde "Atualizar"                                          --}}
{{--   layout  — "auto" (default) ou "custom":                                   --}}
{{--             "custom" renderiza o inner HTML cru — voce controla disposicao  --}}
{{--             usando <mad-form-grid>, <mad-form-section>, <mad-tabs>, etc.    --}}
@php
    use Mad\View\MadBlade;
    $component   = \Mad\Component\MadRenderContext::getComponent();
    $totalActive = ($component instanceof \Mad\Filters\MadFilterable) ? $component->activeFiltersCount($fields) : 0;

    $chrome = \Mad\Dashboard\MadDashFiltersCompiler::chrome($wrapper ?? []);
    $title  = $wrapper['title'] ?? mad_t('mad.dashf.filters');
    $icon   = $wrapper['icon']  ?? 'sliders-horizontal';
    // Sem cabeçalho: form-section só desenha a linha com título OU ícone.
    if (!$chrome['header']) {
        $title = '';
        $icon  = null;
    }
    $cols   = (int) ($wrapper['cols'] ?? 2);
    if ($cols < 1) $cols = 1;
    if ($cols > 4) $cols = 4;

    $layout    = $wrapper['layout'] ?? 'auto';
    $rawInner  = !empty($wrapper['_raw_inner_b64']) ? base64_decode($wrapper['_raw_inner_b64']) : '';

    // Auto-detecta layout custom quando user usou tags de layout dentro do wrapper
    if ($layout === 'auto' && $rawInner !== ''
        && preg_match('#<mad-(form-grid|form-section|form-stack|tabs|tabs-list|accordion|card)\b#', $rawInner)) {
        $layout = 'custom';
    }

    // Pra periodo: injeta wrap-form="false" pra evitar form aninhado
    $injectPeriodWrapForm = function (string $html): string {
        if (strpos($html, 'wrap-form=') !== false) return $html;
        return preg_replace('#<mad-period-monthyear\b#', '<mad-period-monthyear wrap-form="false"', $html);
    };

    // Auto layout — split period vs regular
    $periodField   = null;
    $regularFields = [];
    foreach ($fields as $f) {
        if (($f['type'] ?? '') === 'period-monthyear') {
            $periodField = $f;
        } else {
            $regularFields[] = $f;
        }
    }
@endphp

<mad-card class="mad-dashf-form">
    <mad-form submit="onShow">
        <mad-form-section :title="$title" :icon="$icon">

            @if ($layout === 'custom')
                {{-- Layout custom — renderiza inner cru. User controla via
                     <mad-form-grid>, <mad-form-section>, <mad-tabs>, etc. --}}
                {!! MadBlade::renderString($injectPeriodWrapForm($rawInner)) !!}
            @else
                {{-- Layout auto --}}
                @if ($periodField)
                    @php $periodHtml = $injectPeriodWrapForm(base64_decode($periodField['raw_html_b64'])); @endphp
                    <div class="mad-dashf-form-period">
                        {!! MadBlade::renderString($periodHtml) !!}
                    </div>
                @endif

                @if (!empty($regularFields))
                    <mad-form-grid :cols="$cols">
                        @foreach ($regularFields as $field)
                            {!! MadBlade::renderString(base64_decode($field['raw_html_b64'])) !!}
                        @endforeach
                    </mad-form-grid>
                @endif
            @endif

            <mad-form-actions>
                <mad-btn type="submit" variant="primary" icon="search">{{ $chrome['apply'] }}</mad-btn>
                <mad-btn variant="ghost" icon="x-circle" mad:click="onLimpar">{{ $chrome['clearAll'] }}</mad-btn>
                @if ($chrome['refresh'])
                <mad-btn variant="ghost" icon="refresh-cw" mad:click="onAtualizar">{{ mad_t('mad.btn.refresh') }}</mad-btn>
                @endif
                @if ($totalActive > 0)
                    <span class="mad-dashf-form-badge">{{ $totalActive === 1 ? mad_t('mad.dashf.filter_one_active') : mad_t('mad.dashf.filter_many_active', ['n' => $totalActive]) }}</span>
                @endif
            </mad-form-actions>

        </mad-form-section>
    </mad-form>
</mad-card>
