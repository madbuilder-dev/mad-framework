{{-- Corpo do <mad-week-grid> (Mad\Ui\MadWeekGrid::view). $wg = matrix(). --}}
@php
    $c = $wg['config'];
    $states = $wg['states'];
    $stateBy = [];
    foreach ($states as $s) { $stateBy[$s['value']] = $s; }
    $cycle = array_column($states, 'value');
    if ($c['allowEmpty']) { $cycle[] = ''; }
    $rowsTitle = $c['rowsTitle'] !== '' ? $c['rowsTitle'] : mad_t('mad.week_grid.rows_title');
    $noteLabel = $c['noteLabel'] !== '' ? $c['noteLabel'] : mad_t('mad.week_grid.note_title');
    $title = $c['title'];
    // Estável entre redesenhos (o JS devolve o foco pelo id depois de trocar de semana).
    $gid = 'wg' . substr(md5($c['owner'] . '|' . $c['name'] . '|' . $c['model'] . '|' . $c['rowField'] . '|' . $c['weekField'] . $c['dateField']), 0, 8);
    $emptyLabel = mad_t('mad.week_grid.state_none');
    $label = static fn (string $v) => $v === '' ? $emptyLabel : ($stateBy[$v]['label'] ?? $v);
    $tone = static fn (string $v) => $v === '' ? 'empty' : ($stateBy[$v]['tone'] ?? 'neutral');
    $toneStyle = static fn (string $v) => ($v !== '' && ($stateBy[$v]['tone'] ?? '') === 'custom') ? '--wg-tone:' . $stateBy[$v]['color'] . ';' : '';
    $short = static fn (string $v) => $v === '' ? '·' : ($stateBy[$v]['short'] ?? $v);
    $nextOf = static function (string $v) use ($cycle): string {
        $i = array_search($v, $cycle, true);
        return $cycle[$i === false ? 0 : ($i + 1) % count($cycle)] ?? '';
    };
    $cellAria = static function (array $r, array $d, string $v) use ($label, $nextOf): string {
        $txt = $r['label'] . ', ' . $d['long'] . ': ' . $label($v);
        if ($d['holiday']) $txt .= ' (' . $d['holiday'] . ')';
        if ($r['editable']) $txt .= '. ' . mad_t('mad.week_grid.next_hint', ['state' => $label($nextOf($v))]);
        return $txt;
    };
    $cellKey = static fn (array $r, array $d) => $r['key'] . ':' . $d['date'];
    $hasRows = $wg['rows'] !== [];
    $statesJson = json_encode(array_map(static fn ($s) => ['v' => $s['value'], 'l' => $s['label'], 's' => $s['short'], 't' => $s['tone'], 'c' => $s['color']], $states), JSON_UNESCAPED_UNICODE);
