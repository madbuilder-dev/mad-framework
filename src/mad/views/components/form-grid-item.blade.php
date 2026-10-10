@php
    $span  = max(1, (int)($span ?? 1));
    $start = (int)($start ?? 0);
    $class = $class ?? '';
    // start="2" → a Coluna começa na 2ª coluna da Linha ("Coluna inicial" do
    // painel). Sem start, só o span: a grade decide onde a Coluna cai.
    $style = $start > 0
        ? "grid-column:{$start} / span {$span};"
        : ($span > 1 ? "grid-column:span {$span};" : '');
@endphp
<div @if($style) style="{{ $style }}" @endif class="{{ $class }}">
    {!! $slot !!}
</div>
