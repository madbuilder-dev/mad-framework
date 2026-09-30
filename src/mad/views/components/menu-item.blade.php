@php
    $icon     = $icon     ?? '';
    $shortcut = $shortcut ?? '';
    $variant  = $variant  ?? '';
    $href     = $href     ?? '';
    $disabled = !empty($disabled);
    $class    = $class    ?? '';
    $attrs    = $attrs    ?? '';
    $variantClass = $variant ? " mad-context-item-{$variant}" : '';
@endphp
@if($href)
<a href="{{ $href }}"
   class="mad-context-item{{ $variantClass }} {{ $class }}"
   role="menuitem"
   @if($disabled) aria-disabled="true" @endif
   {!! $attrs !!}>
    @if($icon)<i data-lucide="{{ $icon }}" class="mad-context-item-icon"></i>@endif
    <span class="mad-context-item-label">{!! $slot !!}</span>
    @if($shortcut)<kbd class="mad-context-kbd">{{ $shortcut }}</kbd>@endif
</a>
@else
<button type="button"
        class="mad-context-item{{ $variantClass }} {{ $class }}"
        role="menuitem"
        @if($disabled) disabled @endif
        {!! $attrs !!}>
    @if($icon)<i data-lucide="{{ $icon }}" class="mad-context-item-icon"></i>@endif
    <span class="mad-context-item-label">{!! $slot !!}</span>
    @if($shortcut)<kbd class="mad-context-kbd">{{ $shortcut }}</kbd>@endif
</button>
@endif
