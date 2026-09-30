{{-- <mad-kpi-strip> — grid de <mad-kpi-card> (faixa persistente estilo BI).

     Props:
       :cols   int   nº fixo de colunas; 0 = auto-fit por :min
       :min    int   largura mínima do card no auto-fit (px, default 200)
       :gap    int   gap em px (default 14)
       sticky  bool  gruda no topo ao rolar (top via --mad-kpi-sticky-top)
       class / style --}}
@php
    $cols   = (int) ($cols ?? 0);
    $min    = (int) ($min ?? 200);
    $gap    = (int) ($gap ?? 14);
    $sticky = filter_var($sticky ?? false, FILTER_VALIDATE_BOOL);
    $class  = $class ?? '';
    $style  = $style ?? '';

    $tpl = $cols > 0
        ? "repeat({$cols}, minmax(0, 1fr))"
        : "repeat(auto-fit, minmax({$min}px, 1fr))";
@endphp
<div class="mad-kpi-strip{{ $sticky ? ' mad-kpi-strip-sticky' : '' }} {{ $class }}"
     style="grid-template-columns:{{ $tpl }};gap:{{ $gap }}px;{{ $style }}">
    {!! $slot !!}
</div>
