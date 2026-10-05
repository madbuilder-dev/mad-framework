{{--
    Card default do MadKanban — slot-based generico.

    Vars (resolvidos por MadKanban::_resolveCardVars):
      $item           - model Eloquent do card
      $id             - id (int|string — UUID/código preservado)
      $attrs          - cardAttrs() ja string
      $titulo         - string
      $badges         - list<['label','color','muted']>
      $meta           - list<['icon','value','sub','muted']>
      $footer         - list<['type','icon','display','class']>
      $customHtml     - HTML do body do <mad-kanban-card> (modo misto: trio + custom)
      $actions        - list<['label','icon','variant','confirm','attrs']> (modo menu/dropdown)
      $actionsInline  - mesma estrutura, mas renderiza direto no card (flag `inline`)
      $actionsMode    - menu | inline | dropdown — controla onde $actions vai
--}}
<div class="mad-kanban-card" {!! $attrs !!}>
    {{-- data-card-actions: guard do onCardClick (mad-ui.js) — clicks aqui não
         abrem o click-target do card. NÃO usar @click.stop no menu: a
         delegation do data-mad-click (mad-livewire.js) é bubble-phase no
         document; .stop engolia o clique e NENHUMA action de dropdown
         disparava o wire. --}}
    <div class="mad-kanban-card-actions" data-card-actions>
        @if (!empty($actions) && $actionsMode === 'menu')
            <div class="mad-kanban-card-menu-wrap" x-data="{ open: false }"
                 @click.away="open = false"
                 @keydown.escape.window="open = false"
                 @mad-kanban-menus-close.window="open = false">
                <button type="button" class="mad-kanban-card-menu-btn"
                        @click.stop="open = !open; if (open) $nextTick(() => window._madKanbanAnchorMenu($refs.menu, $el))">
                    <i data-lucide="ellipsis"></i>
                </button>
                <div class="mad-kanban-card-menu" x-ref="menu" x-show="open" x-cloak>
                    @foreach ($actions as $a)
                        <button type="button"
                                class="mad-kanban-card-menu-item @if($a['variant']) mad-kanban-card-menu-item--{{ $a['variant'] }} @endif"
                                {!! $a['attrs'] !!}
                                @click="open = false">
                            @if ($a['icon'])
                                <i data-lucide="{{ $a['icon'] }}"></i>
                            @endif
                            <span>{{ $a['label'] }}</span>
                        </button>
                    @endforeach
                </div>
            </div>
        @endif
        <div class="mad-kanban-card-grip" data-drag-handle>
            <i data-lucide="grip-vertical"></i>
        </div>
    </div>

    @if (!empty($badges))
    <div class="mad-kanban-card-badges">
        @foreach ($badges as $b)
            @if (!empty($b['muted']))
                <span class="mad-kanban-badge--muted">{{ $b['label'] }}</span>
            @else
                <span class="mad-kanban-badge" style="background-color:{{ $b['color'] }}">{{ $b['label'] }}</span>
            @endif
        @endforeach
    </div>
    @endif

    <p class="mad-kanban-card-title">
        @if (!empty($tituloHtml))
            {!! $titulo !!}
        @else
            {{ $titulo }}
        @endif
    </p>

    @foreach ($meta as $m)
        <div class="mad-kanban-card-meta mad-kanban-card-meta--{{ $m['position'] ?? 'left' }}"
             @if($loop->last && empty($metaInline) && empty($metaGroups)) style="margin-bottom:0;" @endif>
            <div class="mad-kanban-card-meta-row">
                @if (!empty($m['icon']))
                    <i data-lucide="{{ $m['icon'] }}"></i>
                @endif
                <span @if(!empty($m['muted'])) style="font-weight:400;color:var(--mad-text-muted, #71717a);" @endif>{{ $m['value'] }}</span>
            </div>
            @if (!empty($m['sub']))
                <div class="mad-kanban-card-meta-sub">{{ $m['sub'] }}</div>
            @endif
        </div>
    @endforeach

    @if (!empty($metaInline))
        <div class="mad-kanban-card-meta-inline">
            @foreach ($metaInline as $m)
                <span class="mad-kanban-card-meta-chip @if(!empty($m['muted'])) mad-kanban-card-meta-chip--muted @endif @if(($m['position'] ?? '') === 'right') mad-kanban-card-meta-chip--right @endif"
                      @if(!empty($m['sub'])) title="{{ $m['sub'] }}" @endif>
                    @if (!empty($m['icon']))
                        <i data-lucide="{{ $m['icon'] }}"></i>
                    @endif
                    <span>{{ $m['value'] }}</span>
                </span>
            @endforeach
        </div>
    @endif

    @if (!empty($metaGroups))
        @foreach ($metaGroups as $g)
            <div class="mad-kanban-card-meta-group mad-kanban-card-meta-group--{{ $g['direction'] }} mad-kanban-card-meta-group--justify-{{ $g['justify'] }} mad-kanban-card-meta-group--align-{{ $g['align'] }}"
                 style="gap:{{ \Mad\Support\CssUnits::length((string) ($g['gap'] ?? ''), '0px') }};">
                @foreach ($g['metas'] as $m)
                    @if ($g['direction'] === 'row')
                        <span class="mad-kanban-card-meta-chip @if(!empty($m['muted'])) mad-kanban-card-meta-chip--muted @endif"
                              @if(!empty($m['sub'])) title="{{ $m['sub'] }}" @endif>
                            @if (!empty($m['icon']))
                                <i data-lucide="{{ $m['icon'] }}"></i>
                            @endif
                            <span>{{ $m['value'] }}</span>
                        </span>
                    @else
                        <div class="mad-kanban-card-meta">
                            <div class="mad-kanban-card-meta-row">
                                @if (!empty($m['icon']))
                                    <i data-lucide="{{ $m['icon'] }}"></i>
                                @endif
                                <span @if(!empty($m['muted'])) style="font-weight:400;color:var(--mad-text-muted, #71717a);" @endif>{{ $m['value'] }}</span>
                            </div>
                            @if (!empty($m['sub']))
                                <div class="mad-kanban-card-meta-sub">{{ $m['sub'] }}</div>
                            @endif
                        </div>
                    @endif
                @endforeach
            </div>
        @endforeach
    @endif

    @if (!empty($customHtml))
        {!! $customHtml !!}
    @endif

    @if (!empty($footer))
        <div class="mad-kanban-card-footer">
            @foreach ($footer as $i => $f)
                @if ($f['type'] === 'money')
                    <span class="mad-kanban-card-value {{ $f['class'] }}">{!! $f['display'] !!}</span>
                @elseif ($f['type'] === 'date')
                    <div class="mad-kanban-card-date {{ $f['class'] }}">
                        @if (!empty($f['icon']))
                            <i data-lucide="{{ $f['icon'] }}"></i>
                        @endif
                        {{ $f['display'] }}
                    </div>
                @else
                    {{-- Rodapé de texto (ex.: vendedor): classe própria — sem ela o
                         ícone saía no tamanho padrão do Lucide (24px). --}}
                    <span class="mad-kanban-card-foot-text {{ $f['class'] }}">
                        @if (!empty($f['icon']))
                            <i data-lucide="{{ $f['icon'] }}"></i>
                        @endif
                        {{ $f['display'] }}
                    </span>
                @endif
            @endforeach
        </div>
    @endif

    @if (!empty($actionsInline))
        <div class="mad-kanban-card-actions-inline">
            @foreach ($actionsInline as $a)
                <button type="button"
                        class="mad-kanban-card-btn @if($a['variant']) mad-kanban-card-btn--{{ $a['variant'] }} @endif"
                        {!! $a['attrs'] !!}>
                    @if ($a['icon'])
                        <i data-lucide="{{ $a['icon'] }}"></i>
                    @endif
                    <span>{{ $a['label'] }}</span>
                </button>
            @endforeach
        </div>
    @endif

    @if (!empty($actions) && $actionsMode === 'dropdown')
        {{-- Sem @click.stop no menu — ver comentário no topo do card. --}}
        <div class="mad-kanban-card-actions-dropdown" x-data="{ open: false }" data-card-actions
             @click.away="open = false"
             @keydown.escape.window="open = false"
             @mad-kanban-menus-close.window="open = false">
            <button type="button" class="mad-kanban-card-btn mad-kanban-card-btn--dropdown"
                    @click.stop="open = !open; if (open) $nextTick(() => window._madKanbanAnchorMenu($refs.menu, $el))">
                <i data-lucide="chevron-down"></i>
                <span>{{ mad_t('mad.kanban.actions') }}</span>
            </button>
            <div class="mad-kanban-card-menu mad-kanban-card-menu--footer"
                 x-ref="menu" x-show="open" x-cloak>
                @foreach ($actions as $a)
                    <button type="button"
                            class="mad-kanban-card-menu-item @if($a['variant']) mad-kanban-card-menu-item--{{ $a['variant'] }} @endif"
                            {!! $a['attrs'] !!}
                            @click="open = false">
                        @if ($a['icon'])
                            <i data-lucide="{{ $a['icon'] }}"></i>
                        @endif
                        <span>{{ $a['label'] }}</span>
                    </button>
                @endforeach
            </div>
        </div>
    @endif
</div>
