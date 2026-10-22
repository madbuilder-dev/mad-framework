{{-- <mad-kpi-strip> — grid de <mad-kpi-card> (faixa persistente estilo BI).

     Props:
       :cols   int   nº fixo de colunas; 0 = auto-fit por :min
       :min    int   largura mínima do card no auto-fit (px, default 200)
       :gap    int   gap em px (default 14)
       sticky  bool  gruda no topo ao rolar (top via --mad-kpi-sticky-top)
       class / style

     Colunas e largura saem em variáveis CSS (mad-ui.css): sem :cols, o tema
     ERP põe de 4 a 7 cards por linha pela quantidade de filhos, sem sobra. --}}
@php
    $cols   = (int) ($cols ?? 0);
    $min    = isset($min) && $min !== '' && $min !== null ? (int) $min : null;
    $gap    = (int) ($gap ?? 14);
    $sticky = filter_var($sticky ?? false, FILTER_VALIDATE_BOOL);
    $class  = $class ?? '';
    $style  = $style ?? '';

    $vars = '--mad-kpi-strip-gap:' . $gap . 'px;';
    if ($cols > 0) {
        $vars .= '--mad-kpi-strip-cols:' . $cols . ';';
    }
    if ($min !== null && $min > 0) {
        $vars .= '--mad-kpi-strip-min:' . $min . 'px;';
    }
@endphp
<div class="mad-kpi-strip{{ $sticky ? ' mad-kpi-strip-sticky' : '' }} {{ $class }}"@if($cols > 0) data-cols="{{ $cols }}"@endif
     style="{{ $vars }}{{ $style }}">
    {!! $slot !!}
</div>
