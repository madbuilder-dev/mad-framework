{{-- <mad-doc-heading level="1" align="center" color="#111">Título</mad-doc-heading> --}}
@props(['level' => 1, 'align' => 'left', 'color' => '#111111'])

@php
    $lv = (int) $level;
    $lv = ($lv >= 1 && $lv <= 4) ? $lv : 1;
    $alignSafe = in_array($align, ['left', 'center', 'right', 'justify'], true) ? $align : 'left';
    $colorSafe = preg_match('/^#[0-9a-fA-F]{3,6}$/', (string) $color) ? $color : '#111111';
    $sizeMap = [1 => '24pt', 2 => '18pt', 3 => '14pt', 4 => '12pt'];
    $size = $sizeMap[$lv];
@endphp

<div style="font-weight:700;font-size:{{ $size }};color:{{ $colorSafe }};text-align:{{ $alignSafe }};margin:0 0 6pt 0;line-height:1.15;">{!! $slot !!}</div>
