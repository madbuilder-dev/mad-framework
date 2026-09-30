{{-- MadBuilder <mad-doc-footer-band>
     Repete no rodapé de toda página. Mesmo contrato do doc-header-band: a
     banda anuncia a própria altura (data-mad-doc-band-mm), o <mad-doc-page>
     soma à margem de baixo e a banda desce para a faixa reservada (bottom
     negativo) — o corpo termina antes dela. --}}
@props([
    'heightMm' => 15,
    'align' => 'center',
    'background' => 'transparent',
    'padding' => 4,
])

@php
    $alignSafe = in_array($align, ['left', 'center', 'right'], true) ? $align : 'center';
    $h = max(5, min(80, (int) $heightMm));
    $pad = max(0, min(40, (int) $padding));
    $bg = preg_match('/^(transparent|#[0-9a-fA-F]{3,6})$/', (string) $background) ? $background : 'transparent';
    // Altura total da caixa: a do conteúdo + o respiro de cima e de baixo (pt → mm).
    $total = round($h + 2 * $pad * 25.4 / 72, 2);
@endphp

<div class="mad-doc-footer-band" data-mad-doc-band="footer" data-mad-doc-band-mm="{{ $total }}"
     style="position:fixed;bottom:-{{ $total }}mm;left:0;right:0;height:{{ $h }}mm;
            padding:{{ $pad }}pt;text-align:{{ $alignSafe }};
            background:{{ $bg }};overflow:hidden;">
{!! $slot !!}
</div>
