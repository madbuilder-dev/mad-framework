{{-- <mad-doc-page-number format="Página {page} de {total}" align="center" size="9" />
     Tokens {page} / {total} are replaced by spans driven by DOMPDF CSS
     counters (defined in <mad-doc-page>).  --}}
@props([
    'format' => 'Página {page} de {total}',
    'align' => 'center',
    'size' => 9,
])

@php
    $alignSafe = in_array($align, ['left', 'center', 'right'], true) ? $align : 'center';
    $sizePt = max(6, min(18, (int) $size));
    $safe = htmlspecialchars((string) $format, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $safe = str_replace('{page}',  '<span class="mad-page"></span>',  $safe);
    $safe = str_replace('{total}', '<span class="mad-pages"></span>', $safe);
@endphp

<div style="text-align:{{ $alignSafe }};font-size:{{ $sizePt }}pt;color:#4b5563;">{!! $safe !!}</div>
