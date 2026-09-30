@php
    // Template interno — só renderiza via MadOrgChart::_renderInlineOrgChart.
    if (!isset($__component) || !$__component instanceof \Mad\OrgChart\MadOrgChart) {
        return;
    }
    $roots  = $__component->getRoots();
    $ocKey  = $__component->ocCfgKey;
    // click-target="EmployeeForm::onShow({id})" — o `::metodo({id})` era mandado
    // CRU pro JS, que usava a string inteira como nome de classe. Aqui o alvo é
    // partido e a rota amigável sai assada (com __MAD_id__ resolvido no clique).
    $ocNav  = \Mad\Ui\MadAction::navTarget($__component->getClickTarget(), 'onShow');
    $ocCfg  = [
        'key'         => $ocKey,
        'draggable'   => $__component->isDraggable(),
        'clickTarget' => (string) ($ocNav['class']  ?? ''),
        'clickMethod' => (string) ($ocNav['method'] ?? 'onShow'),
        'clickUrl'    => (string) ($ocNav['url']    ?? ''),
        'forwardParams' => \Mad\Ui\MadAction::getForwardParams() ?: new \stdClass(),
    ];
    $cfgJson = json_encode($ocCfg, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_FORCE_OBJECT);
@endphp

{{-- x-data com aspas SIMPLES (JSON estrutural usa aspas duplas; HEX_APOS cobre o resto) --}}
<div class="mad-orgchart" data-mad-orgchart="{{ $ocKey }}" x-data='madOrgChart({!! $cfgJson !!})'>

    {{-- Toolbar --}}
    <div class="mad-oc-toolbar">
        <div class="mad-oc-toolbar-left">
            <input type="search" class="mad-oc-search"
                   placeholder="{{ mad_t('mad.orgchart.search') }}"
                   x-model="search" @input.debounce.250ms="onSearch()">
            <span class="mad-oc-search-hits" x-show="search && hits.length" x-cloak>
                <span x-text="hitIndex + 1"></span>/<span x-text="hits.length"></span>
                <button type="button" class="mad-oc-btn mad-oc-btn--sm" @click="nextHit()">&darr;</button>
            </span>
        </div>
        <div class="mad-oc-toolbar-right">
            <button type="button" class="mad-oc-btn" @click="zoomOut()" title="-">&minus;</button>
            <span class="mad-oc-zoom" x-text="Math.round(zoom * 100) + '%'"></span>
            <button type="button" class="mad-oc-btn" @click="zoomIn()" title="+">+</button>
            <button type="button" class="mad-oc-btn" @click="resetView()">{{ mad_t('mad.orgchart.fit') }}</button>
        </div>
    </div>

    @if ($__component->isTruncated())
        <div class="mad-oc-truncated">{{ mad_t('mad.orgchart.truncated') }}</div>
    @endif

    {{-- Viewport (pan/zoom) --}}
    <div class="mad-oc-viewport" x-ref="viewport"
         @mousedown="panStart($event)"
         @mousemove="panMove($event)"
         @mouseup="panEnd()"
         @mouseleave="panEnd()"
         @wheel.prevent="onWheel($event)"
         @click="onChartClick($event)"
         @dragstart="onDragStart($event)"
         @dragover="onDragOver($event)"
         @dragleave="onDragLeave($event)"
         @drop="onDrop($event)"
         @dragend="onDragEnd($event)">
        <div class="mad-oc-canvas" x-ref="canvas" :style="canvasStyle">
            <ul class="mad-oc-tree mad-oc-tree--root">
                @foreach ($roots as $node)
                    @include('components.org-chart-node', ['__component' => $__component, 'node' => $node])
                @endforeach
            </ul>
        </div>
    </div>
</div>
