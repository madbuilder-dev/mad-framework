{{-- MadBuilder <mad-doc-header-band>
     Repete no topo de toda página (position:fixed do DOMPDF). O DOMPDF
     ancora o `fixed` na ÁREA DE CONTEÚDO (dentro das margens), não no papel:
     com `top:0` a banda caía em cima das primeiras linhas do corpo. Agora a
     banda anuncia a própria altura (data-mad-doc-band-mm, altura + respiro de
     cima e de baixo) e o <mad-doc-page> soma esse valor à margem de cima; a
     banda sobe a mesma medida (top negativo) e ocupa a faixa reservada, logo
     acima do corpo — como na prévia do editor. --}}
@props([
    'heightMm' => 20,
    'align' => 'left',
    'background' => 'transparent',
    'padding' => 4,
])

@php
    $alignSafe = in_array($align, ['left', 'center', 'right'], true) ? $align : 'left';
    $h = max(5, min(80, (int) $heightMm));
    $pad = max(0, min(40, (int) $padding));
    $bg = preg_match('/^(transparent|#[0-9a-fA-F]{3,6})$/', (string) $background) ? $background : 'transparent';
    // Altura total da caixa: a do conteúdo + o respiro de cima e de baixo (pt → mm).
    $total = round($h + 2 * $pad * 25.4 / 72, 2);
@endphp

<div class="mad-doc-header-band" data-mad-doc-band="header" data-mad-doc-band-mm="{{ $total }}"
     style="position:fixed;top:-{{ $total }}mm;left:0;right:0;height:{{ $h }}mm;
            padding:{{ $pad }}pt;text-align:{{ $alignSafe }};
            background:{{ $bg }};overflow:hidden;">
{!! $slot !!}
</div>
