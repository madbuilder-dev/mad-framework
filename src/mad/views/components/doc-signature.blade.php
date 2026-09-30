{{-- Signature block: baseline with optional name/role below.
     Usage: mad-doc-signature(width-mm=80, name="João Silva", role="Diretor"). --}}
@props([
    'widthMm' => 80,
    'align' => 'center',
    'name' => '',
    'role' => '',
])

@php
    $alignSafe = in_array($align, ['left', 'center', 'right'], true) ? $align : 'center';
    $width = max(30, min(250, (int) $widthMm));
@endphp

<div style="text-align:{{ $alignSafe }};margin:16pt 0 6pt 0;">
    <div style="display:inline-block;width:{{ $width }}mm;max-width:100%;
                border-bottom:1px solid #111;height:14pt;margin-bottom:2pt;"></div>
    @if($name !== '')
        <div style="font-size:9pt;">{{ $name }}</div>
    @endif
    @if($role !== '')
        <div style="font-size:8pt;color:#6b7280;">{{ $role }}</div>
    @endif
</div>
