<?php
namespace Mad\Grid;

/**
 * As linhas de um relatório por janelas (#439), em HTML enxuto.
 *
 * O laço do data-grid.blade.php gasta ~3,5 KB por linha: cada célula leva um
 * `:class` do Alpine para o seletor de colunas, um `style` de alinhamento e a
 * indentação do template. Num relatório de 30 mil linhas isso dava 105 MB de
 * HTML e 300 mil ligações do Alpine no navegador.
 *
 * Aqui a célula sai com `data-k` (a chave da coluna) e o mesmo conteúdo de
 * hoje (`GridColumn::renderValue`). Esconder uma coluna no seletor passa a ser
 * o grid trocar a classe `mad-dg-col-hidden` nas células daquela chave
 * (mad-ui.js) — o CSS de coluna oculta continua o mesmo. Cabeçalho e subtotal
 * da quebra são poucos e saem pelos partials de sempre.
 *
 * Só cobre a linha que é SÓ leitura: com edição na célula, ações, seleção ou
 * linha expansível a grade usa o partial completo (`supports()`).
 */
final class GridRowRenderer
{
    /** @var GridColumn[] */
    private array $columns;
    private string $prefix;
    private string $idField;
    private array $highlightRules;
    private int $span;
    /** @var array<int, array{fk:string, field:string, class:string, align:string}> pedaço fixo de cada célula */
    private array $cells = [];

    /**
     * @param GridColumn[] $visibleColumns colunas da tela (as do cabeçalho)
     * @param array        $highlightRules regras de <mad-row-highlights>
     */
    public function __construct(array $visibleColumns, string $rowPrefix, string $rowIdField = 'id', array $highlightRules = [])
    {
        $this->columns        = array_values(array_filter($visibleColumns, fn ($c) => $c instanceof GridColumn));
        $this->prefix         = $rowPrefix;
        $this->idField        = $rowIdField !== '' ? $rowIdField : 'id';
        $this->highlightRules = $highlightRules;
        $this->span           = max(1, count($this->columns));

        foreach ($this->columns as $col) {
            $this->cells[] = [
                'fk'    => $col->fieldKey,
                'field' => $col->field,
                'class' => 'mad-dg-cell' . $col->nowrapClass(),
                'align' => $col->align,
            ];
        }
    }

    /**
     * A grade pode usar o HTML enxuto? Edição na célula, ações por linha,
     * seleção e linha expansível têm marcação e comportamento próprios no
     * partial completo.
     *
     * @param GridColumn[] $visibleColumns
     */
    public static function supports(array $visibleColumns, bool $hasActions, bool $selectable, bool $hasExpand): bool
    {
        if ($hasActions || $selectable || $hasExpand) {
            return false;
        }
        foreach ($visibleColumns as $col) {
            if ($col instanceof GridColumn && $col->editable) {
                return false;
            }
        }

        return true;
    }

    /** Colunas que a linha de detalhe cobre (todas; as ocultas não ocupam largura). */
    public function span(): int
    {
        return $this->span;
    }

    /**
     * Uma linha de dado e, quando há `row-detail`, a linha descritiva logo
     * abaixo — as mesmas classes, ids e conteúdo do laço de hoje.
     *
     * @param int        $index posição da linha no resultado inteiro (zebra)
     * @param int        $depth nível da linha na quebra (0 sem quebra)
     * @param array|null $lastRow a linha anterior (transforms de coluna)
     */
    public function row(array $row, int $index, int $depth = 0, ?array $lastRow = null): string
    {
        $rowId  = $row[$this->idField] ?? $row['id'] ?? $index;
        $parity = $index % 2 === 0 ? 'mad-dg-row-even' : 'mad-dg-row-odd';
        $hl     = $this->highlightRules !== [] ? MadDataGrid::rowHighlightFor($this->highlightRules, $row) : null;
        $hlStyle = MadDataGrid::rowHighlightStyle($hl);
        $domId  = e($this->prefix . $rowId);

        $html = '<tr class="mad-dg-row ' . $parity
            . ($depth > 0 ? ' mad-dg-row-depth-' . $depth : '')
            . e(MadDataGrid::rowHighlightClass($hl)) . '" data-row-id="' . $domId . '"'
            . ($hlStyle !== '' ? ' style="' . e($hlStyle) . '"' : '')
            . '>';

        foreach ($this->columns as $i => $col) {
            $c        = $this->cells[$i];
            $rendered = $col->renderValue($row[$c['field']] ?? '', $row, $lastRow);
            $cellHl   = $hl !== null ? MadDataGrid::cellHighlightStyle($hl, $col) : '';
            $style    = $c['align'] !== 'left' || $cellHl !== '' ? 'text-align:' . $c['align'] . $cellHl . ';' : '';
            $html .= '<td class="' . $c['class'] . ($hl !== null ? e(MadDataGrid::cellHighlightClass($hl, $col)) : '') . '" data-k="' . e($c['fk']) . '"'
                . ($style !== '' ? ' style="' . e($style) . '"' : '')
                . '>' . $rendered . '</td>';
        }
        $html .= '</tr>';

        if (!empty($row['__detail'])) {
            $html .= '<tr class="mad-dg-row-detail ' . $parity . '" data-detail-for="' . $domId . '">'
                . '<td colspan="' . $this->span . '">' . e($row['__detail']) . '</td></tr>';
        }

        return $html;
    }

    /**
     * Os itens de uma janela do GridGroupStream em HTML: cabeçalho e subtotal
     * da quebra pelos partials, linhas pelo `row()`.
     *
     * @param array      $items     o que o GridGroupStream::push()/finish() devolveu
     * @param int        $index     posição da 1ª linha da janela no resultado inteiro
     * @param array|null $lastRow   linha anterior (entra e sai atualizada)
     * @param array      $groupVars variáveis dos partials da quebra (visibleColumns,
     *                              groupBandCells, hasGroupFooters, selectable,
     *                              hasExpand, hasActions, actionSide)
     */
    public function items(array $items, int $index, ?array &$lastRow, array $groupVars): string
    {
        $html = '';
        foreach ($items as $item) {
            if ($item['type'] === 'row') {
                $html   .= $this->row($item['data'], $index++, (int) ($item['level'] ?? 0), $lastRow);
                $lastRow = $item['data'];
                continue;
            }
            $view  = $item['type'] === 'group' ? 'components.partials.data-grid-group-row' : 'components.partials.data-grid-group-total-row';
            $html .= \Mad\View\MadBlade::render($view, ['item' => $item] + $groupVars);
        }

        return $html;
    }
}
