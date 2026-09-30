{{-- Dynamic image bound to a model attribute.
     Usage: mad-doc-dynamic-image(:src="$record->foto_url", width-mm=40, fit=contain, alt="Foto").
     `src` accepts URL, filesystem path, or base64 data URI — all three
     work in DOMPDF. Empty src renders nothing so the surrounding layout
     closes over the hole. --}}
@props([
    'src' => null,
    'widthMm' => 40,
    'align' => 'left',
    'fit' => 'contain',
    'alt' => '',
])

@php
    $alignSafe = in_array($align, ['left', 'center', 'right'], true) ? $align : 'left';
    $fitSafe = in_array($fit, ['contain', 'cover'], true) ? $fit : 'contain';
    $width = max(1, min(500, (int) $widthMm));
    $srcStr = is_string($src) ? trim($src) : '';
@endphp

@if($srcStr !== '')
<div style="text-align:{{ $alignSafe }};margin:2pt 0;">
    <img src="{{ $srcStr }}" alt="{{ $alt }}"
         style="width:{{ $width }}mm;max-width:100%;object-fit:{{ $fitSafe }};" />
</div>
@endif
