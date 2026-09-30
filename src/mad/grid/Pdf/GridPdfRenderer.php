<?php
namespace Mad\Grid\Pdf;

use Dompdf\Dompdf;
use Dompdf\Helpers;
use Mad\Grid\GridColumn;
use Mad\Grid\MadGridExporter;

/**
 * PDF da listagem SEM passar a tabela pelo Dompdf.
 *
 * O Dompdf monta DOM + árvore de frames + estilo para cada célula e refaz a
 * tabela restante a cada quebra de página: 1 mil linhas custavam ~20 s e
 * ~700 MB, e o custo crescia mais que linear (3 mil = 4 min / 4,8 GB). Nenhum
 * ambiente (MadCloud 128 MB/60 s, Teste Online, servidor do cliente) exportava
 * nem mil linhas.
 *
 * Aqui a tabela é medida e desenhada direto no canvas CPDF do próprio Dompdf,
 * com o layout do Dompdf reimplementado em GridPdfTable — mesmas fontes,
 * mesmas métricas, mesmas contas, mesmos operadores de desenho. O resultado é
 * o MESMO PDF (a paridade é verificada operador a operador nos testes), em
 * ~0,4 s / ~25 MB por mil linhas, linear.
 *
 * As bandas de cabeçalho/rodapé continuam com o Dompdf, porque são HTML livre
 * do editor (logo, placeholders, posicionamento absoluto): renderizadas UMA
 * vez como objeto PDF e reaproveitadas em toda página. {PAGE_COUNT} é
 * conhecido antes (a paginação vem primeiro) — acabou a 2ª renderização do
 * documento inteiro; {PAGE_NUM} é uma sentinela de mesma largura capturada no
 * render da banda e carimbada em cada página.
 *
 * Duas passadas sobre as linhas, as duas em fluxo: 1) larguras das colunas
 * (dependem de TODAS as linhas, como no layout automático do HTML); 2)
 * paginação e desenho. Página pronta é comprimida na hora.
 *
 * O que o motor não reproduz idêntico (HTML dentro da célula, largura em %…)
 * não chega aqui: supports() recusa antes e o exporter usa o Dompdf.
 */
final class GridPdfRenderer
{
    private Dompdf $dompdf;
    private GridPdfCanvas $canvas;
    private GridPdfTable $table;
    private float $x0;
    private float $y0;
    private float $bottomEdge;
    /** @var float[] */
    private array $W = [];
    /** @var float[] x de cada coluna relativo à tabela */
    private array $colX = [];
    private array $lastLeft = [];
    private float $tableBottom = 0.0;
    private int $n;

    // ── Suporte ──────────────────────────────────────────────────────────

    /**
     * O motor desenha este PDF igual ao Dompdf? Checagem barata, antes de
     * carregar/renderizar — decide também o teto de linhas do MadDataGrid.
     *
     * @param GridColumn[] $columns
     * @param array $headerBand banda normalizada (MadGridExporter::normalizePdfBand)
     */
    public static function supports(array $columns, array $headerBand, array $footerBand): bool
    {
        return self::unsupportedReason($columns, $headerBand, $footerBand) === null;
    }

    /** Motivo de recusa (null = suportado). Público para testes/diagnóstico. */
    public static function unsupportedReason(array $columns, array $headerBand, array $footerBand): ?string
    {
        if ($columns === []) {
            return 'sem colunas';
        }
        foreach ($columns as $col) {
            // Transform que devolve HTML: markup arbitrário dentro da célula.
            if ($col->isHtml) {
                return "coluna {$col->field} com HTML";
            }
            // Rótulo entra CRU no HTML da quebra inline (totais): tag viraria markup.
            if (str_contains((string) $col->label, '<')) {
                return "rótulo com HTML na coluna {$col->field}";
            }
            try {
                GridPdfTable::columnWidth($col);
            } catch (GridPdfUnsupported) {
                return "largura '{$col->width}' na coluna {$col->field}";
            }
        }
        foreach ([$headerBand, $footerBand] as $band) {
            if (($band['mode'] ?? '') !== 'custom') {
                continue;
            }
            // Contador CSS escrito à mão (fora do {PAGE_NUM}): no objeto de
            // banda reaproveitado ele congelaria no número da 1ª página.
            if (str_contains($band['html'], 'counter(') || str_contains($band['html'], 'mad-page')) {
                return 'banda com contador CSS próprio';
            }
        }

        return null;
    }

    // ── Render ───────────────────────────────────────────────────────────