@endphp
<div class="mad-week-grid" id="{{ $gid }}" data-mad-week-grid
     @if($c['name'] !== '') data-wg-name="{{ $c['name'] }}" @endif
     @if($c['action'] !== '') data-wg-action="{{ $c['action'] }}" @endif
     data-wg-states="{{ $statesJson }}" data-wg-cycle="{{ json_encode($cycle) }}"
     data-wg-empty-label="{{ $emptyLabel }}" data-wg-week="{{ $wg['weekStart'] }}"
     data-wg-next-hint="{{ mad_t('mad.week_grid.next_hint', ['state' => '%s']) }}"
     data-wg-saved-text="{{ mad_t('mad.week_grid.saved', ['state' => '%s']) }}"
     data-wg-failed-text="{{ mad_t('mad.week_grid.failed_live') }}">

    <div class="mad-wg-toolbar">
        @if($title !== '')<div class="mad-wg-title" id="{{ $gid }}-title">{{ $title }}</div>@endif
        <div class="mad-wg-nav" role="group" aria-label="{{ mad_t('mad.week_grid.nav') }}">
            <button type="button" class="mad-wg-navbtn" data-wg-go="prev" aria-label="{{ mad_t('mad.week_grid.prev') }}" title="{{ mad_t('mad.week_grid.prev') }}">
                <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path d="M15 18l-6-6 6-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <button type="button" class="mad-wg-navbtn mad-wg-navbtn--today" data-wg-go="today" @if($wg['isCurrent']) aria-current="true" @endif>{{ mad_t('mad.week_grid.today') }}</button>
            <button type="button" class="mad-wg-navbtn" data-wg-go="next" aria-label="{{ mad_t('mad.week_grid.next') }}" title="{{ mad_t('mad.week_grid.next') }}">
                <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path d="M9 18l6-6-6-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
        </div>
        <div class="mad-wg-range" id="{{ $gid }}-range">{{ $wg['range'] }}</div>
        @if($c['copyWeek'] && !$wg['locked'] && $c['action'] === '')
            <button type="button" class="mad-wg-copy" data-wg-copy>
                <svg viewBox="0 0 24 24" width="15" height="15" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2" fill="none" stroke="currentColor" stroke-width="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10" fill="none" stroke="currentColor" stroke-width="2"/></svg>
                <span>{{ mad_t('mad.week_grid.copy') }}</span>
            </button>
        @endif
    </div>

    @if($c['legend'] && $states !== [])
        <ul class="mad-wg-legend" aria-label="{{ mad_t('mad.week_grid.legend') }}">
            @foreach($states as $s)
                <li><span class="mad-wg-chip mad-wg-tone-{{ $s['tone'] }}" @if($s['tone'] === 'custom') style="--wg-tone: {{ $s['color'] }}" @endif aria-hidden="true">{{ $s['short'] }}</span>{{ $s['label'] }}</li>
            @endforeach
            @if(array_filter(array_column($wg['days'], 'holiday')))
                <li><span class="mad-wg-chip mad-wg-chip--holiday" aria-hidden="true"></span>{{ mad_t('mad.week_grid.holiday') }}</li>
            @endif
            @if(!$wg['locked'] && $hasRows)
                <li class="mad-wg-hint">{{ mad_t('mad.week_grid.hint') }}</li>
            @endif
        </ul>
    @endif

    @if($wg['error'])
        <div class="mad-wg-error" role="alert">{{ $wg['error'] }}</div>
    @elseif(!$hasRows)
        <div class="mad-wg-empty">{{ $c['emptyText'] !== '' ? $c['emptyText'] : mad_t('mad.week_grid.empty') }}</div>
    @else
        {{-- Semana em tabela (telas largas) --}}
        <div class="mad-wg-scroll">
            <table class="mad-wg-table" role="grid" aria-labelledby="{{ $title !== '' ? $gid . '-title ' : '' }}{{ $gid }}-range" data-wg-layout="table">
                <thead>
                    <tr>
                        <th scope="col" class="mad-wg-rowhead">{{ $rowsTitle }}</th>
                        @foreach($wg['days'] as $d)
                            <th scope="col" class="mad-wg-dayhead{{ $d['today'] ? ' is-today' : '' }}{{ $d['holiday'] ? ' is-holiday' : '' }}"
                                @if($d['holiday']) title="{{ $d['holiday'] }}" @endif>
                                <abbr title="{{ $d['long'] }}{{ $d['holiday'] ? ' — ' . $d['holiday'] : '' }}">{{ $d['short'] }}</abbr>
                                <small>{{ $d['dm'] }}</small>
                                @if($d['holiday'])<span class="mad-wg-holname">{{ $d['holiday'] }}</span>@endif
                            </th>
                        @endforeach
                        @if($wg['noteOn'])<th scope="col" class="mad-wg-notehead">{{ $noteLabel }}</th>@endif
                    </tr>
                </thead>
                <tbody>
                    @foreach($wg['rows'] as $r)
                        <tr data-wg-row="{{ $r['key'] }}" class="{{ $r['editable'] ? '' : 'is-locked' }}">
                            <th scope="row" class="mad-wg-rowlabel">
                                <span class="mad-wg-name">{{ $r['label'] }}</span>
                                @if($r['sub'] !== '')<span class="mad-wg-sub">{{ $r['sub'] }}</span>@endif
                                @if(!$r['editable'])
                                    <span class="mad-wg-lock" title="{{ mad_t('mad.week_grid.row_readonly') }}">
                                        <svg viewBox="0 0 24 24" width="12" height="12" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2" fill="none" stroke="currentColor" stroke-width="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4" fill="none" stroke="currentColor" stroke-width="2"/></svg>
                                        <span class="mad-wg-sr">{{ mad_t('mad.week_grid.row_readonly') }}</span>
                                    </span>
                                @endif
                            </th>
                            @foreach($wg['days'] as $d)
                                @php $v = $r['cells'][$d['i']] ?? ''; @endphp
                                <td role="gridcell" class="mad-wg-td{{ $d['today'] ? ' is-today' : '' }}{{ $d['holiday'] ? ' is-holiday' : '' }}">
                                    <button type="button" class="mad-wg-cell mad-wg-tone-{{ $tone($v) }}"
                                            data-wg-cell="{{ $cellKey($r, $d) }}" data-wg-r="{{ $r['key'] }}" data-wg-d="{{ $d['date'] }}"
                                            data-wg-value="{{ $v }}" data-wg-label="{{ $r['label'] }}, {{ $d['long'] }}{{ $d['holiday'] ? ' (' . $d['holiday'] . ')' : '' }}"
                                            @if($toneStyle($v) !== '') style="{{ $toneStyle($v) }}" @endif
                                            @if(!$r['editable']) aria-disabled="true" @endif
                                            tabindex="{{ ($loop->parent->first && $loop->first) ? 0 : -1 }}" aria-label="{{ $cellAria($r, $d, $v) }}">{{ $short($v) }}</button>
                                </td>
                            @endforeach
                            @if($wg['noteOn'])
                                <td class="mad-wg-notecell">
                                    <input type="text" class="mad-wg-note" data-wg-note="{{ $r['key'] }}" value="{{ $r['note'] }}"
                                           maxlength="{{ $c['noteMax'] }}" placeholder="{{ $c['notePlaceholder'] }}"
                                           aria-label="{{ $noteLabel }} — {{ $r['label'] }}" @if(!$r['editable']) readonly aria-readonly="true" @endif>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
                @if($c['totals'] && $states !== [])
                    <tfoot>
                        <tr>
                            <th scope="row" class="mad-wg-rowhead">{{ mad_t('mad.week_grid.totals') }}</th>
                            @foreach($wg['days'] as $d)
                                <td class="mad-wg-total" data-wg-total="{{ $d['date'] }}">
                                    @foreach($states as $s)
                                        @php $n = $wg['totals'][$d['i']][$s['value']] ?? 0; @endphp
                                        <span class="mad-wg-count mad-wg-tone-{{ $s['tone'] }}" data-wg-count="{{ $s['value'] }}" @if($s['tone'] === 'custom') style="--wg-tone: {{ $s['color'] }}" @endif
                                              @if($n === 0) hidden @endif title="{{ $s['label'] }}">{{ $s['short'] }} {{ $n }}</span>
                                    @endforeach
                                </td>
                            @endforeach
                            @if($wg['noteOn'])<td></td>@endif
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>

        {{-- Semana como lista por dia (telas estreitas) --}}
        <div class="mad-wg-days" data-wg-layout="list">
            @foreach($wg['days'] as $d)
                <section class="mad-wg-day{{ $d['today'] ? ' is-today' : '' }}{{ $d['holiday'] ? ' is-holiday' : '' }}" aria-labelledby="{{ $gid }}-d{{ $d['i'] }}">
                    <h4 class="mad-wg-dayttl" id="{{ $gid }}-d{{ $d['i'] }}">
                        <span>{{ $d['short'] }} <small>{{ $d['dm'] }}</small></span>
                        @if($d['today'])<span class="mad-wg-badge">{{ mad_t('mad.week_grid.today_badge') }}</span>@endif
                        @if($d['holiday'])<span class="mad-wg-holname">{{ $d['holiday'] }}</span>@endif
                    </h4>
                    <ul role="list">
                        @foreach($wg['rows'] as $r)
                            @php $v = $r['cells'][$d['i']] ?? ''; @endphp
                            <li class="{{ $r['editable'] ? '' : 'is-locked' }}">
                                <span class="mad-wg-name">{{ $r['label'] }}@if(!$r['editable'])<span class="mad-wg-sr"> — {{ mad_t('mad.week_grid.row_readonly') }}</span>@endif</span>
                                <button type="button" class="mad-wg-cell mad-wg-tone-{{ $tone($v) }}"
                                        data-wg-cell="{{ $cellKey($r, $d) }}" data-wg-r="{{ $r['key'] }}" data-wg-d="{{ $d['date'] }}"
                                        data-wg-value="{{ $v }}" data-wg-label="{{ $r['label'] }}, {{ $d['long'] }}{{ $d['holiday'] ? ' (' . $d['holiday'] . ')' : '' }}"
                                        @if($toneStyle($v) !== '') style="{{ $toneStyle($v) }}" @endif
                                        @if(!$r['editable']) aria-disabled="true" @endif
                                        aria-label="{{ $cellAria($r, $d, $v) }}">
                                    <span class="mad-wg-cell-short">{{ $short($v) }}</span>
                                    <span class="mad-wg-cell-long">{{ $label($v) }}</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach
            @if($wg['noteOn'])
                <section class="mad-wg-day mad-wg-day--notes" aria-labelledby="{{ $gid }}-notes">
                    <h4 class="mad-wg-dayttl" id="{{ $gid }}-notes">{{ $noteLabel }}</h4>
                    <ul role="list">
                        @foreach($wg['rows'] as $r)
                            <li class="mad-wg-noterow">
                                <label class="mad-wg-name" for="{{ $gid }}-n-{{ $loop->index }}">{{ $r['label'] }}</label>
                                <input type="text" id="{{ $gid }}-n-{{ $loop->index }}" class="mad-wg-note" data-wg-note="{{ $r['key'] }}" value="{{ $r['note'] }}"
                                       maxlength="{{ $c['noteMax'] }}" placeholder="{{ $c['notePlaceholder'] }}"
                                       @if(!$r['editable']) readonly aria-readonly="true" @endif>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>
    @endif

    <div class="mad-wg-sr" aria-live="polite" data-wg-live></div>
</div>
