{{-- <mad-doc-horizontal-line thickness="1" color="#d1d5db" margin-y="8" /> --}}
@props([
    'thickness' => 1,
    'color' => '#d1d5db',
    'marginY' => 8,
])

@php
    $t = max(1, min(10, (int) $thickness));
    $my = max(0, min(100, (int) $marginY));
    $c = preg_match('/^#[0-9a-fA-F]{3,6}$/', (string) $color) ? $color : '#d1d5db';
@endphp

<hr style="border:none;border-top:{{ $t }}px solid {{ $c }};margin:{{ $my }}px 0;">
