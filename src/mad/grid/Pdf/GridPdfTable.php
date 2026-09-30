<?php
namespace Mad\Grid\Pdf;

use Dompdf\Helpers;
use Mad\Grid\GridColumn;
use Mad\Grid\MadGridExporter;

/**
 * Layout da tabela do PDF da listagem — o que o Dompdf faz com o <table> do
 * buildPdfHtml(), refeito sem montar DOM nem árvore de frames:
 *
 *  - modelo: as MESMAS linhas do HTML (cabeçalho, dado com zebra, row-detail,
 *    quebra inline/cells, sub-total, total geral), com o texto que o HTML teria
 *    depois de decodificado;
 *  - largura das colunas: layout automático de tabela do Dompdf
 *    (Cellmap::calculate_column_widths + FrameReflower\Table::_assign_widths),
 *    inclusive a ordem de processamento do colspan, que muda o resultado;
 *  - border-collapse: resolução de borda por aresta (mais larga vence, empate
 *    fica com a primeira) e meia largura para cada célula vizinha;
 *  - quebra de linha: FrameReflower\Text::line_break (palavra, hífen, nbsp,
 *    palavra maior que a coluna forçada na linha).
 *
 * Os números estão em pt e o estilo vem do GridPdfStyle — a mesma fonte que
 * gera o CSS do HTML.
 */
final class GridPdfTable
{
    /** Quebra de palavra do Dompdf: espaço (menos nbsp), quebra de linha, hífen, soft hyphen. */
    private const WORDBREAK = '/([^\S\xA0\x{202F}\x{2007}\n]+|\R|\-+|\xAD+)/u';
    /** Colapso de espaço em branco (white-space: normal). */
    private const WS = '/([^\S\xA0\x{202F}\x{2007}]+)/u';
    /** Span do badge exatamente como o GridColumn::renderValueForPdf gera. */
    private const BADGE_RE = '/^<span style="([^"<>]*)">([^<]*)<\/span>$/';

    public GridPdfMetrics $m;
    private array $palette;
    private array $styles = [];
    /** @var GridColumn[] */
    private array $columns;
    private int $n;
    private array $bodyFonts;

    /**
     * @param GridColumn[] $columns
     * @param ?array $palette paleta efetiva (MadGridExporter::pdfPalette);
     *        null = a do projeto
     */
    public function __construct(GridPdfMetrics $m, array $columns, ?array $palette = null)
    {
        $this->m = $m;
        $this->columns = array_values($columns);
        $this->n = count($this->columns);
        $this->palette = $palette ?? GridPdfStyle::palette();
    }

    public function columnCount(): int
    {
        return $this->n;
    }

    // ── Estilos ──────────────────────────────────────────────────────────

    public function style(string $kind, bool $zebra = false): array
    {
        $key = $kind . ($zebra ? '|z' : '');
        if (isset($this->styles[$key])) {
            return $this->styles[$key];
        }
        $s = GridPdfStyle::cell($kind, $zebra, $this->palette);
        if ($s['color'] === null) {
            throw new GridPdfUnsupported("cor de texto transparente em {$kind}");
        }
        $s['font'] = $this->m->font($s['families'], $s['weight']);
        $s['fonts'] = $this->m->familyFonts($s['families'], $s['weight']);

        return $this->styles[$key] = $s;
    }

    // ── Modelo (espelho do HTML de buildPdfHtml) ─────────────────────────

    /** Itens de texto de um nó de texto do HTML: espaço colapsado, maiúsculas, trechos por fonte. */
    private function textItems(string $text, array $st, ?array $over = null, float $opacity = 1.0): array
    {
        $s = $over ?? $st;
        if ($s['upper']) {
            $text = mb_convert_case($text, MB_CASE_UPPER, 'UTF-8');
        }
        $text = preg_replace(self::WS, ' ', $text) ?? '';
        $font = $s['font'] ?? $this->m->font($s['families'], $s['weight']);
        $fonts = $s['fonts'] ?? $this->m->familyFonts($s['families'], $s['weight']);
        $out = [];
        foreach ($this->m->runs($text, $fonts, $font) as [$run, $f]) {
            $out[] = ['t' => 'text', 's' => $run, 'font' => $f, 'size' => $s['size'], 'color' => $s['color'], 'op' => $opacity, 'cs' => $s['cs']];
        }

        return $out;
    }

