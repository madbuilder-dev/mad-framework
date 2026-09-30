{{-- <mad-stat-grid> — grid de metric-cards (mad-db-metric-card / mad-metric-card)
     com gap embutido POR PADRÃO. Substitui o
     `<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px">`
     hand-escrito em toda dashboard.

     Props:
       :cols  nº fixo de colunas (1..N). Omitido = responsivo (auto-fit pela
              largura mínima :min). No mobile (<640px) sempre empilha em 1 col.
       :min   largura mínima do card no modo auto-fit. nº (px) ou CSS. Default 220.
       :gap   gap entre cards. nº (px) ou CSS. Default: token --mad-dash-card-gap (16px).
       class / style  extras.

     Ex fixo:        <mad-stat-grid :cols="4"> ...4 cards... </mad-stat-grid>
     Ex responsivo:  <mad-stat-grid :min="240"> ...N cards... </mad-stat-grid>
--}}
@php
    $cols  = $cols  ?? null;
    $min   = $min   ?? null;
    $gap   = $gap   ?? null;
    $class = $class ?? '';
    $style = $style ?? '';

    $vars = '';
    if ($cols !== null && (int) $cols > 0) {
        $vars .= '--mad-stat-grid-cols:' . (int) $cols . ';';
    }
    if ($min !== null && $min !== '') {
        $_min = \Mad\Support\CssUnits::length((string) $min);
        if ($_min !== '') $vars .= '--mad-stat-grid-min:' . $_min . ';';
    }
    if ($gap !== null && $gap !== '') {
        $_gap = \Mad\Support\CssUnits::length((string) $gap);
        if ($_gap !== '') $vars .= '--mad-dash-card-gap:' . $_gap . ';';
    }
@endphp
<div class="mad-stat-grid {{ $class }}" @if($vars || $style) style="{{ $vars }}{{ $style }}" @endif>
    {!! $slot !!}
</div>
