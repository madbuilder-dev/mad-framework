@props(['name' => '', 'title' => '', 'size' => 'md', 'footer' => '', 'dismissible' => true, 'closeOnBackdrop' => true, 'class' => ''])
@php
    // Mesmo contrato do <mad-drawer>: `dismissible` = X e Esc;
    // `close-on-backdrop` = clique na área escurecida (desligado na tela com
    // $wrapper = MODAL e no <mad-detail-form mode="modal">).
    $dismissible     = is_bool($dismissible) ? $dismissible
        : (filter_var($dismissible, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true);
    $closeOnBackdrop = $dismissible && (is_bool($closeOnBackdrop) ? $closeOnBackdrop
        : (filter_var($closeOnBackdrop, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true));
    $sizes = ['sm' => '380px', 'md' => '520px', 'lg' => '680px', 'xl' => '900px'];
    // idem drawer: `size` fora do mapa é medida livre e ia crua pro style.
    $maxW  = \Mad\Support\CssUnits::length((string) ($sizes[$size] ?? $size), $sizes['md']);
@endphp
<div x-data="{ open: false }"
     @madmodal.window="$event.detail.name === '{{ $name }}' && (open = $event.detail.action === 'open')"
     x-cloak>
    {{-- data-mad-overlay*: o Esc global (MadOverlayEsc, mad.js) fecha a
         modal do TOPO por aqui — nome e se pode ser dispensada. O clique fora
         é do MadOverlayBackdrop (mad.js): só com data-mad-close-on-backdrop="1"
         e com o botão descendo E subindo na área escura. --}}
    <div class="mad-modal-overlay {{ $class }}"
         data-mad-overlay="modal" data-mad-overlay-name="{{ $name }}" data-mad-dismissible="{{ $dismissible ? '1' : '0' }}"
         data-mad-close-on-backdrop="{{ $closeOnBackdrop ? '1' : '0' }}"
         x-show="open"
         x-transition>
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