    /**
     * @param GridColumn[] $columns
     * @param iterable $rows linhas (array ou iterável contável) — ignorado com $groupData
     * @param array $headerBand banda normalizada
     * @return string bytes do PDF
     * @throws GridPdfUnsupported conteúdo que só o Dompdf desenha igual
     */
    public static function render(
        array $columns, iterable $rows, string $title, array $groupData,
        array $headerBand, array $footerBand, array $grandTotals, array $report
    ): string {
        return (new self($columns, $report))->run($rows, $title, $groupData, $headerBand, $footerBand, $grandTotals, $report, false);
    }

    /**
     * Só o layout (sem PDF): larguras das colunas e linhas por página. Seam de
     * teste da paginação.
     *
     * @return array{widths: float[], pages: int[]}
     */
    public static function layout(
        array $columns, iterable $rows, string $title, array $groupData,
        array $headerBand, array $footerBand, array $grandTotals, array $report
    ): array {
        return (new self($columns, $report))->run($rows, $title, $groupData, $headerBand, $footerBand, $grandTotals, $report, true);
    }

    private function __construct(array $columns, array $report = [])
    {
        // Mesma orientação e paleta que o caminho Dompdf usa (paridade).
        $orientation = MadGridExporter::pdfOrientation($report);
        $this->dompdf = new Dompdf(MadGridExporter::PDF_DOMPDF_OPTIONS);
        $this->dompdf->setPaper('A4', $orientation);
        $this->canvas = new GridPdfCanvas('a4', $orientation, $this->dompdf);
        $this->dompdf->setCanvas($this->canvas);
        $fm = $this->dompdf->getFontMetrics();
        $fm->setCanvas($this->canvas);
        $metrics = new GridPdfMetrics($this->canvas, $fm, $this->dompdf->getOptions()->getIsFontSubsettingEnabled());
        $this->table = new GridPdfTable($metrics, $columns, MadGridExporter::pdfPalette($report));
        $this->n = $this->table->columnCount();
    }

    private function run(
        iterable $rows, string $title, array $groupData, array $headerBand, array $footerBand,
        array $grandTotals, array $report, bool $layoutOnly
    ): string|array {
        $totalRows = !empty($groupData)
            ? count(array_filter($groupData, fn($i) => $i['type'] === 'row'))
            : (is_countable($rows) ? count($rows) : iterator_count((fn() => yield from $rows)()));

        // Margens: mesma regra do HTML (banda vazia encolhe a margem para 8mm).
        $parts = MadGridExporter::pdfDocumentParts($title, $headerBand, $footerBand, $totalRows, null, $report);
        $H = $this->canvas->get_height();
        $W = $this->canvas->get_width();
        // Page reflower: conteúdo em (0 + margem), largura = página − esquerda − direita.
        $this->x0 = 0.0 + GridPdfStyle::length('8mm');
        $this->y0 = 0.0 + GridPdfStyle::length($parts['marginTop'] . 'mm');
        $this->bottomEdge = $H - GridPdfStyle::length($parts['marginBottom'] . 'mm');
        $cbW = $W - GridPdfStyle::length('8mm') - GridPdfStyle::length('8mm');

        // Passada 1: larguras (todas as linhas, em fluxo).
        $header = $this->table->headerRow();
        $all = (function () use ($header, $rows, $groupData, $grandTotals, $report) {
            yield $header;
            yield from $this->table->bodyRows($rows, $groupData, $grandTotals, $report);
        })();
        $cw = $this->table->columnWidths($all, $cbW);
        $this->W = $cw['W'];
        $x = $cw['left'];
        foreach ($this->W as $j => $w) {
            $this->colX[$j] = $x;
            $x += $w;
        }
        $this->lastLeft = $cw['lastLeft'];
        $this->tableBottom = $cw['bottom'];

        // Passada 2: paginação e desenho.
        $pages = $this->paginate($header, $this->table->bodyRows($rows, $groupData, $grandTotals, $report), $layoutOnly);
        if ($layoutOnly) {
            return ['widths' => $this->W, 'pages' => $pages];
        }
        $pageCount = count($pages);

        $this->bands($title, $headerBand, $footerBand, $totalRows, $pageCount, $report);

        return (string) $this->canvas->output(['compress' => 1]);
    }

    // ── Paginação ────────────────────────────────────────────────────────

