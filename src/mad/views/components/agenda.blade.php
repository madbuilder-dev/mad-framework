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
            <input type="date" class="mad-ag-date" aria-label="{{ $agI18n['go_to_date'] ?? '' }}" :value="date" @change="goDate($event.target.value)">
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
        <select class="mad-ag-res" aria-label="{{ $agI18n['resources'] ?? '' }}"
                x-show="data.resources.length > 1 && (view !== 'day' || mobile)" x-cloak
                :value="resource" @change="setResource($event.target.value)">
            <template x-if="view !== 'day'"><option value="">{{ $agI18n['all_resources'] ?? '' }}</option></template>
            <template x-for="r in data.resources" :key="r.id"><option :value="r.id" x-text="r.title" :selected="r.id === resource"></option></template>
        </select>
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
