{{--
    <mad-map> — mapa com marcadores (Leaflet + mapa do OpenStreetMap, servidos
    pelo pacote: nenhum CDN em runtime).

    <mad-map model="Unidade" lat-field="latitude" lng-field="longitude"
        title-field="{codigo} - {nome}" popover-content="{cidade}/{uf} · {situacao}"
        color-field="situacao" :color-map="['ativa' => 'success', 'obra' => 'warning', 'fechada' => '#71717a']"
        click-target="UnidadeForm::onEdit({id})" zoom="7" center="auto" height="520" cluster>
        <mad-map-layer model="Obra" lat-field="lat" lng-field="lng" icon="hard-hat" label="Obras" />
    </mad-map>

    Dados: Mad\Chart\MadMap (linha sem coordenada fica fora, sem erro). Dentro
    de um painel, o período, a unidade e os filtros do painel valem como nos
    outros widgets. Desenho: lib/mad/mad-map.js, carregado sob demanda.
    Clique:
      click-target="Tela::metodo({campo})" → Mad.go(Tela, metodo, {campo: valor})
      click-action="metodo({campo})"       → MadWire.call(tela, metodo, [valor])
      sempre                               → evento `mad-map-click` {name, layer, id, lat, lng}
    Guia: docs/mad-map.md
--}}
@php
    $__b = static fn ($v, bool $d): bool => \Mad\Chart\MadMap::bool($v, $d);

    $title  = (string) ($title ?? '');
    $name   = (string) ($name ?? '');
    $class  = (string) ($class ?? '');
    $style  = (string) ($style ?? '');
    $noDebug    = $__b($noDebug ?? null, false);
    $showLegend = $__b($legend ?? null, true);
    $cluster    = $__b($cluster ?? null, false);
    $mapHeight  = \Mad\Support\CssUnits::length((string) ($height ?? ''), '420px');

    $__host = \Mad\Component\MadRenderContext::getComponent();
    $__host = $__host instanceof \Mad\Dashboard\MadDashboard ? $__host : null;

    $__main = \Mad\Chart\MadMap::fromProps([
        'model' => $model ?? '', 'database' => $database ?? '', 'query' => $query ?? null, 'data' => $data ?? null,
        'filters' => $filters ?? [], 'unitField' => $unitField ?? '',
        'latField' => $latField ?? '', 'lngField' => $lngField ?? '', 'keyField' => $keyField ?? '',
        'titleField' => $titleField ?? '', 'popoverContent' => $popoverContent ?? '',
        'colorField' => $colorField ?? '', 'colorMap' => $colorMap ?? null, 'color' => $color ?? '',
        'icon' => $icon ?? '', 'label' => $label ?? '', 'limit' => $limit ?? null,
        'clickTarget' => $clickTarget ?? '', 'clickAction' => $clickAction ?? '',
    ], $__host);

    $__layers = [$__main];
    foreach (array_slice(\Mad\Chart\MadMap::takeLayers(), 0, \Mad\Chart\MadMap::MAX_LAYERS - 1) as $__lp) {
        $__layers[] = \Mad\Chart\MadMap::fromProps($__lp, $__host);
    }

    [$__center, $__zoom] = \Mad\Chart\MadMap::view($center ?? null, $zoom ?? null);
    $__tiles = trim((string) ($tiles ?? ''));
    if ($__tiles !== '' && !preg_match('#^(https?:)?//[^\s"\'<>]+\{z\}[^\s"\'<>]*$#i', $__tiles)) {
        $__tiles = '';
    }

    $uid = \Mad\Chart\MadMap::domId($name . '|' . (is_string($model ?? null) ? $model : '') . '|' . ($latField ?? ''));
    $__count = 0;
    $__skipped = 0;
    $__truncated = 0;
    $__js = [];
    foreach ($__layers as $__i => $__l) {
        $__count += count($__l['points']);
        $__skipped += $__l['skipped'];
        if ($__l['truncated']) $__truncated = max($__truncated, count($__l['points']));
        $__js[] = [
            'label' => $__l['label'], 'icon' => $__l['icon'], 'color' => $__l['color'], 'hidden' => $__l['hidden'],
            'click' => $__l['click'], 'points' => $__l['points'],
        ];
    }
    $__multi = count($__layers) > 1;
    $__legend = $__main['legend'];
    $__debug = !$noDebug && \Mad\Support\MadDebug::sqlPanel();

    $__cfg = [
        'name' => $name !== '' ? $name : $uid, 'center' => $__center, 'zoom' => $__zoom, 'cluster' => $cluster,
        'tiles' => $__tiles, 'layers' => $__js,
        'text' => ['loadError' => mad_t('mad.map.load_error')],
    ];
