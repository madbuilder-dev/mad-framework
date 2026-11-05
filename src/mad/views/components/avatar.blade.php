@php
    $name    = $name    ?? '';
    $src     = $src     ?? '';
    $size    = $size    ?? '';    // sm | lg | xl
    $variant = $variant ?? '';    // primary | success
    $class   = $class   ?? '';

    // Iniciais do nome (sem o complemento entre parênteses): a mesma regra do
    // avatar do topo e das mensagens (fw#571).
    $initials = ($name && !$src) ? \Mad\Util\MadInitials::of((string) $name) : '';
    // A tag sem conteúdo (`<mad-avatar name="…" />`) chega com um slot VAZIO,
    // que não pode esconder as iniciais.
    $slotHtml = trim((string) ($slot ?? ''));

    $sizeClass    = $size    ? " mad-avatar-{$size}"    : '';
    $variantClass = $variant ? " mad-avatar-{$variant}" : '';
@endphp
<div class="mad-avatar{{ $sizeClass }}{{ $variantClass }} {{ $class }}" title="{{ $name }}">
    @if($src)
        <img src="{{ $src }}" alt="{{ $name }}">
    @elseif($slotHtml !== '')
        {!! $slot !!}
    @else
        {{ $initials }}
    @endif
</div>