    /**
     * Distribui as linhas nas páginas como o Dompdf (Page::check_page_break):
     * a linha vai para a próxima página quando o fundo dela + a meia borda de
     * baixo da tabela passa da margem inferior; o cabeçalho da tabela se
     * repete em toda página. Desenha cada página assim que ela fecha.
     *
     * As posições seguem a MESMA aritmética do Dompdf (y da linha relativo à
     * tabela, somado linha a linha): o PDF grava coordenadas com 3 casas e
     * uma soma em outra ordem já muda a última casa.
     *
     * @return int[] linhas por página
     */
    private function paginate(GridPdfRow $header, \Generator $body, bool $layoutOnly): array
    {
        $counts = [];
        $list = [];
        $headerG = null;  // geometria do cabeçalho da página (depende da 1ª linha)
        $rel = null;      // y da próxima linha, relativo ao topo da tabela da página
        $prev = null;
        $idx = 0;
        $firstIdx = 1;
        $contentsId = $this->canvas->get_cpdf()->getFirstPageId();

        $cur = $body->valid() ? $body->current() : null;
        $body->next();
        $next = $body->valid() ? $body->current() : null;

        if ($cur === null) {
            // Só o cabeçalho (listagem vazia).
            if (!$layoutOnly) $this->drawPage($header, $this->geometry($header, null, null, $this->y0 + 0.0), [], 1);
            return [0];
        }

        while ($cur !== null) {
            $idx++;
            if ($rel === null) {
                $headerG = $this->geometry($header, null, $cur, $this->y0 + 0.0);
                $rel = 0.0 + $headerG['h'];
                $prev = $header;
                $firstIdx = $idx;
            }
            $g = $this->geometry($cur, $prev, $next, $this->y0 + $rel);
            $fits = Helpers::lengthLessOrEqual($this->y0 + $rel + $g['h'] + $this->tableBottom, $this->bottomEdge);
            if (!$fits && $list) {
                $counts[] = count($list);
                if (!$layoutOnly) {
                    $this->drawPage($header, $headerG, $list, $firstIdx);
                    $newId = $this->canvas->new_page();
                    $this->canvas->freeze($contentsId);
                    $contentsId = $newId;
                }
                $list = [];
                $rel = null;
                $idx--;
                continue;
            }
            if (!$fits) {
                // Linha mais alta que a página inteira: o Dompdf empurra/estoura
                // de um jeito que o motor não reproduz.
                throw new GridPdfUnsupported('linha maior que a página');
            }
            $list[] = [$cur, $g, $rel];
            $rel = $rel + $g['h'];
            $prev = $cur;
            $cur = $next;
            if ($cur !== null) {
                $body->next();
                $next = $body->valid() ? $body->current() : null;
            }
        }
        $counts[] = count($list);
        if (!$layoutOnly) {
            $this->drawPage($header, $headerG, $list, $firstIdx);
        }

        return $counts;
    }

    /**
     * Geometria de uma linha na posição absoluta $cellY (topo da linha):
     * bordas resolvidas contra a linha de cima e a de baixo (border-collapse),
     * linhas de texto de cada célula e a altura da linha — com as contas de
     * TableCell::reflow (a altura do conteúdo sai de posições ABSOLUTAS).
     */
    private function geometry(GridPdfRow $r, ?GridPdfRow $prev, ?GridPdfRow $next, float $cellY): array
    {
        $n = $this->n;
        $vl = [];
        for ($j = 0; $j <= $n; $j++) $vl[$j] = GridPdfTable::vEdge($r, $j);
        $top = [];
        $bot = [];
        for ($j = 0; $j < $n; $j++) {
            $top[$j] = GridPdfTable::hEdge($prev, $r, $j);
            $bot[$j] = GridPdfTable::hEdge($r, $next, $j);
        }
        $h = 0.0;
        $geo = [];
        foreach ($r->cells as $c) {
            $bt = 0.0;
            $bb = 0.0;
            $w = 0;
            for ($k = $c->col; $k < $c->col + $c->span; $k++) {
                $bt = max($bt, $top[$k][0]);
                $bb = max($bb, $bot[$k][0]);
                $w += $this->W[$k];
            }
            $bt /= 2;
            $bb /= 2;
            $bl = $vl[$c->col][0] / 2;
            $br = $vl[$c->col + $c->span][0] / 2;
            [$pt, $pr, $pb, $pl] = $c->st['pad'];
            $cw = $w - ($pl + $bl) - ($pr + $br);
            $this->table->layoutCell($c, $cw);

            $topSpace = $pt + $bt;
            $contentY = $cellY + $topSpace;
            $lineY = [];
            $ly = $contentY;
            $end = $contentY;
            foreach ($c->lines as $ln) {
                $lineY[] = $ly;
                $end = $ly + $ln['h'];
                $ly = $end;
            }
            $contentH = $end - ($cellY + ($bt + $pt));
            $h = max($h, $contentH + ($topSpace + ($pb + $bb)));
            $geo[] = ['bt' => $bt, 'bb' => $bb, 'bl' => $bl, 'br' => $br, 'w' => $w, 'cw' => $cw,
                      'contentH' => $contentH, 'lineY' => $lineY];
        }

        return ['h' => $h, 'geo' => $geo, 'vl' => $vl, 'top' => $top, 'bot' => $bot];
    }

