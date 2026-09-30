@php
    $name    = $name    ?? '';
    $src     = $src     ?? '';
    $size    = $size    ?? '';    // sm | lg | xl
    $variant = $variant ?? '';    // primary | success
    $class   = $class   ?? '';

    // Generate initials from name
    $initials = '';
    if ($name && !$src) {
        $parts = explode(' ', trim($name));
        $initials = mb_strtoupper(mb_substr($parts[0], 0, 1));
        if (count($parts) > 1) {
            $initials .= mb_strtoupper(mb_substr(end($parts), 0, 1));
        }
    }

    $sizeClass    = $size    ? " mad-avatar-{$size}"    : '';
    $variantClass = $variant ? " mad-avatar-{$variant}" : '';
@endphp
<div class="mad-avatar{{ $sizeClass }}{{ $variantClass }} {{ $class }}" title="{{ $name }}">
    @if($src)
        <img src="{{ $src }}" alt="{{ $name }}">
    @elseif($slot ?? '')
        {!! $slot !!}
    @else
        {{ $initials }}
    @endif
</div>
