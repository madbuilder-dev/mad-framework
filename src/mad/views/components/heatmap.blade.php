{{--
    <mad-heatmap> — matriz N×M com cor por faixa, bolha com a contagem, célula
    clicável e legenda (matriz de risco P×I, priorização, competência).

    <mad-heatmap model="Risco" x-field="impacto" y-field="probabilidade" preset="risk"
        :x-labels="[1 => 'Muito baixo', 2 => 'Baixo', 3 => 'Médio', 4 => 'Alto', 5 => 'Muito alto']"
        :y-labels="[1 => 'Rara', 2 => 'Improvável', 3 => 'Possível', 4 => 'Provável', 5 => 'Quase certa']"
        x-title="Impacto" y-title="Probabilidade" list-field="titulo"
        click-target="RiscoList" />

    Dados e faixas: Mad\Chart\MadHeatmap::build(). Clique (mad-ui.js):
      click-action="metodo"  → MadWire.call(tela, metodo, [x, y, valor])
      click-target="Tela"     → Mad.go(Tela, show, {x-param: x, y-param: y}) —
                                tela com wrapper de cortina abre como cortina
      sempre                  → evento `mad-heatmap-click` {name, x, y, value} no elemento
    Guia: docs/mad-heatmap.md
--}}
@php
    $__b = static function ($v, bool $default): bool {
        if ($v === null || $v === '') return $default;
        if (is_bool($v)) return $v;
        return !in_array(strtolower(trim((string) $v)), ['false', '0', 'no', 'off', 'nao', 'não'], true);
    };

    $xField    = (string) ($xField ?? '');
    $yField    = (string) ($yField ?? '');
    $xTitle    = (string) ($xTitle ?? '');
    $yTitle    = (string) ($yTitle ?? '');
    $title     = (string) ($title ?? '');
    $name      = (string) ($name ?? '');
    $clickAction = trim((string) ($clickAction ?? ''));
    $clickTarget = trim((string) ($clickTarget ?? ''));
    $xParam    = trim((string) ($xParam ?? '')) ?: ($xField !== '' ? $xField : 'x');
    $yParam    = trim((string) ($yParam ?? '')) ?: ($yField !== '' ? $yField : 'y');
    $showLegend  = $__b($legend ?? null, true);
    $showBubbles = $__b($bubbles ?? null, true);
    $clickEmpty  = $__b($clickEmpty ?? null, false);
    $noDebug     = $__b($noDebug ?? null, false);
    $class     = (string) ($class ?? '');
    $style     = (string) ($style ?? '');

    $hm = \Mad\Chart\MadHeatmap::build([
        'model' => $model ?? '', 'database' => $database ?? '', 'query' => $query ?? null,
        'filters' => $filters ?? [], 'data' => $data ?? null,
        'xField' => $xField, 'yField' => $yField, 'total' => $total ?? 'count', 'field' => $field ?? '',
        'format' => $format ?? '', 'xLabels' => $xLabels ?? null, 'yLabels' => $yLabels ?? null,
        'xOrder' => $xOrder ?? 'asc', 'yOrder' => $yOrder ?? null, 'bands' => $bands ?? null,
        'bandBy' => $bandBy ?? '', 'preset' => $preset ?? '', 'listField' => $listField ?? '',
        'listLimit' => $listLimit ?? 5, 'listMax' => $listMax ?? 1000,
    ]);

    $nav = $clickTarget !== '' && $clickAction === ''
        ? \Mad\Ui\MadAction::navTarget($clickTarget, 'show', [$xParam, $yParam])
        : null;
    $clickable = $clickAction !== '' || $nav !== null;

    $uid = \Mad\Chart\MadHeatmap::domId($name . '|' . $xField . '|' . $yField . '|' . (is_string($model ?? null) ? $model : ''));
    $xT = $xTitle !== '' ? $xTitle : mad_t('mad.heatmap.x_title');
    $yT = $yTitle !== '' ? $yTitle : mad_t('mad.heatmap.y_title');
    $cols = count($hm['x']);
    $isEmpty = $cols === 0 || count($hm['y']) === 0;

    $__debug = !$noDebug && \Mad\Support\MadDebug::sqlPanel();
@endphp