    // ── Desenho ──────────────────────────────────────────────────────────

    /** Uma página: cabeçalho da tabela + linhas. */
    private function drawPage(GridPdfRow $header, array $headerG, array $list, int $firstIdx): void
    {
        // A tabela de continuação é refeita pelo Dompdf a cada página só com
        // as linhas restantes: a borda esquerda externa dela é a maior entre
        // ESSAS linhas, e só a coluna 0 se desloca (as outras ficam travadas
        // nas posições da 1ª página — Cellmap::lock_columns).
        $lb = 0.0;
        foreach ($this->lastLeft as $w => $last) {
            if ($last >= $firstIdx) $lb = max($lb, (float) $w);
        }
        $cx = $this->colX;
        $cx[0] = 0.0 + (0.0 + $lb / 2);

        $this->drawRow($header, $headerG, 0.0, $list === [], $cx);
        $k = count($list);
        foreach ($list as $i => [$row, $g, $rel]) {
            $this->drawRow($row, $g, $rel, $i === $k - 1, $cx);
        }
    }

    /**
     * Renderer\TableCell no modelo colapsado: fundo no padding box, bordas
     * (_render_collapsed_border), depois o texto — com as contas do Dompdf.
     */
    private function drawRow(GridPdfRow $r, array $g, float $rowY, bool $lastOnPage, array $cx): void
    {
        $cv = $this->canvas;
        $n = $this->n;
        $W = $this->W;
        $rowH = $g['h'];
        $tx = $this->x0;
        $ty = $this->y0;
        $absY = $ty + $rowY;
        foreach ($r->cells as $ci => $c) {
            $G = $g['geo'][$ci];
            [$pt, $pr, $pb, $pl] = $c->st['pad'];
            $cv->set_opacity(1.0);
            $cellX = $tx + $cx[$c->col];

            // TableCell::set_cell_height: altura útil e deslocamento do middle.
            $newH = $rowH - ($pt + $G['bt'] + $G['bb'] + $pb);
            $mid = $newH > $G['contentH'] ? ($newH - $G['contentH']) / 2 : 0.0;

            if ($c->st['bg'] !== null) {
                // Frame::get_padding_box
                $cv->filled_rectangle($cellX + $G['bl'], $absY + $G['bt'], $pl + $G['cw'] + $pr, $pt + $pb + $newH, $c->st['bg']);
            }

            // Bordas horizontais: cada célula desenha a de CIMA; a última linha
            // da página desenha também a de baixo.
            for ($j = $c->col; $j < $c->col + $c->span; $j++) {
                $bT = $g['top'][$j];
                $bL = $g['vl'][$j][0];
                $bR = $g['vl'][$j + 1][0];
                $lx = $tx + $cx[$j] - $bL / 2;
                $lw = $W[$j] + ($bL + $bR) / 2;
                if ($bT[0] > 0) {
                    $ly = $ty + $rowY - $bT[0] / 2;
                    $ly += $bT[0] / 2;
                    $cv->line($lx, $ly, $lx + $lw, $ly, $bT[2], $bT[0], [], 'butt');
                }
                $bB = $g['bot'][$j];
                if ($lastOnPage && $bB[0] > 0) {
                    $by = $ty + $rowY + $rowH + $bB[0] / 2;
                    $by -= $bB[0] / 2;
                    $cv->line($lx, $by, $lx + $lw, $by, $bB[2], $bB[0], [], 'butt');
                }
            }
            // Bordas verticais: a esquerda da célula; a direita só na última coluna.
            $bL = $g['vl'][$c->col];
            if ($bL[0] > 0) {
                $bT = $g['top'][$c->col][0];
                $bB = $g['bot'][$c->col][0];
                $vx = $tx + $cx[$c->col] - $bL[0] / 2;
                $vy = $ty + $rowY - $bT / 2;
                $vh = $rowH + ($bT + $bB) / 2;
                $vx += $bL[0] / 2;
                $cv->line($vx, $vy, $vx, $vy + $vh, $bL[2], $bL[0], [], 'butt');
            }
            if ($c->col + $c->span === $n && $g['vl'][$n][0] > 0) {
                $bR = $g['vl'][$n];
                $bT = $g['top'][$n - 1][0];
                $bB = $g['bot'][$n - 1][0];
                $vx = $tx + $cx[$n - 1] + $W[$n - 1] + $bR[0] / 2;
                $vy = $ty + $rowY - $bT / 2;
                $vh = $rowH + ($bT + $bB) / 2;
                $vx -= $bR[0] / 2;
                $cv->line($vx, $vy, $vx, $vy + $vh, $bR[2], $bR[0], [], 'butt');
            }

            // Texto (Inline positioner + Block::_text_align + vertical_align).
            $cbx = $cellX + ($pl + $G['bl']);
            foreach ($c->lines as $li => $ln) {
                $dx = match ($c->align) {
                    'right' => $G['cw'] - $ln['w'],
                    'center' => ($G['cw'] + 0.0 - $ln['w']) / 2,
                    default => 0.0,
                };
                $lineY = $G['lineY'][$li];
                foreach ($ln['frames'] as $f) {
                    $fx = $cbx + $f['x'];
                    if ($dx != 0.0) $fx += $dx;
                    if ($f['t'] === 'badge') {
                        $this->drawBadge($f, $fx, $lineY, $mid);
                        continue;
                    }
                    if ($f['s'] === '') continue;
                    // vertical_align (baseline dentro da célula) e depois o middle.
                    $fy = $lineY + ($ln['h'] * 0.8 - $this->table->m->baseline($f['font'], $f['size']));
                    $fy += $mid;
                    $cv->set_opacity($f['op']);
                    $cv->text($fx, $fy, $f['s'], $f['font'], $f['size'], $f['color'], 0.0, $f['cs']);
                }
            }
        }
        $cv->set_opacity(1.0);
    }

