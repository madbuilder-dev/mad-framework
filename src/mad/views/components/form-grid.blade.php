@php
    $template = $template ?? null;
    $cols  = (int)($cols ?? 2);
    $gap   = (int)($gap ?? 4);
    $gaps  = [0=>'0px',1=>'4px',2=>'8px',3=>'12px',4=>'16px',5=>'20px',6=>'24px',8=>'32px'];
    $gapPx = $gaps[$gap] ?? '16px';

    // template="140 1fr" → "140px 1fr". Sem isso o track sem unidade invalidava
    // a declaração INTEIRA e o form virava uma coluna só — o formulário todo
    // empilhava por causa de um número.
    if (is_string($template) && trim($template) !== '') {
        $gridCols = \Mad\Support\CssUnits::template($template);
    } else {
        $colsMap = [1=>'repeat(1,1fr)',2=>'repeat(2,1fr)',3=>'repeat(3,1fr)',4=>'repeat(4,1fr)',5=>'repeat(5,1fr)',6=>'repeat(6,1fr)'];
        $gridCols = $colsMap[$cols] ?? 'repeat(2,1fr)';
    }
    // valign="top|center|bottom" → align-items da linha. Só valor da lista chega
    // ao HTML; sem a prop a declaração não sai e o grid segue no `stretch` de
    // sempre (campo baixo, como o switch, fica no topo da célula).
    $valignMap = ['top' => 'start', 'start' => 'start', 'center' => 'center', 'middle' => 'center', 'bottom' => 'end', 'end' => 'end'];
    $valign    = is_string($valign ?? null) ? strtolower(trim($valign)) : '';
    $alignCss  = isset($valignMap[$valign]) ? 'align-items:' . $valignMap[$valign] . ';' : '';
    $class = $class ?? '';
    $style = $style ?? '';
@endphp
<div style="display:grid;grid-template-columns:{{ $gridCols }};gap:{{ $gapPx }};{{ $alignCss }}{{ $style }}" class="mad-form-grid {{ $class }}">
    {!! $slot !!}
</div>
