@php
    $trigger = $trigger ?? '';   // HTML string for the trigger button
    $align   = $align   ?? 'start';   // start | end
    $class   = $class   ?? '';
    $menuClass = $align === 'end' ? 'mad-dropdown-end' : '';
    // position="left|center|right" — lado do dropdown dentro do <mad-form-actions>
    // (`align` aqui é o lado em que o MENU abre, outra coisa).
    $position  = $position ?? '';
    $posClass  = in_array($position, ['left', 'center', 'right'], true) ? " mad-pos-{$position}" : '';
    // Gatilho por `label`/`icon` (como o catálogo documenta) quando não vem
    // `trigger` em HTML — antes o gatilho saía vazio e o menu não abria
    // (fw#319). `variant` = a do <mad-btn> (secondary por padrão).
    $label   = is_string($label ?? null) ? $label : '';
    $icon    = is_string($icon ?? null) ? $icon : '';
    $title   = is_string($title ?? null) ? $title : '';
    $variant = is_string($variant ?? null) && $variant !== '' ? $variant : 'secondary';
    $_autoTrigger = trim((string) $trigger) === '' && ($label !== '' || $icon !== '');
    // O menu nunca sai da tela (fw#325): ao abrir, e ao redimensionar a janela
    // com ele aberto, o mad-ui.js confere e acerta o lado. `align` continua
    // sendo o lado preferido. Com um mad-ui.js antigo em cache, nada acontece.
@endphp
<div class="mad-dropdown{{ $posClass }} {{ $class }}"
     style="position:relative;display:inline-block;"
     x-data="{ open: false }"
     x-effect="open && $nextTick(() => window.madDropdownPlace && window.madDropdownPlace($el))"
     @resize.window="open && window.madDropdownPlace && window.madDropdownPlace($el)"
     @keydown.escape.window="open = false"
     @click.outside="open = false">
    <div @click="open = !open">
        @if($_autoTrigger)
        <button type="button" class="mad-btn mad-btn-{{ $variant }}" aria-haspopup="menu" :aria-expanded="open ? 'true' : 'false'"
                @if($title !== '') title="{{ $title }}" @endif
                @if($label === '') aria-label="{{ $title !== '' ? $title : 'Menu' }}" @endif>
            @if($icon !== '')<i data-lucide="{{ $icon }}" style="width:16px;height:16px;"></i>@endif
            @if($label !== ''){{ $label }}<i data-lucide="chevron-down" style="width:14px;height:14px;"></i>@endif
        </button>
        @else
        {!! $trigger !!}
        @endif
    </div>
    <div class="mad-dropdown-menu {{ $menuClass }}"
         x-show="open"
         x-cloak
         @click="open = false">
        {!! $slot !!}
    </div>
</div>