    /**
     * Badge: inline-block sozinho na linha (sem deslocamento vertical —
     * workaround do Block::vertical_align), com fundo em pílula e o texto dele.
     * A caixa é medida na posição de layout e depois movida pelo middle da célula.
     */
    private function drawBadge(array $f, float $bx, float $lineY, float $mid): void
    {
        $cv = $this->canvas;
        [$pt, $pr, $pb, $pl] = $f['pad'];
        $m = $this->table->m;
        $lh = $m->lineHeight($f['font'], $f['size']);
        // Block::reflow do inline-block: conteúdo em y + padding-top; altura do conteúdo pela posição absoluta.
        $contentY = $lineY + $pt;
        $height = ($contentY + $lh) - ($lineY + $pt);
        $bw = $pl + $f['tw'] + $pr;
        $bh = $pt + $pb + $height;
        $by = $lineY + $mid;

        $cv->set_opacity(1.0);
        if ($f['bg'] !== null) {
            // Style::resolve_border_radius: raios somados maiores que o lado encolhem na proporção.
            [$tl, $tr, $brr, $bl] = [$f['radius'], $f['radius'], $f['radius'], $f['radius']];
            if ($tl + $tr + $brr + $bl > 0) {
                if ($tl + $bl > $bh) { $k = $bh / ($tl + $bl); $tl = $k * $tl; $bl = $k * $bl; }
                if ($tr + $brr > $bh) { $k = $bh / ($tr + $brr); $tr = $k * $tr; $brr = $k * $brr; }
                if ($tl + $tr > $bw) { $k = $bw / ($tl + $tr); $tl = $k * $tl; $tr = $k * $tr; }
                if ($bl + $brr > $bw) { $k = $bw / ($bl + $brr); $bl = $k * $bl; $brr = $k * $brr; }
                $cv->clipping_roundrectangle($bx, $by, $bw, $bh, $tl, $tr, $brr, $bl);
            }
            $cv->filled_rectangle($bx, $by, $bw, $bh, $f['bg']);
            if ($f['radius'] > 0) {
                $cv->clipping_end();
            }
        }
        $cv->set_opacity(1.0);
        $ty = $contentY + ($lh * 0.8 - $m->baseline($f['font'], $f['size']));
        $ty += $mid;
        $cv->text($bx + $pl, $ty, $f['s'], $f['font'], $f['size'], $f['color'], 0.0, $f['cs']);
    }

