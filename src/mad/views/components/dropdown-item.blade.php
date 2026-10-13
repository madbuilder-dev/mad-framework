@php
    $icon     = $icon     ?? '';
    $variant  = $variant  ?? '';   // danger
    $href     = $href     ?? '';
    $disabled = !empty($disabled);
    $class    = $class    ?? '';
    $attrs    = $attrs    ?? '';
    $variantClass = $variant ? " mad-dropdown-item-{$variant}" : '';
    // `label` é o texto quando o slot vem vazio (`<mad-dropdown-item label="CSV" />`,
    // como o catálogo documenta) — antes o item saía sem texto (fw#319).
    $label    = is_string($label ?? null) ? $label : '';
    $_useLabel = $label !== '' && trim((string) ($slot ?? '')) === '';
@endphp
@if($href)
<a href="{{ $href }}"
   class="mad-dropdown-item{{ $variantClass }} {{ $class }}"
   @if($disabled) aria-disabled="true" style="opacity:.45;pointer-events:none;" @endif
   {!! $attrs !!}>
    @if($icon)<i data-lucide="{{ $icon }}" style="width:14px;height:14px;flex-shrink:0;"></i>@endif
    @if($_useLabel){{ $label }}@else{!! $slot !!}@endif
</a>
@else
<button type="button"
        class="mad-dropdown-item{{ $variantClass }} {{ $class }}"
        @if($disabled) disabled @endif
        {!! $attrs !!}>
    @if($icon)<i data-lucide="{{ $icon }}" style="width:14px;height:14px;flex-shrink:0;"></i>@endif
    @if($_useLabel){{ $label }}@else{!! $slot !!}@endif
</button>
@endif