@endphp

<div class="mad-map {{ $class }}" id="{{ $uid }}" data-mad-map @if($name !== '') data-map-name="{{ $name }}" @endif
     @if($style !== '') style="{{ $style }}" @endif>
    @if($title !== '')
        <div class="mad-map-title" id="{{ $uid }}-title">{{ $title }}</div>
    @endif

    <div class="mad-map-canvas" data-map-canvas style="height:{{ $mapHeight }}" role="region"
         @if($title !== '') aria-labelledby="{{ $uid }}-title" @else aria-label="{{ $__main['label'] !== '' ? $__main['label'] : mad_t('mad.map.legend') }}" @endif>
        <div class="mad-map-status" data-map-status>{{ mad_t('mad.map.loading') }}</div>
    </div>

    @if($__count === 0)
        <div class="mad-map-empty">{{ mad_t('mad.map.empty') }}</div>
    @endif

    @if($showLegend && ($__multi || $__legend !== []))
        <div class="mad-map-legend" aria-label="{{ mad_t('mad.map.legend') }}">
            @if($__multi)
                <div class="mad-map-legend-group" role="group" aria-label="{{ mad_t('mad.map.layers') }}">
                    @foreach($__layers as $__i => $__l)
                        <label class="mad-map-layer-toggle">
                            <input type="checkbox" data-map-layer-toggle="{{ $__i }}" @checked(!$__l['hidden'])>
                            <span class="mad-map-swatch" @if($__l['color'] !== '') style="--mad-map-swatch: {{ $__l['color'] }}" @endif aria-hidden="true">
                                @if($__l['icon'] !== '')<i data-lucide="{{ $__l['icon'] }}"></i>@endif
                            </span>
                            <span>{{ $__l['label'] !== '' ? $__l['label'] : mad_t('mad.map.layers') . ' ' . ($__i + 1) }}</span>
                            <span class="mad-map-legend-count">{{ count($__l['points']) }}</span>
                        </label>
                    @endforeach
                </div>
            @endif
            @if($__legend !== [])
                <ul class="mad-map-legend-group">
                    @foreach($__legend as $__e)
                        <li><span class="mad-map-swatch" style="--mad-map-swatch: {{ $__e['color'] }}" aria-hidden="true"></span>{{ $__e['label'] }}</li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    @if($__skipped > 0 || $__truncated > 0)
        <div class="mad-map-note">
            @if($__skipped > 0){{ mad_t('mad.map.skipped', ['n' => $__skipped]) }}@endif
            @if($__truncated > 0){{ mad_t('mad.map.truncated', ['n' => $__truncated]) }}@endif
        </div>
    @endif

    <script type="application/json" data-map-config>{!! json_encode($__cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) !!}</script>
    <script>
    (function () {
        var el = document.getElementById({!! json_encode($uid, JSON_HEX_TAG) !!});
        if (!el) return;
        if (window.MadMap && typeof window.MadMap.mount === 'function') { window.MadMap.mount(el); return; }
        (window.__madMapQueue = window.__madMapQueue || []).push(el);
        if (!document.querySelector('script[data-mad-map-lib]')) {
            var s = document.createElement('script');
            s.src = {!! json_encode(\Mad\Support\MadFramework::asset('lib/mad/mad-map.js'), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!};
            s.setAttribute('data-mad-map-lib', '');
            document.head.appendChild(s);
        }
    })();
    </script>

    @if($__debug)
        @foreach($__layers as $__i => $__l)
            @if($__l['error'])
                <div class="mad-map-error">{{ mad_t('mad.chart.debug.error') }}: {{ $__l['error'] }}</div>
            @endif
            @if($__l['sql'])
                @include('components.partials.db-chart-debug', [
                    'debugSql' => $__l['sql'], 'debugInlined' => $__l['sql'], 'debugBinds' => $__l['binds'],
                    'chartName' => $uid . '-' . $__i,
                ])
            @endif
        @endforeach
    @endif
</div>
