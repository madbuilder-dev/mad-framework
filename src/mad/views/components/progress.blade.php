@php
    $value   = min(100, max(0, (int)($value ?? 0)));
    $size    = $size    ?? '';   // sm | lg
    $variant = $variant ?? '';   // success | warning | danger | info
    $label   = $label   ?? '';
    $class   = $class   ?? '';
    $sizeClass    = $size    ? " mad-progress-{$size}"    : '';
    $variantClass = $variant ? " mad-progress-{$variant}" : '';
@endphp
<div class="mad-progress{{ $sizeClass }}{{ $variantClass }} {{ $class }}"
     role="progressbar"
     aria-valuenow="{{ $value }}"
     aria-valuemin="0"
     aria-valuemax="100">
    <div class="mad-progress-bar" style="width:{{ $value }}%;"></div>
</div>
@if($label)
    <p style="font-size:11px;color:var(--mad-text-subtle);margin-top:4px;text-align:right;">{{ $label }}</p>
@endif
