@php
    $icon     = $icon     ?? '';
    $variant  = $variant  ?? '';   // danger
    $href     = $href     ?? '';
    $disabled = !empty($disabled);
    $class    = $class    ?? '';
    $attrs    = $attrs    ?? '';
    $variantClass = $variant ? " mad-dropdown-item-{$variant}" : '';
@endphp
@if($href)
<a href="{{ $href }}"
   class="mad-dropdown-item{{ $variantClass }} {{ $class }}"
   @if($disabled) aria-disabled="true" style="opacity:.45;pointer-events:none;" @endif
   {!! $attrs !!}>
    @if($icon)<i data-lucide="{{ $icon }}" style="width:14px;height:14px;flex-shrink:0;"></i>@endif
    {!! $slot !!}
</a>
@else
<button type="button"
        class="mad-dropdown-item{{ $variantClass }} {{ $class }}"
        @if($disabled) disabled @endif
        {!! $attrs !!}>
    @if($icon)<i data-lucide="{{ $icon }}" style="width:14px;height:14px;flex-shrink:0;"></i>@endif
    {!! $slot !!}
</button>
@endif