<div class="mad-heatmap {{ $class }}" id="{{ $uid }}" data-mad-heatmap
     @if($name !== '') data-hm-name="{{ $name }}" @endif
     data-hm-x-param="{{ $xParam }}" data-hm-y-param="{{ $yParam }}"
     @if($clickAction !== '') data-hm-action="{{ $clickAction }}" @endif
     @if($nav) data-hm-target="{{ $nav['class'] }}" data-hm-method="{{ $nav['method'] }}" data-hm-url="{{ $nav['url'] }}" @endif
     @if($clickEmpty) data-hm-click-empty @endif
     style="--hm-cols: {{ max(1, $cols) }};{{ $style }}">

    @if($title !== '')
        <div class="mad-hm-title" id="{{ $uid }}-title">{{ $title }}</div>
    @endif

    @if($isEmpty)
        <div class="mad-hm-empty">{{ mad_t('mad.chart.no_data') }}</div>
    @else
    <div class="mad-hm-body">
        @if($yTitle !== '')
            <div class="mad-hm-ytitle" aria-hidden="true"><span>{{ $yTitle }}</span></div>
        @endif
        <div class="mad-hm-scroll">
            <table class="mad-hm-table" @if($title !== '') aria-labelledby="{{ $uid }}-title" @endif>
                <caption class="mad-hm-sr">{{ $title !== '' ? $title . ' — ' : '' }}{{ $yT }} × {{ $xT }}</caption>
                <colgroup>
                    <col class="mad-hm-col-y">
                    <col span="{{ max(1, $cols) }}">
                </colgroup>
                <tbody>
                    @foreach($hm['y'] as $yi => $y)
                        <tr>
                            <th scope="row" class="mad-hm-ylabel"><span>{{ $y['label'] }}</span></th>
                            @foreach($hm['x'] as $xi => $x)
                                @php
                                    $cell = $hm['cells'][$y['key']][$x['key']];
                                    $band = $cell['band'] !== null ? $hm['bands'][$cell['band']] : null;
                                    $tone = $band ? $band['tone'] : ($cell['heat'] > 0 ? 'heat-' . $cell['heat'] : 'none');
                                    $cellStyle = ($band && $band['tone'] === 'custom' && $band['color'] !== '') ? '--hm-tone:' . $band['color'] . ';' : '';
                                    $n = (int) $cell['value'];
                                    $countText = $cell['value'] > 0
                                        ? $cell['display'] . ' ' . ($n === 1 && $cell['display'] === '1' ? mad_t('mad.heatmap.record') : mad_t('mad.heatmap.records'))
                                        : mad_t('mad.heatmap.none');
                                    $aria = $yT . ': ' . $y['label'] . ', ' . $xT . ': ' . $x['label'] . ' — ' . $countText
                                          . ($band && $band['label'] !== '' ? ', ' . mad_t('mad.heatmap.band') . ' ' . $band['label'] : '');
                                    $hasPop = !empty($cell['items']);
                                    $isLink = $clickable && ($cell['value'] > 0 || $clickEmpty);
                                    $popId = $uid . '-p' . $yi . '-' . $xi;
                                    $edge = ($xi >= $cols - 2 && $cols > 2 ? ' mad-hm-pop--end' : ($xi < 1 ? ' mad-hm-pop--start' : ''))
                                          . ($yi === 0 ? ' mad-hm-pop--below' : '');
                                @endphp
                                <td class="mad-hm-td">
                                    <button type="button"
                                            class="mad-hm-cell mad-hm-tone-{{ $tone }}{{ $isLink ? ' is-clickable' : '' }}{{ $cell['value'] > 0 ? '' : ' is-empty' }}"
                                            data-hm-cell data-hm-x="{{ $x['key'] }}" data-hm-y="{{ $y['key'] }}" data-hm-value="{{ $cell['value'] }}"
                                            @if($cellStyle !== '') style="{{ $cellStyle }}" @endif
                                            @if(!$isLink && !$hasPop) aria-disabled="true" @endif
                                            @if($hasPop) aria-describedby="{{ $popId }}" @endif
                                            aria-label="{{ $aria }}">
                                        @if($showBubbles && $cell['value'] > 0)
                                            <span class="mad-hm-bubble" style="--hm-s: {{ $cell['size'] }}" aria-hidden="true">{{ $cell['display'] }}</span>
                                        @endif
                                    </button>
                                    @if($hasPop)
                                        <div class="mad-hm-pop{{ $edge }}" id="{{ $popId }}" role="tooltip">
                                            <div class="mad-hm-pop-head">{{ $y['label'] }} × {{ $x['label'] }} · {{ $countText }}</div>
                                            <ul>
                                                @foreach($cell['items'] as $item)
                                                    <li>{{ $item }}</li>
                                                @endforeach
                                            </ul>
                                            @if($cell['more'] > 0)
                                                <div class="mad-hm-pop-more">{{ mad_t('mad.heatmap.more', ['n' => $cell['more']]) }}</div>
                                            @endif
                                        </div>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="mad-hm-xrow">
                        <td></td>
                        @foreach($hm['x'] as $x)
                            <th scope="col" class="mad-hm-xlabel"><span>{{ $x['label'] }}</span></th>
                        @endforeach
                    </tr>
                </tfoot>
            </table>
            @if($xTitle !== '')
                <div class="mad-hm-xtitle" aria-hidden="true">{{ $xTitle }}</div>
            @endif
        </div>
    </div>
    @endif

    @if($showLegend && !$isEmpty)
        @if(!empty($hm['bands']))
            <ul class="mad-hm-legend">
                @foreach($hm['bands'] as $b)
                    <li>
                        <span class="mad-hm-swatch mad-hm-tone-{{ $b['tone'] }}" @if($b['tone'] === 'custom' && $b['color'] !== '') style="--hm-tone: {{ $b['color'] }}" @endif aria-hidden="true"></span>
                        <span class="mad-hm-legend-label">{{ $b['label'] }}</span>
                        @if($b['range'] !== '')<span class="mad-hm-legend-range">{{ $b['range'] }}</span>@endif
                    </li>
                @endforeach
            </ul>
        @elseif($hm['max'] > 0)
            <div class="mad-hm-legend mad-hm-legend--scale" aria-hidden="true">
                <span>{{ mad_t('mad.heatmap.legend_less') }}</span>
                @for($i = 1; $i <= 5; $i++)<span class="mad-hm-swatch mad-hm-tone-heat-{{ $i }}"></span>@endfor
                <span>{{ mad_t('mad.heatmap.legend_more') }}</span>
            </div>
        @endif
    @endif

    @if($__debug && ($hm['error'] || $hm['sql']))
        @if($hm['error'])
            <div class="mad-hm-error">{{ mad_t('mad.chart.debug.error') }}: {{ $hm['error'] }}</div>
        @endif
        @if($hm['sql'])
            @include('components.partials.db-chart-debug', [
                'debugSql' => $hm['sql'], 'debugInlined' => $hm['sql'], 'debugBinds' => $hm['binds'],
                'chartName' => $uid,
            ])
        @endif
    @endif
</div>