    // ── Bandas ───────────────────────────────────────────────────────────

    /**
     * Bandas renderizadas pelo Dompdf como objetos PDF (um por quantidade de
     * dígitos do número de página — "9" e "10" não ocupam a mesma largura) e
     * adicionadas em cada página, mais o número da página e o "Página X de Y".
     */
    private function bands(string $title, array $headerBand, array $footerBand, int $totalRows, int $pageCount, array $report): void
    {
        $cv = $this->canvas;
        $cpdf = $cv->get_cpdf();
        $parts = MadGridExporter::pdfDocumentParts($title, $headerBand, $footerBand, $totalRows, $pageCount, $report);
        $pageTag = MadGridExporter::PDF_PAGE_NUM_HTML;
        $needNum = str_contains($parts['bands'], $pageTag);
        // Uma variante por quantidade de dígitos do número de página (1–9, 10–99…).
        $variants = $needNum ? range(1, strlen((string) $pageCount)) : [0];

        $objs = [];
        if ($parts['bands'] !== '') {
            foreach ($variants as $digits) {
                // Sentinela com a MESMA largura do número (dígitos da Helvetica
                // têm largura igual): o texto em volta fica onde ficaria.
                $html = $parts['head']
                    . str_replace($pageTag, '<span class="mad-page-sentinel">' . str_repeat('0', max(1, $digits)) . '</span>', $parts['bands'])
                    . '</body></html>';
                $bd = new Dompdf(MadGridExporter::PDF_DOMPDF_OPTIONS);
                $bd->setCanvas($cv);
                $bd->getFontMetrics()->setCanvas($cv);
                $bd->setCallbacks([['event' => 'begin_frame', 'f' => function ($frame, $canvas) {
                    $p = $frame->get_parent();
                    if ($frame->is_text_node() && $p !== null && $p->get_node() instanceof \DOMElement
                        && $p->get_node()->getAttribute('class') === 'mad-page-sentinel') {
                        $canvas->captureNext = true;
                    }
                }]]);
                $bd->loadHtml($html);
                $pagesBefore = $cv->get_page_count();
                $cv->captured = [];
                $obj = $cv->open_object();
                $cv->resetGraphicState();
                $bd->render();
                $cv->close_object();
                if ($cv->get_page_count() !== $pagesBefore) {
                    // HTML da banda que abre página própria: o objeto não serve.
                    throw new GridPdfUnsupported('banda gerou página');
                }
                $objs[$digits] = ['id' => $obj, 'cap' => $cv->captured];
                unset($bd);
            }
        }

        $pageOf = MadGridExporter::pdfPageOf($footerBand, $cv->get_width(), $cv->get_height());
        $font = $this->dompdf->getFontMetrics()->getFont('Helvetica');
        $last = null;
        foreach ($cv->pageContentIds() as $i => $pid) {
            $pn = $i + 1;
            $v = $objs[$needNum ? strlen((string) $pn) : 0] ?? null;
            // Decorações da página num objeto próprio (a página já pode estar
            // comprimida): número da banda custom + "Página X de Y".
            $deco = null;
            if (($v !== null && $v['cap'] !== []) || $pageOf !== null) {
                $deco = $cv->open_object();
                $cv->resetGraphicState();
                foreach ($v['cap'] ?? [] as $cp) {
                    $cv->set_opacity($cp['op']);
                    $cv->text($cp['x'], $cp['y'], (string) $pn, $cp['font'], $cp['size'], $cp['color'], $cp['word_space'], $cp['char_space'], $cp['angle']);
                }
                if ($pageOf !== null) {
                    // Mesmo desenho do page_text do caminho Dompdf.
                    $cv->set_opacity(1.0);
                    $cv->text($pageOf['x'], $pageOf['y'], str_replace(['{PAGE_NUM}', '{PAGE_COUNT}'], [$pn, $pageCount], $pageOf['text']),
                        $font, $pageOf['size'], $pageOf['color']);
                }
                $cv->close_object();
            }
            $cpdf->reopenObject($pid);
            if ($v !== null) $cpdf->addObject($v['id'], 'add');
            if ($deco !== null) $cpdf->addObject($deco, 'add');
            $cpdf->closeObject();
            $last = $pid;
        }
        if ($last !== null) {
            $cv->freeze($last);
        }
    }
}
