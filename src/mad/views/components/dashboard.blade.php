{{-- <mad-dashboard> — wrapper de dashboard: empilha linhas/seções (metric-grid,
     cards, charts, grids) com gap vertical POR PADRÃO. Dispensa margin-bottom
     manual em cada bloco.

     Props:
       :gap   nº (px) ou string CSS (ex "2rem"). Default: token --mad-dash-gap (24px).
       class  classes extras.
       style  style extra (concatena depois das vars).

     Ex:
       <mad-dashboard>
         <mad-stat-grid :cols="4"> ...metric cards... </mad-stat-grid>
         <mad-card :header="__('Assinaturas por tier')"> ...tabela... </mad-card>
       </mad-dashboard>
--}}
@php
    $gap   = $gap   ?? null;
    $class = $class ?? '';
    $style = $style ?? '';

    $vars = '';
    if ($gap !== null && $gap !== '') {
        $_gap = \Mad\Support\CssUnits::length((string) $gap);
        if ($_gap !== '') $vars .= '--mad-dash-gap:' . $_gap . ';';
    }
@endphp
<div class="mad-dashboard {{ $class }}" @if($vars || $style) style="{{ $vars }}{{ $style }}" @endif>
    {!! $slot !!}
</div>
