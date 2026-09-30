{{-- <mad-segmented> — Segmented control (estilo iOS / Stripe / Linear). --}}
{{-- Filhos: <mad-segmented-item active mad:click="acao">Label</mad-segmented-item> --}}
@php
    $size    = $size    ?? 'md';      // sm | md | lg
    $variant = $variant ?? 'default'; // default | outline | dark
    $class   = $class   ?? '';
    $style   = $style   ?? '';

    $sizeCls = match($size) {
        'sm' => 'mad-seg-sm',
        'lg' => 'mad-seg-lg',
        default => '',
    };
    $variantCls = match($variant) {
        'outline' => 'mad-seg-outline',
        'dark'    => 'mad-seg-dark',
        default   => '',
    };
@endphp

<div class="mad-seg {{ $sizeCls }} {{ $variantCls }} {{ $class }}" role="tablist" @if($style) style="{{ $style }}" @endif>
    {!! $slot !!}
</div>
