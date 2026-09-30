@php
    // Um nó da árvore (<li> + card + filhos recursivos). Renderizado pelo
    // org-chart.blade.php E pelo MadOrgChart::onLoadChildren (lazy). Sem
    // host/node (render direto/preview), aborta cedo.
    if (!isset($__component) || !$__component instanceof \Mad\OrgChart\MadOrgChart || !isset($node['record'])) {
        return;
    }
    /** @var \Mad\OrgChart\MadOrgChart $__component */
    $record   = $node['record'];
    $card     = $__component->cardData($record);
    $children = $node['children'];
    $hasKids  = !empty($children) || $node['hasMore'];
    $custom   = $__component->renderCustomCard($record);
@endphp
<li class="mad-oc-node" data-oc-id="{{ $card['id'] }}">
    <div class="mad-oc-card"
         data-oc-card="{{ $card['id'] }}"
         data-oc-search="{{ mb_strtolower($card['title'] . ' ' . $card['subtitle']) }}"
         @if ($__component->isDraggable()) draggable="true" @endif>
        @if ($custom !== '')
            {!! $custom !!}
        @else
            <div class="mad-oc-avatar">
                @if ($card['avatar'] !== '')
                    <img src="{{ $card['avatar'] }}" alt="" loading="lazy">
                @else
                    <span>{{ $card['initials'] }}</span>
                @endif
            </div>
            <div class="mad-oc-card-body">
                <div class="mad-oc-title" title="{{ $card['title'] }}">{{ $card['title'] }}</div>
                @if ($card['subtitle'] !== '')
                    <div class="mad-oc-subtitle" title="{{ $card['subtitle'] }}">{{ $card['subtitle'] }}</div>
                @endif
            </div>
            @if ($card['metric'] !== '')
                <span class="mad-oc-metric" title="{{ $__component->getMetricLabel() }}">{{ $card['metric'] }}</span>
            @endif
        @endif

        @if ($hasKids)
            <button type="button" class="mad-oc-toggle"
                    data-oc-toggle="{{ $card['id'] }}"
                    @if ($node['hasMore']) data-oc-lazy="1" @endif
                    title="{{ $node['count'] }}">
                <span class="mad-oc-toggle-count">{{ $node['count'] }}</span>
            </button>
        @endif
    </div>

    @if ($hasKids)
        <ul class="mad-oc-tree" data-oc-children="{{ $card['id'] }}">
            @foreach ($children as $child)
                @include('components.org-chart-node', ['__component' => $__component, 'node' => $child])
            @endforeach
        </ul>
    @endif
</li>
