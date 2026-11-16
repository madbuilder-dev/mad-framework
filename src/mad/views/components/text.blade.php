{{--
    <mad-text> — texto solto da tela (fórum #318): um rótulo, aviso curto ou
    explicação em qualquer lugar (formulário, seção, cartão, coluna). Não é
    campo: não tem nome, não grava nem envia nada.

    <mad-text text="Preencha os dados do contratante." />
    <mad-text text="Total do contrato" size="lg" weight="semibold" tone="primary" align="right" />
    <mad-text>Texto também pode vir no corpo da tag.</mad-text>

    Props:
      text       string  O texto. Quebra de linha no texto vira quebra na tela.
      size       xs | sm | md (padrão) | lg | xl | 2xl, ou medida (16, 16px, 1.2rem)
      weight     normal (padrão) | medium | semibold | bold
      tone       default | muted | primary | success | warning | danger — cores
                 do tema, que acompanham o claro/escuro
      color      cor fixa (#1d4ed8); vence o tone e não muda com o tema
      align      left (padrão) | center | right | justify
      italic     bool
      uppercase  bool
      valign     top (padrão) | middle | bottom | field — alinhamento vertical na
                 linha da grade (fórum #327). `field` põe o texto na altura da
                 caixa do campo vizinho (ex.: "%" ao lado de "ISS (%)"): reserva
                 o espaço do rótulo e centraliza o texto na altura de um campo.
      class      classe CSS extra

    Diferente de Seção (título + divisor que agrupa campos), Callout/Alerta
    (caixa com ícone e fundo) e Dica (texto que aparece ao passar o mouse).
--}}
@props(['text' => '', 'size' => '', 'weight' => '', 'tone' => '', 'color' => '', 'align' => '', 'italic' => false, 'uppercase' => false, 'valign' => '', 'class' => ''])
@php
    $_txtSizes  = ['xs' => 'xs', 'sm' => 'sm', 'md' => 'md', 'lg' => 'lg', 'xl' => 'xl', '2xl' => '2xl'];
    $_txtWeight = ['normal' => 400, 'medium' => 500, 'semibold' => 600, 'bold' => 700];
    $_txtTones  = ['muted' => 1, 'primary' => 1, 'success' => 1, 'warning' => 1, 'danger' => 1];
    $_txtAligns = ['left' => 1, 'center' => 1, 'right' => 1, 'justify' => 1];
    // Lista fechada: `top` é o padrão (nada muda); fora da lista também nada muda.
    $_txtValigns = ['middle' => 1, 'bottom' => 1, 'field' => 1];

    $_txtSize  = is_scalar($size) ? strtolower(trim((string) $size)) : '';
    $_txtTone  = is_scalar($tone) ? strtolower(trim((string) $tone)) : '';
    $_txtAlign = is_scalar($align) ? strtolower(trim((string) $align)) : '';
    $_txtW     = is_scalar($weight) ? strtolower(trim((string) $weight)) : '';
    $_txtV     = is_scalar($valign) ? strtolower(trim((string) $valign)) : '';
    $_txtV     = isset($_txtValigns[$_txtV]) ? $_txtV : '';

    $_txtClass = 'mad-text';
    $_txtCss   = '';
    if (isset($_txtSizes[$_txtSize])) {
        $_txtClass .= ' mad-text--' . $_txtSizes[$_txtSize];
    } elseif ($_txtSize !== '') {
        // Medida livre: número = px; fora do formato, o tamanho padrão fica.
        $_txtLen = \Mad\Support\CssUnits::length($_txtSize);
        if ($_txtLen !== '') {
            $_txtCss .= 'font-size:' . $_txtLen . ';';
        }
    }
    if (isset($_txtWeight[$_txtW])) {
        $_txtCss .= 'font-weight:' . $_txtWeight[$_txtW] . ';';
    }
    if ($_txtV === 'middle' || $_txtV === 'bottom') {
        $_txtClass .= ' mad-text--valign-' . $_txtV;
    }
    if (isset($_txtTones[$_txtTone])) {
        $_txtClass .= ' mad-text--' . $_txtTone;
    }
    $_txtColor = \Mad\Support\CssUnits::color($color);
    if ($_txtColor !== '') {
        $_txtCss .= 'color:' . $_txtColor . ';';
    }
    if (isset($_txtAligns[$_txtAlign])) {
        $_txtCss .= 'text-align:' . $_txtAlign . ';';
    }
    if (filter_var($italic, FILTER_VALIDATE_BOOLEAN)) {
        $_txtCss .= 'font-style:italic;';
    }
    if (filter_var($uppercase, FILTER_VALIDATE_BOOLEAN)) {
        $_txtCss .= 'text-transform:uppercase;';
    }
    $_txtExtra = is_scalar($class) ? trim((string) $class) : '';
    $_txtText  = is_scalar($text) || $text instanceof \Stringable ? (string) $text : '';
    $_txtSlot  = isset($slot) ? trim((string) $slot) : '';
@endphp
@if($_txtV === 'field')
{{-- Na altura da caixa do campo: um rótulo invisível (a mesma classe do rótulo dos
     campos, então a mesma altura) e o texto centralizado na altura de um campo. --}}
<div class="mad-text-at-field"><span class="mad-label mad-text-at-field__label" aria-hidden="true">&#8203;</span><div class="{{ $_txtClass }}{{ $_txtExtra !== '' ? ' ' . $_txtExtra : '' }}"@if($_txtCss !== '') style="{{ $_txtCss }}"@endif>{{ $_txtText }}{!! $_txtSlot !!}</div></div>
@else
<div class="{{ $_txtClass }}{{ $_txtExtra !== '' ? ' ' . $_txtExtra : '' }}"@if($_txtCss !== '') style="{{ $_txtCss }}"@endif>{{ $_txtText }}{!! $_txtSlot !!}</div>
@endif
