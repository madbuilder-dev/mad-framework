@php
    // Template interno — só renderiza via MadKanban::_renderInlineKanban, que
    // injeta $__component. Sem host (render direto/preview), aborta cedo em vez
    // de fatalar com "getStages() on null".
    if (!isset($__component) || !$__component instanceof \Mad\Calendar\MadKanban) {
        return;
    }
    $stages     = $__component->getStages();
    $counts     = $__component->getStageCounts();
    $totals     = $__component->getStageTotals();
    $clickTarget = $__component->cardClickTarget();
    // Rota do alvo do clique ASSADA no servidor (o mapa de rotas não vive no
    // client). `__MAD_id__` é substituído pelo id do card na hora do clique —
    // cobre inclusive id no PATH (`/app/clientes/__MAD_id__/editar`).
    $clickNav = \Mad\Ui\MadAction::navTarget((string) ($clickTarget ?? ''), 'onShow');
    $draggable  = $__component->isDraggable();
    $perLoad    = $__component->getCardsPerLoad();
    $topScroll  = method_exists($__component, 'hasTopScroll') ? $__component->hasTopScroll() : false;
    $titleField = method_exists($__component, 'getStageTitleField') ? $__component->getStageTitleField() : 'nome';
    $colorField = method_exists($__component, 'getStageColorField') ? $__component->getStageColorField() : 'cor';
    $hasValue   = !empty($totals);
    $fwdParams  = \Mad\Ui\MadAction::getForwardParams();

    $stagesJson = array_map(function($s) use ($counts, $__component) {
        $sid = $__component->stageId($s);
        return ['id' => $sid, 'count' => $counts[$sid] ?? 0];
    }, $stages);
@endphp

@php
    $toolbarHtml = method_exists($__component, 'getToolbarHtml') ? $__component->getToolbarHtml() : '';
@endphp

@if ($toolbarHtml !== '')
<div class="mad-kanban-toolbar">{!! $toolbarHtml !!}</div>
@endif

@if ($topScroll)
<div class="mad-kanban-wrap" x-data="madKanbanScrollSync()">
    <div class="mad-kanban-top-scroll">
        <div class="mad-kanban-top-scroll-inner"></div>
    </div>
@endif

<div class="mad-kanban-board"
     x-data="madKanban({
         stages: {{ json_encode(array_values($stagesJson)) }},
         cardsPerLoad: {{ $perLoad }},
         draggable: {{ $draggable ? 'true' : 'false' }},
         clickTarget: {{ json_encode((string) ($clickNav['class'] ?? '')) }},
         clickMethod: {{ json_encode((string) ($clickNav['method'] ?? 'onShow')) }},
         clickUrl: {{ json_encode((string) ($clickNav['url'] ?? '')) }},
         forwardParams: {{ json_encode($fwdParams ?: new \stdClass(), JSON_FORCE_OBJECT) }}
     })">

    @foreach($stages as $stage)
    @php $sid = $__component->stageId($stage); @endphp
    {{-- stage id nunca é interpolado dentro de expressão Alpine (id com aspa
         quebraria/injetaria JS) — as expressões leem $el.dataset.stageId. --}}
    <div class="mad-kanban-col" data-stage-id="{{ $sid }}"
         :class="{ 'mad-kanban-col--drag-over': dragOverStage === $el.dataset.stageId }">

        {{-- Header --}}
        @php
            $stageActions = method_exists($__component, 'getStageActions') ? $__component->getStageActions($stage) : [];
        @endphp
        <div class="mad-kanban-col-header">
            <div style="display:flex;align-items:center;gap:8px;min-width:0;">
                <div class="mad-kanban-col-dot" style="background-color:{{ $__component->safeColor($stage->{$colorField} ?? null, 'var(--mad-text-muted, #71717a)') }}"></div>
                <span class="mad-kanban-col-title">{{ $stage->{$titleField} ?? '' }}</span>
                <span class="mad-kanban-col-count" data-stage-count="{{ $sid }}">{{ $counts[$sid] ?? 0 }}</span>
            </div>
            @if (!empty($stageActions))
                <div class="mad-kanban-col-actions">
                    @foreach ($stageActions as $sa)
                        <button type="button"
                                class="mad-kanban-col-action-btn @if($sa['variant']) mad-kanban-col-action-btn--{{ $sa['variant'] }} @endif"
                                @if($sa['label']) title="{{ $sa['label'] }}" @endif
                                {!! $sa['attrs'] !!}>
                            @if ($sa['icon'])
                                <i data-lucide="{{ $sa['icon'] }}"></i>
                            @endif
                            @if ($sa['label'] && !$sa['icon'])
                                <span>{{ $sa['label'] }}</span>
                            @endif
                        </button>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Total monetario --}}
        @if($hasValue)
        <div class="mad-kanban-col-summary" data-stage-summary="{{ $sid }}">
            @if(isset($totals[$sid]) && $totals[$sid] > 0)
            {{ $__component->formatStageTotal((float) $totals[$sid]) }}
            @endif
        </div>
        @endif

        {{-- Cards area --}}
        @php
            $stageCards   = $__component->getCardsForStage($sid);
            $emptyActions = method_exists($__component, 'getStageEmptyActions') ? $__component->getStageEmptyActions($stage) : [];
        @endphp
        <div class="mad-kanban-cards"
             data-stage-id="{{ $sid }}"
             @scroll="onScrollCards($event, $el.dataset.stageId)"
             @dragover.prevent="onDragOver($event, $el.dataset.stageId)"
             @dragleave="onDragLeave($event, $el.dataset.stageId)"
             @drop="onDrop($event, $el.dataset.stageId)">

            @foreach($stageCards as $card)
                {!! $__component->renderCard($card) !!}
            @endforeach

            {{-- Empty state — placeholder dashed quando coluna vazia --}}
            @if (empty($stageCards) && !empty($emptyActions))
                <div class="mad-kanban-empty">
                    @foreach ($emptyActions as $ea)
                        <button type="button"
                                class="mad-kanban-empty-btn @if($ea['variant']) mad-kanban-empty-btn--{{ $ea['variant'] }} @endif"
                                {!! $ea['attrs'] !!}>
                            @if ($ea['icon'])
                                <i data-lucide="{{ $ea['icon'] }}"></i>
                            @endif
                            <span>{{ $ea['label'] ?: mad_t('mad.kanban.add') }}</span>
                        </button>
                    @endforeach
                </div>
            @endif

            {{-- Loading indicator (pai imediato é .mad-kanban-cards, que carrega o data-stage-id) --}}
            <div class="mad-kanban-loading"
                 x-show="loadingMore[$el.parentElement.dataset.stageId]" x-cloak>
                {{ mad_t('mad.kanban.loading') }}
            </div>
        </div>
    </div>
    @endforeach
</div>
@if ($topScroll)
</div>
@endif
