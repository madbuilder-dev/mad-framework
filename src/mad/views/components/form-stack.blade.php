@php
    $gaps  = [0=>'0px',1=>'4px',2=>'8px',3=>'12px',4=>'16px',5=>'20px',6=>'24px',8=>'32px'];
    $gap   = $gaps[(int)($gap ?? 4)] ?? '16px';
    $class = $class ?? '';
@endphp
<div style="display:flex;flex-direction:column;gap:{{ $gap }};" class="{{ $class }}">
    {!! $slot !!}
</div>
