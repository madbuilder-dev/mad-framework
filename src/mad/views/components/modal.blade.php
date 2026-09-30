@props(['name' => '', 'title' => '', 'size' => 'md', 'footer' => '', 'dismissible' => true, 'class' => ''])
@php
    $dismissible = !empty($dismissible);
    $sizes = ['sm' => '380px', 'md' => '520px', 'lg' => '680px', 'xl' => '900px'];
    // idem drawer: `size` fora do mapa é medida livre e ia crua pro style.
    $maxW  = \Mad\Support\CssUnits::length((string) ($sizes[$size] ?? $size), $sizes['md']);
@endphp
<div x-data="{ open: false }"
     @madmodal.window="$event.detail.name === '{{ $name }}' && (open = $event.detail.action === 'open')"
     x-cloak>
    {{-- data-mad-overlay*: o Esc global (Mad.overlayEsc, mad.js) fecha a
         modal do TOPO por aqui — nome e se pode ser dispensada. --}}
    <div class="mad-modal-overlay {{ $class }}"
         data-mad-overlay="modal" data-mad-overlay-name="{{ $name }}" data-mad-dismissible="{{ $dismissible ? '1' : '0' }}"
         x-show="open"
         x-transition
         @if($dismissible) @click.self="open = false; $dispatch('madmodal', { name: '{{ $name }}', action: 'close' })" @endif>
        <div class="mad-modal" style="max-width:{{ $maxW }};">
            @if($title)
                <div class="mad-modal-header">
                    <div class="mad-modal-title">{{ $title }}</div>
                    @if($dismissible)
                        <button type="button"
                                class="mad-btn mad-btn-ghost mad-btn-sm mad-btn-icon"
                                @click="open = false; $dispatch('madmodal', { name: '{{ $name }}', action: 'close' })">
                            <i data-lucide="x" style="width:16px;height:16px;"></i>
                        </button>
                    @endif
                </div>
            @endif
            <div class="mad-modal-body">
                {!! $slot !!}
            </div>
            @if($footer)
                <div class="mad-modal-footer">{!! $footer !!}</div>
            @endif
        </div>
    </div>
</div>
