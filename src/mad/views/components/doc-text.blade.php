{{-- <mad-doc-text align="justify" size="11" color="#333">…</mad-doc-text> --}}
@props(['align' => 'left', 'size' => 11, 'color' => '#333333'])

@php
    $alignSafe = in_array($align, ['left', 'center', 'right', 'justify'], true) ? $align : 'left';
    $sizePt = max(6, min(36, (int) $size));
    $colorSafe = preg_match('/^#[0-9a-fA-F]{3,6}$/', (string) $color) ? $color : '#333333';
@endphp

<div style="font-size:{{ $sizePt }}pt;color:{{ $colorSafe }};text-align:{{ $alignSafe }};line-height:1.45;margin:0 0 4pt 0;">{!! $slot !!}</div>
