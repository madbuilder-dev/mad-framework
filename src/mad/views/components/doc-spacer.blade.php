{{-- <mad-doc-spacer height-mm="10" /> --}}
@props(['heightMm' => 10])

@php
    $h = max(1, min(200, (int) $heightMm));
@endphp

<div style="height:{{ $h }}mm;"></div>
