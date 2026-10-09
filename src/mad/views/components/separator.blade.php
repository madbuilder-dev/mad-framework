@php
    $label    = $label    ?? '';
    $vertical = !empty($vertical) && !in_array(strtolower(trim((string) $vertical)), ['false', '0'], true);
    $class    = $class    ?? '';

    // Aparência da linha (5.140.0). Cada prop vira uma custom property do
    // `.mad-sep*` no mad-ui.css; vazia ou fora da allow-list, a declaração não
    // sai e o separador fica no padrão de sempre (1px contínuo, cor da borda).
    $_sepStyles = ['solid' => 1, 'dashed' => 1, 'dotted' => 1, 'double' => 1];
    $_sepStyle  = is_string($lineStyle ?? null) ? strtolower(trim($lineStyle)) : '';
    $_sepStyle  = isset($_sepStyles[$_sepStyle]) ? $_sepStyle : '';
    $_sepColor  = \Mad\Support\CssUnits::color($color ?? '');
    $_sepSize   = is_scalar($thickness ?? null) ? \Mad\Support\CssUnits::length((string) $thickness) : '';
    // Linha dupla de 1px desenha uma linha só: sem espessura, a dupla nasce com 3px.
    if ($_sepStyle === 'double' && $_sepSize === '') {
        $_sepSize = '3px';
    }
    $_sepSpace  = is_scalar($spacing ?? null) ? \Mad\Support\CssUnits::length((string) $spacing) : '';

    $_sepCss = ($_sepColor !== '' ? '--mad-sep-color:' . $_sepColor . ';' : '')
             . ($_sepSize  !== '' ? '--mad-sep-size:'  . $_sepSize  . ';' : '')
             . ($_sepStyle !== '' ? '--mad-sep-style:' . $_sepStyle . ';' : '')
             . ($_sepSpace !== '' ? '--mad-sep-space:' . $_sepSpace . ';' : '');

    // Posição do texto: centro (padrão), esquerda ou direita.
    $_sepAlign = is_string($labelAlign ?? null) ? strtolower(trim($labelAlign)) : '';
    $_sepAlign = in_array($_sepAlign, ['left', 'right'], true) ? $_sepAlign : '';
@endphp
@if($label)
<div class="mad-sep-labeled{{ $_sepAlign !== '' ? ' mad-sep-labeled--' . $_sepAlign : '' }} {{ $class }}"@if($_sepCss !== '') style="{{ $_sepCss }}"@endif>
    <span class="mad-sep-line"></span>
    <span class="mad-sep-text">{{ $label }}</span>
    <span class="mad-sep-line"></span>
</div>
@elseif($vertical)
<span class="mad-sep mad-sep-v {{ $class }}"@if($_sepCss !== '') style="{{ $_sepCss }}"@endif></span>
@else
<hr class="mad-sep mad-sep-h {{ $class }}"@if($_sepCss !== '') style="{{ $_sepCss }}"@endif>
@endif
