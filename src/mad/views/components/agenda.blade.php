@php
    // Template interno — só desenha via MadAgenda::_renderHtml().
    if (!isset($__agenda) || !$__agenda instanceof \Mad\Agenda\MadAgenda) {
        return;
    }
    $agFlags  = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
    $agCfgJs  = json_encode($cfg, $agFlags);
    $agData   = json_encode($payload, $agFlags);
    $agHeight = preg_match('/^\d{2,4}(px|vh|dvh|rem|em)$/', (string) ($cfg['height'] ?? '')) ? $cfg['height'] : '';
    $agViews  = $cfg['views'];
    $agI18n   = $cfg['i18n'];
    $agRes    = $payload['resources'] ?? [];
    $agDateMask = \Mad\Support\DateMask::date();
    $agDatePh   = strtr($agDateMask, ['y' => mad_t('mad.ui.year_letter')]);
@endphp
<div class="mad-agenda" data-mad-agenda="{{ $cfg['key'] }}" x-data='madAgenda({!! $agCfgJs !!})'
     :class="{ 'is-loading': loading, 'is-mobile': mobile }"
     @if ($agHeight) style="--mad-ag-height: {{ $agHeight }}" @endif>
    <script type="application/json" data-ag-payload>{!! $agData !!}</script>

    <div class="mad-ag-bar" @keydown="barKey($event)">
        <div class="mad-ag-nav">
            <button type="button" class="mad-ag-btn mad-ag-icon" @click="prev()" :aria-label="navLabel(-1)" :title="navLabel(-1)">&#8249;</button>
            <button type="button" class="mad-ag-btn mad-ag-today" @click="today()">{{ $agI18n['today'] ?? 'Hoje' }}</button>
            <button type="button" class="mad-ag-btn mad-ag-icon" @click="next()" :aria-label="navLabel(1)" :title="navLabel(1)">&#8250;</button>
            {{-- Data: o mesmo seletor do <mad-date-field> (máscara do idioma, calendário do framework). --}}
            <div class="mad-input-group mad-ag-datepick" style="position:relative;"
                 x-data="madDatePicker({ displayMask: @js($agDateMask), databaseMask: 'yyyy-mm-dd', initialValue: @js($payload['date'] ?? ''), onCommit: (iso) => { if (iso && iso !== date) goDate(iso) } })"
                 @keydown.escape="close()" @click.outside="close()">
                <input type="text" class="mad-input mad-ag-date" autocomplete="off" inputmode="numeric"
                       aria-label="{{ $agI18n['go_to_date'] ?? '' }}" placeholder="{{ $agDatePh }}">
                <button type="button" class="mad-datepicker-btn" tabindex="-1" aria-hidden="true" @click="toggle()">
                    <i data-lucide="calendar" style="width:14px;height:14px;"></i>
                </button>
                @include('components.partials.date-popup', ['withTime' => false, 'opens' => 'left'])
            </div>
        </div>
        <h2 class="mad-ag-title" aria-live="polite" x-text="title"></h2>
        @if (count($agViews) > 1)
            <div class="mad-ag-views" role="tablist" aria-label="{{ $agI18n['view_by'] ?? '' }}">
                @foreach ($agViews as $v)
                    <button type="button" role="tab" class="mad-ag-view" :class="{ 'is-active': view === '{{ $v }}' }"
                            :aria-selected="view === '{{ $v }}' ? 'true' : 'false'" @click="setView('{{ $v }}')">{{ $agI18n['view_' . $v] ?? $v }}</button>
                @endforeach
            </div>
        @endif
        {{-- Recurso: <select> com data-mad-select vira o combo do framework (mesmo do <mad-select-field>).
             As opções são escritas pelo madAgenda (_syncResSelect) a cada carga. --}}
        <div class="mad-ag-res" x-show="data.resources.length > 1 && (view !== 'day' || mobile)" x-cloak>
            <select data-mad-select data-mad-nosearch data-placeholder="{{ $agI18n['all_resources'] ?? '' }}" x-ref="resSelect" aria-label="{{ $agI18n['resources'] ?? '' }}"
                    @change="setResource($event.target.value)"></select>
        </div>
    </div>

    @if ($toolbarHtml !== '')
        <div class="mad-ag-tools">{!! $toolbarHtml !!}</div>
    @endif

    <div class="mad-ag-stage" x-ref="stage" aria-busy="false" :aria-busy="loading ? 'true' : 'false'"></div>

    <div class="mad-ag-foot">
        <span class="mad-ag-loading" x-show="loading" x-cloak>{{ $agI18n['loading'] ?? '' }}</span>
        <span x-show="data.truncated" x-cloak>{{ $agI18n['truncated'] ?? '' }}</span>
        @if (!$agRes && ($cfg['emptyText'] ?? '') !== '')
            <span>{{ $cfg['emptyText'] }}</span>
        @endif
        @if (($cfg['slotClick'] ?? '') !== '' || !empty($cfg['slotTarget']))
            <span class="mad-ag-hint">{{ $agI18n['drag_hint'] ?? '' }}</span>
        @endif
    </div>
</div>
