{{-- <mad-doc-image src="/logo.png" width-mm="40" align="left" fit="contain" /> --}}
@props([
    'src' => '',
    'widthMm' => 40,
    'align' => 'left',
    'fit' => 'contain',
    'alt' => '',
])

@php
    $srcStr = (string) $src;
    $widthSafe = max(5, min(250, (int) $widthMm));
    $alignSafe = in_array($align, ['left', 'center', 'right'], true) ? $align : 'left';
    $fitSafe = $fit === 'cover' ? 'cover' : 'contain';

    // Reject remote URLs — DOMPDF's isRemoteEnabled is off by default. Data
    // URIs and local paths pass through.
    $isSafe = true;
    if ($srcStr !== '' && ! str_starts_with($srcStr, 'data:image/')) {
        $scheme = strtolower(parse_url($srcStr, PHP_URL_SCHEME) ?? '');
        if (in_array($scheme, ['http', 'https'], true)) {
            $isSafe = false;
        }
    }
@endphp

@if($srcStr !== '' && $isSafe)
<div style="text-align:{{ $alignSafe }};margin:2pt 0;">
    <img src="{{ $srcStr }}" alt="{{ $alt }}"
         style="width:{{ $widthSafe }}mm;max-width:100%;object-fit:{{ $fitSafe }};">
</div>
@else
<div style="font-size:9pt;color:#9ca3af;font-style:italic;">[Imagem sem origem válida]</div>
@endif
