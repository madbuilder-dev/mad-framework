@php
    $template = $template ?? null;
    $cols  = (int)($cols ?? 2);
    $_gapGiven = isset($gap) && $gap !== '';
    $gap   = (int)($gap ?? 4);
    $gaps  = [0=>'0px',1=>'4px',2=>'8px',3=>'12px',4=>'16px',5=>'20px',6=>'24px',8=>'32px'];
    $gapPx = $gaps[$gap] ?? '16px';
    // Espaço vertical entre linhas (fórum #243). `row-gap="24"` (número = px)
    // vale para esta Linha: entre as linhas de campos dela (o campo que quebra
    // para baixo) e acima dela, quando vem logo depois de outra Linha — a regra
    // `.mad-form-grid + .mad-form-grid` do mad-ui.css lê a mesma custom property.
    // Sem `row-gap`, a distância entre as linhas de campos segue o tema
    // (`--mad-form-row-gap`) com o `gap` de sempre como reserva. `gap` escrito
    // na Linha continua mandando nas duas direções.
    $_rowGap    = \Mad\Support\CssUnits::length((string) ($rowGap ?? ''));
    $_rowGapCss = ($_rowGap !== '' ? '--mad-form-row-gap:' . $_rowGap . ';' : '')
        . ($_rowGap !== '' || !$_gapGiven ? 'row-gap:var(--mad-form-row-gap, ' . $gapPx . ');' : '');

    // template="140 1fr" → "140px 1fr". Sem isso o track sem unidade invalidava
    // a declaração INTEIRA e o form virava uma coluna só — o formulário todo
    // empilhava por causa de um número. Coluna em `%` desconta o gap: `70% 30%`
    // passava 16px da borda direita (fórum #205).
    if (is_string($template) && trim($template) !== '') {
        $gridCols = \Mad\Support\CssUnits::templateNetOfGap($template, (float) $gapPx);
    } else {
        // `minmax(0,1fr)`, não `1fr` (fórum #248): `1fr` é `minmax(auto,1fr)` e
        // a coluna crescia até a largura fixa de um campo (`width="800px"`
        // num modal de 800px). A coluna passava da janela, levava junto os
        // outros campos da Linha e o modal ganhava rolagem para o lado. Com
        // mínimo 0 a coluna fica do tamanho da Linha, e o `max-width: 100%`
        // do `.mad-field` (mad-ui.css) encolhe o campo para caber nela.
        $colsMap = [1=>'repeat(1,minmax(0,1fr))',2=>'repeat(2,minmax(0,1fr))',3=>'repeat(3,minmax(0,1fr))',4=>'repeat(4,minmax(0,1fr))',5=>'repeat(5,minmax(0,1fr))',6=>'repeat(6,minmax(0,1fr))'];
        $gridCols = $colsMap[$cols] ?? 'repeat(2,minmax(0,1fr))';
    }
    // valign="top|center|bottom" → align-items da linha. Só valor da lista chega
    // ao HTML; sem a prop a declaração não sai e o grid segue no `stretch` de
    // sempre (campo baixo, como o switch, fica no topo da célula).
    $valignMap = ['top' => 'start', 'start' => 'start', 'center' => 'center', 'middle' => 'center', 'bottom' => 'end', 'end' => 'end'];
    $valign    = is_string($valign ?? null) ? strtolower(trim($valign)) : '';
    $alignCss  = isset($valignMap[$valign]) ? 'align-items:' . $valignMap[$valign] . ';' : '';
    $class = $class ?? '';
    $style = $style ?? '';
    // Grade só de leitura (<mad-display-field>, campos de <mad-form read>), sem
    // controle editável: ganha .mad-form-grid-read — no celular, tema ERP, fica
    // em 2 colunas quando cabe, em vez de uma (fw#498).
    $_slotHtml = (string) ($slot ?? '');
    if (str_contains($_slotHtml, 'mad-display-field')
        && !preg_match('/<(?:select|textarea)\b|<input\b(?![^>]*\btype=["\']hidden["\'])/i', $_slotHtml)) {
        $class = trim($class . ' mad-form-grid-read');
    }
@endphp
<div style="display:grid;grid-template-columns:{{ $gridCols }};gap:{{ $gapPx }};{{ $_rowGapCss }}{{ $alignCss }}{{ $style }}" class="mad-form-grid {{ $class }}">
    {!! $slot !!}
</div>