    private static function decode(string $html): string
    {
        return html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** Conteúdo de uma célula de dado (saída do renderValueForPdf). */
    private function valueItems(string $html, array $st): array
    {
        if ($html === '') {
            return [];
        }
        if (!str_contains($html, '<')) {
            return $this->textItems(self::decode($html), $st);
        }
        // Único HTML que o motor desenha: o badge (span inline-block em pílula).
        if (preg_match(self::BADGE_RE, $html, $m)) {
            $b = GridPdfStyle::inline($m[1], $st);
            if ($b !== null && $b['display'] === 'inline-block' && $b['color'] !== null) {
                $label = self::decode($m[2]);
                if ($b['upper']) $label = mb_convert_case($label, MB_CASE_UPPER, 'UTF-8');
                $label = trim(preg_replace(self::WS, ' ', $label) ?? '', ' ');
                $font = $this->m->font($b['families'], $b['weight']);
                $runs = $this->m->runs($label, $this->m->familyFonts($b['families'], $b['weight']), $font);
                if (count($runs) !== 1) {
                    // Glifo que a fonte não tem quebraria o texto da pílula em frames.
                    throw new GridPdfUnsupported('badge com caractere fora da fonte');
                }
                return [['t' => 'badge', 's' => $label, 'font' => $font, 'size' => $b['size'], 'color' => $b['color'],
                    'bg' => $b['bg'], 'pad' => $b['pad'], 'radius' => $b['radius'], 'cs' => $b['cs']]];
            }
        }
        throw new GridPdfUnsupported('HTML na célula');
    }

    private function cell(int $col, int $span, string $align, array $st, array $items): GridPdfCell
    {
        $c = new GridPdfCell();
        $c->col = $col;
        $c->span = $span;
        $c->align = $align;
        $c->st = $st;
        $c->items = $items;

        return $c;
    }

    private static function align(GridColumn $col): string
    {
        return match ($col->align ?? '') { 'center' => 'center', 'right' => 'right', default => 'left' };
    }

    public function headerRow(): GridPdfRow
    {
        $r = new GridPdfRow('th');
        $st = $this->style('th');
        foreach ($this->columns as $i => $col) {
            $c = $this->cell($i, 1, 'left', $st, $this->textItems((string) $col->label, $st));
            $c->width = self::columnWidth($col);
            $r->cells[] = $c;
        }

        return $r;
    }

    /**
     * Largura declarada na coluna, em pt. null = auto. Unidade que o motor não
     * converte igual ao Dompdf (%, em…) nunca chega aqui: GridPdfRenderer::supports
     * manda o PDF inteiro para o Dompdf antes.
     */
    public static function columnWidth(GridColumn $col): ?float
    {
        $w = \Mad\Support\CssUnits::length((string) ($col->width ?? ''));
        if ($w === '' || strtolower($w) === 'auto') {
            return null;
        }

        return GridPdfStyle::length($w) ?? throw new GridPdfUnsupported("largura {$w}");
    }

    public function dataRow(array $row, ?array $lastRow, bool $zebra): GridPdfRow
    {
        $r = new GridPdfRow('data');
        $st = $this->style('data', $zebra);
        foreach ($this->columns as $i => $col) {
            $val = $col->renderValueForPdf($row[$col->field] ?? '', $row, $lastRow);
            $r->cells[] = $this->cell($i, 1, self::align($col), $st, $this->valueItems($val, $st));
        }

        return $r;
    }

    public function detailRow(string $text, bool $zebra): GridPdfRow
    {
        $r = new GridPdfRow('detail');
        $st = $this->style('detail', $zebra);
        $r->cells[] = $this->cell(0, $this->n, 'left', $st, $this->textItems($text, $st));

        return $r;
    }

    /** Cabeçalho de quebra: MadGridExporter::pdfGroupRow. */
    public function groupRow(array $item, string $groupBand): GridPdfRow
    {
        $level = (int) ($item['level'] ?? 0);
        $r = new GridPdfRow('group' . $level);
        // Só .group-0/.group-1 têm regra no CSS: do 3º nível em diante a
        // linha de quebra é um <td> comum (sem fundo, sem negrito).
        $st = match ($level) { 0 => $this->style('group0'), 1 => $this->style('group1'), default => $this->style('data') };
        $label = (string) ($item['label'] ?? '');
        $count = !empty($item['count'])
            // `label <span style="font-weight:normal;opacity:.7;">(n)</span>`
            ? $this->textItems('(' . $item['count'] . ')', $st, ['weight' => '400', 'font' => null, 'fonts' => null] + $st, 0.7)
            : [];

        if ($groupBand === 'cells' && !empty($item['totals'])) {
            $gt = MadGridExporter::groupTotalCells($this->columns, $item['totals'], '');
            $span = max(1, $gt['labelSpan']);
            $items = $count ? array_merge($this->textItems($label . ' ', $st), $count) : $this->textItems($label, $st);
            $r->cells[] = $this->cell(0, $span, 'left', $st, $items);
            for ($i = $span; $i < $this->n; $i++) {
                $r->cells[] = $this->cell($i, 1, self::align($this->columns[$i]), $st, $this->textItems((string) ($gt['cells'][$i] ?? ''), $st));
            }

            return $r;
        }
        $totals = '';
        foreach ($this->columns as $col) {
            if (isset($item['totals'][$col->field])) {
                // O rótulo da coluna entra CRU no HTML (entidades decodificam).
                $totals .= "\u{A0}\u{A0}\u{A0}" . self::decode((string) $col->label) . ': ' . $item['totals'][$col->field];
            }
        }
        // Sem contagem, rótulo e totais são UM nó de texto no HTML (quebra de
        // linha só em espaço); com contagem, o <span> separa os dois.
        $items = $count
            ? array_merge($this->textItems($label . ' ', $st), $count, $totals !== '' ? $this->textItems($totals, $st) : [])
            : $this->textItems($label . $totals, $st);
        $r->cells[] = $this->cell(0, $this->n, 'left', $st, $items);

        return $r;
    }

    /** Sub-total da quebra: MadGridExporter::pdfGroupTotalRow. */
    public function groupTotalRow(array $item, string $groupBand): GridPdfRow
    {
        $r = new GridPdfRow('gtotal');
        $st = $this->style('gtotal');
        $gt = MadGridExporter::groupTotalCells($this->columns, $item['totals'] ?? [], MadGridExporter::groupTotalText($item));
        if ($groupBand === 'cells' && $gt['labelSpan'] > 1) {
            $r->cells[] = $this->cell(0, $gt['labelSpan'], 'left', $st, $this->textItems($gt['label'], $st));
            for ($i = $gt['labelSpan']; $i < $this->n; $i++) {
                $r->cells[] = $this->cell($i, 1, self::align($this->columns[$i]), $st, $this->textItems((string) ($gt['cells'][$i] ?? ''), $st));
            }

            return $r;
        }
        foreach ($this->columns as $i => $col) {
            $r->cells[] = $this->cell($i, 1, self::align($col), $st, $this->textItems((string) ($gt['cells'][$i] ?? ''), $st));
        }

        return $r;
    }

    public function grandRow(array $grandTotals): GridPdfRow
    {
        $r = new GridPdfRow('grand');
        $st = $this->style('grand');
        $cells = MadGridExporter::grandTotalCells($this->columns, $grandTotals);
        foreach ($this->columns as $i => $col) {
            $r->cells[] = $this->cell($i, 1, self::align($col), $st, $this->textItems((string) ($cells[$i] ?? ''), $st));
        }

        return $r;
    }

    /**
     * Linhas do corpo na ordem do HTML. Gerador: o modelo de uma linha só
     * existe enquanto ela é medida/desenhada — a memória não cresce com o PDF.
     *
     * @return \Generator<GridPdfRow>
     */
    public function bodyRows(iterable $rows, array $groupData, array $grandTotals, array $report): \Generator
    {
        $groupBand = ($report['groupBand'] ?? '') === 'cells' ? 'cells' : 'inline';
        $rowDetail = !empty($report['rowDetail']);
        if (!empty($groupData)) {
            $z = 0;
            $last = null;
            foreach ($groupData as $item) {
                switch ($item['type']) {
                    case 'group':
                        yield $this->groupRow($item, $groupBand);
                        $z = 0;
                        break;
                    case 'row':
                        $zebra = $z % 2 === 1;
                        yield $this->dataRow($item['data'], $last, $zebra);
                        if ($rowDetail && !empty($item['data']['__detail'])) {
                            yield $this->detailRow((string) $item['data']['__detail'], $zebra);
                        }
                        $last = $item['data'];
                        $z++;
                        break;
                    case 'group-total':
                        yield $this->groupTotalRow($item, $groupBand);
                        break;
                }
            }
        } else {
            $last = null;
            // Zebra pela CHAVE da linha, como o buildPdfHtml (`$r % 2`).
            foreach ($rows as $i => $row) {
                $zebra = $i % 2 === 1;
                yield $this->dataRow($row, $last, $zebra);
                if ($rowDetail && !empty($row['__detail'])) {
                    yield $this->detailRow((string) $row['__detail'], $zebra);
                }
                $last = $row;
            }
        }
        if (!empty($grandTotals)) {
            yield $this->grandRow($grandTotals);
        }
    }

    // ── Bordas colapsadas ────────────────────────────────────────────────

    /** Cellmap::resolve_border: mais larga vence; empate mantém a que já estava. */
    public static function resolve(?array $old, array $new): array
    {
        if ($old === null) return $new;
        if ($old[1] === 'hidden') return $old;
        if ($new[1] === 'hidden' || $new[0] > $old[0]) return $new;

        return $old;
    }

    public static function cellAt(GridPdfRow $r, int $j): ?GridPdfCell
    {
        foreach ($r->cells as $c) {
            if ($j >= $c->col && $j < $c->col + $c->span) return $c;
        }

        return null;
    }

    /** Aresta horizontal entre $above (borda de baixo) e $below (borda de cima), coluna $j. */
    public static function hEdge(?GridPdfRow $above, ?GridPdfRow $below, int $j): array
    {
        $b = null;
        if ($above && ($c = self::cellAt($above, $j))) $b = self::resolve($b, $c->st['bBottom']);
        if ($below && ($c = self::cellAt($below, $j))) $b = self::resolve($b, $c->st['bTop']);

        return $b ?? GridPdfStyle::NONE;
    }

    /** Aresta vertical à esquerda da coluna $j na linha (a da coluna $n é a borda direita). */
    public static function vEdge(GridPdfRow $r, int $j): array
    {
        $b = null;
        foreach ($r->cells as $c) {
            if ($c->col + $c->span === $j) $b = self::resolve($b, $c->st['bRight']);
        }
        foreach ($r->cells as $c) {
            if ($c->col === $j) $b = self::resolve($b, $c->st['bLeft']);
        }

        return $b ?? GridPdfStyle::NONE;
    }

    // ── Larguras (layout automático de tabela) ───────────────────────────

    private function itemMinMax(array $it, bool $first, bool $last): array
    {
        if ($it['t'] === 'badge') {
            // inline-block: min/max do texto + padding da caixa.
            $min = 0.0;
            foreach (array_chunk(preg_split(self::WORDBREAK, $it['s'], -1, PREG_SPLIT_DELIM_CAPTURE), 2) as $ch) {
                $sep = $ch[1] ?? '';
                $word = $sep === ' ' ? $ch[0] : $ch[0] . $sep;
                $min = max($min, $this->m->width($word, $it['font'], $it['size'], $it['cs']));
            }
            $pad = $it['pad'][1] + $it['pad'][3];

            return [$min + $pad, $this->m->width($it['s'], $it['font'], $it['size'], $it['cs']) + $pad];
        }
        $text = $it['s'];
        if ($first) $text = ltrim($text, ' ');
        if ($last) $text = rtrim($text, ' ');
        $min = 0.0;
        foreach (array_chunk(preg_split(self::WORDBREAK, $text, -1, PREG_SPLIT_DELIM_CAPTURE), 2) as $ch) {
            $sep = $ch[1] ?? '';
            $word = $sep === ' ' ? $ch[0] : $ch[0] . $sep;
            $min = max($min, $this->m->width($word, $it['font'], $it['size'], $it['cs']));
        }
        $max = $this->m->width(preg_replace('/\xAD/u', '', $text) ?? '', $it['font'], $it['size'], $it['cs']);

        return [$min, $max];
    }

    /** min/max do border-box da célula (Text/TableCell::get_min_max_width) com bordas pela metade. */
    public function cellMinMax(GridPdfCell $c, float $bl, float $br): array
    {
        $low = [];
        $max = 0.0;
        $k = count($c->items);
        foreach ($c->items as $i => $it) {
            [$mn, $mx] = $this->itemMinMax($it, $i === 0, $i === $k - 1);
            $low[] = $mn;
            $max += $mx;
        }
        $min = $low ? max($low) : 0.0;
        if ($c->width !== null) {
            // Largura declarada: vale se for maior que o conteúdo; min = max.
            $min = max($c->width, $min);
            $max = $min;
        }
        $d = $c->st['pad'][1] + $c->st['pad'][3] + $bl + $br;

        return [$min + $d, $max + $d];
    }

    /**
     * Larguras das colunas a partir de TODAS as linhas (cabeçalho + corpo, na
     * ordem do documento). Uma passada, em fluxo.
     *
     * @return array{W: float[], left: float, right: float, lastLeft: array<string,int>, bottom: float, rows: int}
     *         left/right = meia borda externa da tabela; lastLeft = largura da
     *         borda esquerda externa → índice da ÚLTIMA linha que a usa (a tabela
     *         de continuação de cada página só enxerga as linhas restantes);
     *         bottom = meia borda de baixo da última linha.
     */
    public function columnWidths(iterable $allRows, float $cbW): array
    {
        $n = $this->n;
        $cols = array_fill(0, $n, ['min' => 0.0, 'max' => 0.0, 'abs' => 0.0]);
        $maxLeft = 0.0;
        $maxRight = 0.0;
        $lastLeft = [];
        $ri = -1;
        $lastRow = null;
        foreach ($allRows as $r) {
            $ri++;
            $lastRow = $r;
            $vl = [];
            for ($j = 0; $j <= $n; $j++) $vl[$j] = self::vEdge($r, $j)[0];
            $maxLeft = max($maxLeft, $vl[0]);
            $maxRight = max($maxRight, $vl[$n]);
            $lastLeft[(string) $vl[0]] = $ri;
            foreach ($r->cells as $c) {
                [$fmin, $fmax] = $this->cellMinMax($c, $vl[$c->col] / 2, $vl[$c->col + $c->span] / 2);
                if ($c->width !== null && $c->span === 1 && $fmin > $cols[$c->col]['abs']) {
                    $cols[$c->col]['abs'] = $fmin;
                }
                $min = 0.0;
                $max = 0.0;
                for ($s = 0; $s < $c->span; $s++) {
                    $min += $cols[$c->col + $s]['min'];
                    $max += $cols[$c->col + $s]['max'];
                }
                if ($fmin > $min && $c->span === 1) {
                    $cols[$c->col]['min'] += $fmin - $min;
                }
                if ($fmax > $max) {
                    // colspan reparte o excedente por igual — no momento em que
                    // a célula aparece (a ordem das linhas muda o resultado).
                    $inc = ($fmax - $max) / $c->span;
                    for ($s = 0; $s < $c->span; $s++) $cols[$c->col + $s]['max'] += $inc;
                }
            }
        }
        foreach ($cols as &$col) {
            if ($col['abs'] > 0) {
                $col['abs'] = $col['min'];
                $col['max'] = $col['min'];
            }
        }
        unset($col);

        // Table::_assign_widths com width:100% e sem percentuais.
        $minW = 0.0;
        $maxW = 0.0;
        $absUsed = 0.0;
        $abs = [];
        $auto = [];
        foreach ($cols as $i => $col) {
            $minW += $col['min'];
            $maxW += $col['max'];
            if ($col['abs'] > 0) {
                $abs[] = $i;
                $absUsed += $col['min'];
            } else {
                $auto[] = $i;
            }
        }
        $pref = $cbW - ($maxLeft / 2 + $maxRight / 2);
        $width = $pref > $minW ? $pref : $minW;
        $W = array_fill(0, $n, 0.0);
        if ($width == $maxW) {
            foreach ($cols as $i => $col) $W[$i] = $col['max'];
        } elseif ($width > $minW) {
            if ($auto) {
                foreach ($abs as $i) $W[$i] = $cols[$i]['min'];
                if ($width < $maxW) {
                    $inc = $width - $minW;
                    $td = $maxW - $minW;
                    foreach ($auto as $i) $W[$i] = $cols[$i]['min'] + $inc * (($cols[$i]['max'] - $cols[$i]['min']) / $td);
                } else {
                    $inc = $width - $maxW;
                    $autoMax = $maxW - $absUsed;
                    foreach ($auto as $i) $W[$i] = $cols[$i]['max'] + $inc * ($autoMax > 0 ? $cols[$i]['max'] / $autoMax : 1 / count($auto));
                }
            } else {
                $inc = $width - $absUsed;
                foreach ($abs as $i) $W[$i] = $cols[$i]['min'] + $inc * ($absUsed > 0 ? $cols[$i]['min'] / $absUsed : 1 / count($abs));
            }
        } else {
            foreach ($cols as $i => $col) $W[$i] = $col['min'];
        }

        $bottom = 0.0;
        if ($lastRow !== null) {
            foreach ($lastRow->cells as $c) $bottom = max($bottom, $c->st['bBottom'][0]);
        }

        return ['W' => $W, 'left' => $maxLeft / 2, 'right' => $maxRight / 2, 'lastLeft' => $lastLeft,
                'bottom' => $bottom / 2, 'rows' => $ri + 1];
    }

    // ── Quebra de linha ──────────────────────────────────────────────────

    /**
     * Quebra os itens da célula em linhas na largura $cw (FrameReflower\Text::
     * line_break). Resultado em $c->lines / $c->contentH.
     */
    public function layoutCell(GridPdfCell $c, float $cw): void
    {
        if ($c->layoutWidth !== null && $c->layoutWidth === $cw) {
            return;
        }
        $lines = [];
        $cur = ['frames' => [], 'w' => 0.0, 'h' => 0.0];
        foreach ($c->items as $it) {
            if ($it['t'] === 'badge') {
                $tw = $this->m->width($it['s'], $it['font'], $it['size'], $it['cs']);
                // get_margin_width: padding-left + largura + padding-right, nessa ordem.
                $bw = $it['pad'][3] + $tw + $it['pad'][1];
                if (Helpers::lengthGreater($bw, $cw)) {
                    // O texto quebraria DENTRO da pílula — layout que o motor não faz.
                    throw new GridPdfUnsupported('badge mais largo que a coluna');
                }
                // Altura da caixa: padding + a linha de texto dela (medida na hora
                // do desenho, que depende da posição absoluta — ver drawBadge).
                $bh = $it['pad'][0] + $it['pad'][2] + $this->m->lineHeight($it['font'], $it['size']);
                if ($cur['frames'] && Helpers::lengthGreater($cur['w'] + $bw, $cw)) {
                    $lines[] = $cur;
                    $cur = ['frames' => [], 'w' => 0.0, 'h' => 0.0];
                }
                $cur['frames'][] = ['x' => $cur['w'], 'w' => $bw, 'tw' => $tw, 'mh' => $bh] + $it;
                $cur['w'] += $bw;
                $cur['h'] = max($cur['h'], $bh);
                continue;
            }
            $text = $it['s'];
            $lh = $this->m->lineHeight($it['font'], $it['size']);
            while (true) {
                if (!$cur['frames']) $text = ltrim($text, ' ');
                if ($text === '') break;
                $avail = $cw - $cur['w'];
                $tw = $this->m->width(preg_replace('/\xAD/u', '', $text) ?? '', $it['font'], $it['size'], $it['cs']);
                if (Helpers::lengthLessOrEqual($tw, $avail)) {
                    $cur['frames'][] = ['s' => $text, 'x' => $cur['w'], 'w' => $tw, 'mh' => $lh] + $it;
                    $cur['w'] += $tw;
                    $cur['h'] = max($cur['h'], $lh);
                    break;
                }
                $words = preg_split(self::WORDBREAK, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
                $wc = count($words);
                $width = 0.0;
                $str = '';
                $space = $this->m->width(' ', $it['font'], $it['size'], $it['cs']);
                $sep = '';
                $word = '';
                for ($i = 0; $i < $wc; $i += 2) {
                    $sep = $words[$i + 1] ?? '';
                    $word = $sep === ' ' ? $words[$i] : $words[$i] . $sep;
                    $ww = $this->m->width($word, $it['font'], $it['size'], $it['cs']);
                    $used = $width + $ww;
                    if ($used > 0 && Helpers::lengthGreater($used, $avail)) break;
                    if ($sep === ' ') {
                        $width += $ww + $space;
                        $str .= $word . $sep;
                    } else {
                        $width += $ww;
                        $str .= $word;
                    }
                }
                // A primeira palavra não coube: vai forçada na linha vazia.
                if (!$cur['frames'] && $width === 0.0) {
                    $str = $word;
                }
                $off = mb_strlen($str, 'UTF-8');
                if ($off === 0) {
                    $lines[] = $cur;
                    $cur = ['frames' => [], 'w' => 0.0, 'h' => 0.0];
                    continue;
                }
                $part = mb_substr($text, 0, $off, 'UTF-8');
                $rest = mb_substr($text, $off, null, 'UTF-8');
                $pw = $this->m->width($part, $it['font'], $it['size'], $it['cs']);
                $cur['frames'][] = ['s' => $part, 'x' => $cur['w'], 'w' => $pw, 'mh' => $lh] + $it;
                $cur['w'] += $pw;
                $cur['h'] = max($cur['h'], $lh);
                if ($rest === '') break;
                $lines[] = $cur;
                $cur = ['frames' => [], 'w' => 0.0, 'h' => 0.0];
                $text = $rest;
            }
        }
        if ($cur['frames']) $lines[] = $cur;

        // Espaço no fim da linha não conta para o alinhamento (LineBox::
        // trim_trailing_ws: tira UM espaço e soma de novo a largura da linha).
        foreach ($lines as &$ln) {
            $k = count($ln['frames']) - 1;
            $f = &$ln['frames'][$k];
            if ($f['t'] === 'text' && str_ends_with($f['s'], ' ')) {
                $f['s'] = mb_substr($f['s'], 0, -1, 'UTF-8');
                $f['w'] = $this->m->width($f['s'], $f['font'], $f['size'], $f['cs']);
                $w = 0.0;
                foreach ($ln['frames'] as $fr) $w += $fr['w'];
                $ln['w'] = $w;
            }
            unset($f);
        }
        unset($ln);

        $c->lines = $lines;
        $h = 0.0;
        foreach ($lines as $ln) $h += $ln['h'];
        $c->contentH = $h;
        $c->layoutWidth = $cw;
    }
}
