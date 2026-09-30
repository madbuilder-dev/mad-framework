{{-- <mad-dash-filters style="toolbar"> renderer --}}
{{-- UM unico <mad-form> envolve toda a toolbar. Cada filtro vira um popover    --}}
{{-- com mini-button + dropdown. Apply submete o form unico (todos os campos    --}}
{{-- aplicam-se de uma vez). Isso evita o problema de multiplos tokens          --}}
{{-- __mad_form em $_REQUEST (ultimo ganha → schema parcial → getData() filtra  --}}
{{-- fora os campos de outras popovers).                                        --}}
@php
    use Mad\View\MadBlade;
    // Rótulos/botões configuráveis no wrapper (apply-label, clear-label, no-refresh).
    $chrome = \Mad\Dashboard\MadDashFiltersCompiler::chrome($wrapper ?? []);
    $component    = \Mad\Component\MadRenderContext::getComponent();
    $isFilterable = $component instanceof \Mad\Filters\MadFilterable;
    $totalActive  = $isFilterable ? $component->activeFiltersCount($fields) : 0;
@endphp

<mad-form submit="onShow">
    <div class="mad-dashf-toolbar">
        @foreach ($fields as $field)
            @if ($field['type'] === 'period-monthyear')
                {{-- period-monthyear sem form interno — Apply submete o form pai --}}
                @php
                    $periodHtml = base64_decode($field['raw_html_b64']);
                    // Injeta wrap-form="false" no tag <mad-period-monthyear ...>
                    // pra evitar form aninhado.
                    if (strpos($periodHtml, 'wrap-form=') === false) {
                        $periodHtml = preg_replace(
                            '#<mad-period-monthyear\b#',
                            '<mad-period-monthyear wrap-form="false"',
                            $periodHtml,
                            1
                        );
                    }
                @endphp
                {!! MadBlade::renderString($periodHtml) !!}

                @if (!$loop->last)<div class="mad-dashf-tb-divider"></div>@endif

            @else
                @php
                    $name   = $field['attrs']['name']  ?? '';
                    // daterange: 2 props (name_start/name_end) — o clear limpa
                    // ambas via clearFilter("a|b") e o label vem do attr.
                    if (($field['type'] ?? '') === 'daterange' && $name === '') {
                        $name = trim(($field['attrs']['name_start'] ?? '') . '|' . ($field['attrs']['name_end'] ?? ''), '|');
                    }
                    $label  = $field['attrs']['label'] ?? ucfirst($name);
                    // Guard instanceof (nao truthiness): host MadComponent puro
                    // sem MadFilterable fatalava em isFilterActive().
                    $active = $isFilterable && $component->isFilterActive($field);
                    $valLbl = $active ? $component->resolveFilterLabel($field) : ($field['attrs']['placeholder'] ?? mad_t('mad.dashf.all'));
                    $cls    = $active ? 'mad-dashf-mini active' : 'mad-dashf-mini';
                    // name vai dentro de JS-em-atributo: restringe a identifier (| = filtro composto).
                    $safeName  = preg_replace('/[^\w\-|]/', '', $name);
                    $attrClear = 'data-mad-click="clearFilter(\'' . $safeName . '\')"';
                @endphp

                <div class="mad-dashf-anchor" x-data="{ open: false }">
                    <button type="button" class="{{ $cls }}" @click="open = !open"
                            aria-haspopup="true" :aria-expanded="open ? 'true' : 'false'">
                        <span class="mad-dashf-mini-lbl">{{ $label }}:</span>
                        <span>{{ $valLbl }}</span>
                        <span class="mad-dashf-mini-chev" aria-hidden="true">▾</span>
                    </button>
                    @php $isMulti = !empty($field['attrs']['multiple']); @endphp
                    {{-- multi: largura FIXA — o campo cresce ao mostrar os nomes
                         escolhidos quando a lista fecha, e o popover alargando
                         movia o botão Aplicar para fora do clique. --}}
                    <div class="mad-dashf-pop" x-show="open" x-cloak @if ($isMulti) style="width:340px;max-width:calc(100vw - 32px)" @endif
                         @click.outside="if (!$event.target.closest('.mad-sel-dropdown')) open = false" @keydown.escape.window="open = false">
                        <div class="mad-dashf-pop-head">
                            <span>{{ mad_t('mad.dashf.filter_by', ['label' => mb_strtolower($label)]) }}</span>
                            <button type="button" class="mad-dashf-pop-close" @click="open = false" aria-label="{{ mad_t('mad.btn.close') }}">×</button>
                        </div>
                        {{-- Seleção múltipla: a lista do select abre PARA BAIXO por cima do
                             popover (teleportada) e cobriria os botões — ações vão pro topo. --}}
                        @if (!$isMulti)
                            {!! MadBlade::renderString(base64_decode($field['raw_html_b64'])) !!}
                        @endif
                        <div class="mad-dashf-pop-actions" @if ($isMulti) style="margin:0 0 10px" @endif>
                            @if ($active)
                                <mad-btn variant="ghost" size="sm" icon="x-circle" :attrs="$attrClear">{{ mad_t('mad.btn.clear') }}</mad-btn>
                            @endif
                            <mad-btn variant="ghost" size="sm" :attrs="'@click.prevent=\'open = false\''">{{ mad_t('mad.btn.cancel') }}</mad-btn>
                            <mad-btn type="submit" variant="primary" size="sm" icon="check">{{ $chrome['apply'] }}</mad-btn>
                        </div>
                        @if ($isMulti)
                            {!! MadBlade::renderString(base64_decode($field['raw_html_b64'])) !!}
                        @endif
                    </div>
                </div>
            @endif
        @endforeach

        @if ($totalActive > 0)
            <span class="mad-dashf-pill">{{ $totalActive === 1 ? mad_t('mad.dashf.filter_one') : mad_t('mad.dashf.filter_many', ['n' => $totalActive]) }}</span>
        @endif

        <div class="mad-dashf-spacer"></div>

        @if ($chrome['refresh'])
        <mad-btn variant="ghost" icon="refresh-cw" size="sm" mad:click="onAtualizar">{{ mad_t('mad.btn.refresh') }}</mad-btn>
        @endif
        <mad-btn variant="ghost" icon="x-circle" size="sm" mad:click="onLimpar">{{ $chrome['clear'] }}</mad-btn>
    </div>
</mad-form>
