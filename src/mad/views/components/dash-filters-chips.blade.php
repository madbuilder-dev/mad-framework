{{-- <mad-dash-filters style="chips"> renderer --}}
{{-- Period as standalone control + chip bar for ACTIVE filters + popover to add more. --}}
{{-- Single outer <mad-form> envolve tudo (period com wrap-form="false") pra evitar
     multiplos tokens __mad_form em $_REQUEST. --}}
@php
    use Mad\View\MadBlade;
    // Rótulos/botões configuráveis no wrapper (apply-label, clear-label, no-refresh).
    $chrome = \Mad\Dashboard\MadDashFiltersCompiler::chrome($wrapper ?? []);
    $component = \Mad\Component\MadRenderContext::getComponent();

    // Split fields: period vs. regular filters
    $periodField   = null;
    $regularFields = [];
    foreach ($fields as $f) {
        if ($f['type'] === 'period-monthyear') $periodField = $f;
        else                                     $regularFields[] = $f;
    }

    $activeSummary = ($component instanceof \Mad\Filters\MadFilterable)
        ? $component->activeFiltersSummary($fields) : [];
    $totalActive = count($activeSummary);

    if ($periodField) {
        $periodHtml = base64_decode($periodField['raw_html_b64']);
        if (strpos($periodHtml, 'wrap-form=') === false) {
            $periodHtml = preg_replace('#<mad-period-monthyear\b#', '<mad-period-monthyear wrap-form="false"', $periodHtml, 1);
        }
    }
@endphp

<mad-form submit="onShow">
    {{-- Period control sits BEFORE chip bar (above) if declared --}}
    @if ($periodField)
    <div style="margin-bottom:12px;">
        {!! MadBlade::renderString($periodHtml) !!}
    </div>
    @endif

    <div class="mad-dashf-chips-bar">
        <span class="mad-dashf-chips-lbl">{{ mad_t('mad.dashf.filters') }}</span>

        @foreach ($activeSummary as $chip)
            @php
                // Periodo ativo tambem vira chip (clear_pair → clearFilter('_period'))
                // — antes o badge contava a entrada mas nenhum chip aparecia.
                $clearTarget = !empty($chip['clear_pair'])
                    ? '_period'
                    : preg_replace('/[^\w\-]/', '', (string) ($chip['clear'] ?? ''));
                $attrClear = 'data-mad-click="clearFilter(\'' . $clearTarget . '\')"';
            @endphp
            <span class="mad-dashf-chip">
                <span class="mad-dashf-chip-cat">{{ $chip['label'] }}:</span>
                <span class="mad-dashf-chip-val">{{ $chip['value'] }}</span>
                <button type="button" class="mad-dashf-chip-x" {!! $attrClear !!}
                        aria-label="{{ mad_t('mad.btn.clear') }} {{ $chip['label'] }}">×</button>
            </span>
        @endforeach

        @if (!empty($regularFields))
            @php
                // Nomes de prop entram em strings JS do Alpine: restringe a
                // identifier (aspas no name quebravam/injetavam no x-data).
                $safeType  = fn ($n) => preg_replace('/[^\w\-]/', '', (string) $n);
                $firstType = $safeType($regularFields[0]['attrs']['name'] ?? '');
            @endphp
            <div class="mad-dashf-anchor" x-data="{ open: false, tipo: '{{ $firstType }}' }" style="position:relative;">
                <button type="button" class="mad-dashf-chip-add" @click="open = !open"
                        aria-haspopup="true" :aria-expanded="open ? 'true' : 'false'">+ {{ mad_t('mad.dashf.add_filter') }}</button>

                <div class="mad-dashf-pop" x-show="open" x-cloak
                     @click.outside="if (!$event.target.closest('.mad-sel-dropdown')) open = false" @keydown.escape.window="open = false">
                    <div class="mad-dashf-pop-head">
                        <span>{{ mad_t('mad.dashf.add_filter') }}</span>
                        <button type="button" class="mad-dashf-pop-close" @click="open = false" aria-label="{{ mad_t('mad.btn.close') }}">×</button>
                    </div>

                    <div class="mad-dashf-pop-types">
                        @foreach ($regularFields as $f)
                            @php $tname = $safeType($f['attrs']['name'] ?? ''); $tlbl = $f['attrs']['label'] ?? ucfirst($tname); @endphp
                            <button type="button" class="mad-dashf-pop-type-btn"
                                :class="{ active: tipo === '{{ $tname }}' }"
                                @click="tipo = '{{ $tname }}'">{{ $tlbl }}</button>
                        @endforeach
                    </div>

                    @foreach ($regularFields as $f)
                        @php $tname = $safeType($f['attrs']['name'] ?? ''); @endphp
                        <div x-show="tipo === '{{ $tname }}'" @if (!$loop->first) x-cloak @endif>
                            {!! MadBlade::renderString(base64_decode($f['raw_html_b64'])) !!}
                        </div>
                    @endforeach

                    <div class="mad-dashf-pop-actions">
                        <mad-btn variant="ghost" size="sm" :attrs="'@click.prevent=\'open = false\''">{{ mad_t('mad.btn.cancel') }}</mad-btn>
                        <mad-btn type="submit" variant="primary" size="sm" icon="check">{{ $chrome['apply'] }}</mad-btn>
                    </div>
                </div>
            </div>
        @endif

        @if ($chrome['refresh'])
        <button type="button" class="mad-dashf-chip-refresh" mad:click="onAtualizar"
                title="{{ mad_t('mad.dashf.refresh_data') }}" aria-label="{{ mad_t('mad.dashf.refresh_data') }}">↻</button>
        @endif

        @if ($totalActive > 0)
            <button type="button" class="mad-dashf-chip-clear" mad:click="onLimpar">{{ $chrome['clearAll'] }}</button>
        @endif

        <div class="mad-dashf-chip-spacer"></div>

        @if ($totalActive > 0)
            <span class="mad-dashf-chip-count">{{ $totalActive === 1 ? mad_t('mad.dashf.filter_one_active') : mad_t('mad.dashf.filter_many_active', ['n' => $totalActive]) }}</span>
        @endif
    </div>
</mad-form>
