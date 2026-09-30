<?php
namespace Mad\Grid;

use Dompdf\Dompdf;

/**
 * MadGridExporter — gera CSV, XLSX e PDF a partir de colunas e rows do MadDataGrid.
 * Suporta agrupamentos via $groupData (array achatado com types: group, row, group-total).
 */
class MadGridExporter
{
    /**
     * Paleta do PDF (quebras, totais, linha do cabeçalho). Estava espalhada em hex
     * literais dentro do <style>; centralizar é o que permite o projeto
     * sobrescrever a cor pelo `"palette"` do app/config/pdf-export.json
     * (MadGridPdfBranding::palette). Os valores aqui são exatamente os de
     * sempre — sem config, o PDF sai byte a byte igual.
     */
    public const PDF_PALETTE = [
        'group0Bg'          => '#eff6ff',
        'group0Fg'          => '#1e3a8a',
        'group0Rule'        => '#2563eb',
        'group0Border'      => '#dbeafe',
        'group1Bg'          => '#f8fafc',
        'group1Fg'          => '#334155',
        'group1Rule'        => '#cbd5e1',
        'groupTotalBg'      => '#f1f5f9',
        'groupTotalFg'      => '#334155',
        'groupTotalBorder'  => '#e2e8f0',
        'grandTotalBg'      => '#0f172a',
        'grandTotalFg'      => '#fff',
        'grandTotalBorder'  => '#020617',
        // Linha abaixo do cabeçalho padrão (vitrine). `none`/`transparent`
        // tiram a linha.
        'headerRule'        => '#2563eb',
    ];

    // ── CSV ──────────────────────────────────────────────────────────────

    /**
     * @param GridColumn[] $columns
     * @param array $groupData Array achatado de _computeGroupData() (opcional)
     * @return string chave output/<uniqid>.csv no scratch (MadScratchStorage)
     */
    public static function csv(array $columns, array $rows, string $title = 'export', array $groupData = [], array $grandTotals = []): string
    {
        $w = GridCsvWriter::open($columns);
        try {
            if (!empty($groupData)) {
                $w->groupItems($groupData);
            } else {
                foreach ($rows as $row) {
                    $w->dataRow($row);
                }
            }
            $w->grandTotalRow($grandTotals);

            return $w->finish();
        } catch (\Throwable $e) {
            $w->abort();
            throw $e;
        }
    }

    // ── XLSX ─────────────────────────────────────────────────────────────

    /**
     * XLSX com as linhas já carregadas. A escrita é do GridXlsxWriter (em
     * fluxo, OpenSpout); a exportação do MadDataGrid usa o writer direto,
     * lote a lote, sem carregar tudo.
     *
     * @param GridColumn[] $columns
     * @param array $groupData Array achatado de _computeGroupData() (opcional)
     * @return string chave output/<uniqid>.xlsx no scratch (MadScratchStorage)
     */
    public static function xlsx(array $columns, array $rows, string $title = 'export', array $groupData = [], array $grandTotals = []): string
    {
        $columns = array_values($columns);
        self::inferSourceTypes($columns, $rows, $groupData);

        $w = GridXlsxWriter::open($columns, $title);
        try {
            if (!empty($groupData)) {
                $w->groupItems($groupData);
            } else {
                foreach ($rows as $row) {
                    $w->dataRow($row);
                }
            }
            $w->grandTotalRow($grandTotals, GridRenderHelpers::computeRawTotals($rows, $columns));

            return $w->finish();
        } catch (\Throwable $e) {
            $w->abort();
            throw $e;
        }
    }

    /**
     * Coluna sem tipo cuja fonte o MadDataGrid não descobriu (grid de array,
     * alias, accessor): decide pela coluna inteira se o texto "12.50" é número.
     *
     * @param GridColumn[] $columns
     */
    public static function inferSourceTypes(array $columns, array $rows, array $groupData = []): void
    {
        $pending = array_filter($columns, fn (GridColumn $c) =>
            $c->sourceNumeric === null && GridExportSourceTypes::needsHint($c));
        if ($pending === []) {
            return;
        }

        if (!empty($groupData)) {
            $rows = [];
            foreach ($groupData as $item) {
                if (($item['type'] ?? '') === 'row') {
                    $rows[] = $item['data'];
                }
            }
        }

        foreach ($pending as $col) {
            $col->sourceNumeric = GridExportCell::inferSourceNumeric($col, $rows);
        }
    }

    // ── PDF ──────────────────────────────────────────────────────────────

    /** Opções do Dompdf do PDF da listagem (caminho HTML e bandas do motor direto). */
    public const PDF_DOMPDF_OPTIONS = ['isRemoteEnabled' => false, 'defaultFont' => 'Helvetica'];

    /** O que {PAGE_NUM} vira no HTML das bandas: contador CSS de página do Dompdf. */
    public const PDF_PAGE_NUM_HTML = '<span class="mad-page"></span>';

    /**
     * Exporta para PDF com bandas de header/footer repetidas em TODA página
     * (mesmo contrato dos componentes <mad-doc-*-band>) e paginação.
     *
     * Dois desenhistas, o MESMO PDF: o motor direto (Pdf\GridPdfRenderer —
     * mede e desenha a tabela sem montar HTML; ~0,4 s e ~25 MB por mil linhas)
     * e o Dompdf lendo o buildPdfHtml() (~20 s e ~700 MB por mil linhas,
     * crescendo mais que linear). O motor é usado sempre que o conteúdo é algo
     * que ele reproduz idêntico; o resto (HTML dentro da célula, largura em %…)
     * cai no Dompdf.
     *
     * @param GridColumn[] $columns
     * @param array $groupData Array achatado de _computeGroupData() (opcional)
     * @param string|array $header Banda de header. String preserva o contrato
     *        legado: '' = vitrine default; HTML custom com placeholders
     *        {TITLE} {SUBTITLE} {DATE} {PERIOD} {FILTERS} {TOTAL_REGISTER}
     *        {APP_NAME} {UNIT_NAME} {USER_NAME} {TENANT_NAME} {LOGO}
     *        {LOGO_SMALL} {PAGE_NUM} {PAGE_COUNT} ({TOTAL} = alias legado).
     *        Array = band config resolvido pelo MadDataGrid (_resolvePdfBands):
     *        ['mode' => 'custom|none|default', 'html', 'heightMm'].
     * @param string|array $footer Banda de footer. String legada: '' = footer
     *        default (texto + paginação); texto custom substitui só o lado
     *        esquerdo do footer default (strip_tags — contrato antigo).
     *        Array = band config (+ 'showPagination').
     * @param array $report Metadados de relatório (MadDataGrid::$exportMeta):
     *        'subtitle', 'period', 'filters' (placeholders das bandas),
     *        'groupBand' ('inline'|'cells'), 'rowDetail' (bool),
     *        'placeholders' (mapa próprio do app — `'{CNPJ}' => 'texto'`, chave
     *        com/sem chaves; ver MadGridPdfPlaceholders: texto escapado, vence
     *        os built-ins de texto, nunca os estruturais),
     *        'pdfHtmlMaxRows' (teto de linhas quando o PDF cai no Dompdf —
     *        acima dele lança Pdf\GridPdfRowLimitException em vez de estourar
     *        a memória). Tudo opcional — `[]` reproduz o comportamento anterior.
     * @throws Pdf\GridPdfRowLimitException
     */
    public static function pdf(
        array $columns, array $rows, string $title = 'export',
        array $groupData = [], string|array $header = '', string|array $footer = '',
        array $grandTotals = [], array $report = []
    ): string {
        // PDF gerado em memória e persistido no scratch via Storage.
        $key = 'output/' . uniqid() . '.pdf';

        $headerBand = self::normalizePdfBand($header, 'header');
        $footerBand = self::normalizePdfBand($footer, 'footer');

        $bytes = null;
        if (Pdf\GridPdfRenderer::supports($columns, $headerBand, $footerBand)) {
            try {
                $bytes = Pdf\GridPdfRenderer::render($columns, $rows, $title, $groupData, $headerBand, $footerBand, $grandTotals, $report);
            } catch (Pdf\GridPdfUnsupported) {
                // Só descoberto ao medir (badge que quebraria dentro da pílula,
                // linha maior que a página…): o Dompdf desenha.
                $bytes = null;
            } catch (\Throwable $e) {
                // Erro inesperado do motor (ex.: um Dompdf mais novo mudou algo
                // do Cpdf que o motor usa): o Dompdf desenha e o erro vai para o
                // log — o export não cai. Os testes de paridade chamam o motor
                // direto, então um erro desses ainda quebra a suíte.
                \Illuminate\Support\Facades\Log::warning(
                    '[MadGridExporter] PDF: motor direto falhou, usando o Dompdf: ' . $e->getMessage(),
                    ['exception' => $e]
                );
                $bytes = null;
            }
        }

        if ($bytes === null) {
            $max   = (int) ($report['pdfHtmlMaxRows'] ?? 0);
            $count = self::pdfRowCount($rows, $groupData);
            if ($max > 0 && $count > $max) {
                throw new Pdf\GridPdfRowLimitException($max, $count);
            }
            $bytes = self::pdfViaDompdf($columns, $rows, $title, $groupData, $headerBand, $footerBand, $grandTotals, $report);
        }

        \Mad\Service\MadScratchStorage::putContent($key, $bytes);
        return $key;
    }

    /**
     * O PDF desta exportação sai pelo motor direto? Barato (só colunas e
     * bandas) — o MadDataGrid usa para escolher o teto de linhas ANTES de
     * carregar os dados.
     *
     * @param GridColumn[] $columns
     */
    public static function pdfUsesDirectRenderer(array $columns, string|array $header = '', string|array $footer = ''): bool
    {
        return Pdf\GridPdfRenderer::supports(
            array_values($columns),
            self::normalizePdfBand($header, 'header'),
            self::normalizePdfBand($footer, 'footer')
        );
    }

    /** Linhas de DADO da exportação (o que o teto conta). */
    public static function pdfRowCount(array $rows, array $groupData = []): int
    {
        return !empty($groupData)
            ? count(array_filter($groupData, fn($i) => ($i['type'] ?? '') === 'row'))
            : count($rows);
    }

    /**
     * Caminho HTML: Dompdf renderizando o buildPdfHtml(). É o fallback do
     * motor direto e a referência da paridade nos testes.
     *
     * @internal
     * @return string bytes do PDF
     */
    public static function pdfViaDompdf(
        array $columns, array $rows, string $title, array $groupData,
        string|array $header, string|array $footer, array $grandTotals = [], array $report = []
    ): string {
        $headerBand = self::normalizePdfBand($header, 'header');
        $footerBand = self::normalizePdfBand($footer, 'footer');

        $render = function (?int $pageCount) use ($columns, $rows, $title, $groupData, $headerBand, $footerBand, $grandTotals, $report): Dompdf {
            $html = self::buildPdfHtml($columns, $rows, $title, $groupData, $headerBand, $footerBand, $grandTotals, $pageCount, $report);
            $dompdf = new Dompdf(self::PDF_DOMPDF_OPTIONS);
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4', self::pdfOrientation($report));
            $dompdf->render();
            return $dompdf;
        };

        $dompdf = $render(null);

        // Dompdf não tem o counter CSS "pages" (sempre 0) — quando o HTML
        // custom embute {PAGE_COUNT}, renderiza de novo com o total literal.
        if (self::pdfBandsNeedPageCount($headerBand, $footerBand)) {
            $dompdf = $render($dompdf->getCanvas()->get_page_count());
        }

        // Paginação padrão "Página X de Y" — overlay pós-render por página
        // (page_text interpola {PAGE_NUM}/{PAGE_COUNT} nativamente; evita o
        // segundo render no caminho comum).
        $canvas = $dompdf->getCanvas();
        $pageOf = self::pdfPageOf($footerBand, $canvas->get_width(), $canvas->get_height());
        if ($pageOf !== null) {
            $font = $dompdf->getFontMetrics()->getFont('Helvetica');
            $canvas->page_text($pageOf['x'], $pageOf['y'], $pageOf['text'], $font, $pageOf['size'], $pageOf['color']);
        }

        return (string) $dompdf->output();
    }

    /**
     * "Página X de Y" do rodapé: texto ({PAGE_NUM}/{PAGE_COUNT} por
     * interpolar) e posição — dentro da banda do footer (altura em mm → pt),
     * alinhado à direita. null = não desenhar. Os dois caminhos usam isto.
     *
     * @internal
     * @return array{text: string, x: float, y: float, size: float, color: float[]}|null
     */
    public static function pdfPageOf(array $footerBand, float $pageWidth, float $pageHeight): ?array
    {
        $pageOf = self::pdfPaginationText($footerBand);
        if ($pageOf === null) {
            return null;
        }
        $bandTop = $pageHeight - $footerBand['heightMm'] * 72 / 25.4;
        $x = $pageWidth - 24 - 8 * strlen(strtr($pageOf, ['{PAGE_NUM}' => '0', '{PAGE_COUNT}' => '0'])) * 0.55;

        return ['text' => $pageOf, 'x' => $x, 'y' => $bandTop + 8, 'size' => 8, 'color' => [0.42, 0.45, 0.5]];
    }

    /**
     * Paleta efetiva do PDF: default do exporter < projeto (pdf-export.json)
     * < página (`$report['palette']`, vindo do exportPdfBands()). Os dois
     * motores leem daqui — o CSS do Dompdf e a tabela do motor direto —, então
     * a cor que a página escolhe não diverge entre eles.
     *
     * @internal
     * @return array<string,string>
     */
    public static function pdfPalette(array $report = []): array
    {
        return MadGridPdfBranding::sanitizePalette($report['palette'] ?? [], MadGridPdfBranding::palette());
    }

    /**
     * Orientação do papel: página (`$report['orientation']`, vindo do
     * exportPdfBands()) > projeto (pdf-export.json) > paisagem.
     *
     * @internal
     * @return 'portrait'|'landscape'
     */
    public static function pdfOrientation(array $report = []): string
    {
        return MadGridPdfBranding::normalizeOrientation($report['orientation'] ?? null)
            ?? MadGridPdfBranding::orientation()
            ?? 'landscape';
    }

    /**
     * Partes do documento que não são a tabela: <head> com o CSS, as bandas
     * fixas já com placeholders e as margens da página. O buildPdfHtml() monta
     * o HTML com elas; o motor direto renderiza SÓ elas no Dompdf (bandas).
     *
     * @internal
     * @param ?int $pageCount Total de páginas para {PAGE_COUNT} (null = span
     *        de counter, que o Dompdf renderiza como 0 — 1ª passada do HTML).
     * @return array{head: string, bands: string, headerBand: array, footerBand: array,
     *               marginTop: int, marginBottom: int}
     */
    public static function pdfDocumentParts(
        string $title, string|array $header, string|array $footer,
        int $totalRows, ?int $pageCount, array $report = []
    ): array {
        $date = \Illuminate\Support\Carbon::now()->format('d/m/Y H:i');

        $rowDetail = !empty($report['rowDetail']);
        $palette   = self::pdfPalette($report);

        // ── Bandas de header/footer ──────────────────────────────────
        $headerBand  = self::normalizePdfBand($header, 'header');
        $footerBand  = self::normalizePdfBand($footer, 'footer');

        // Placeholders de contexto ({UNIT_NAME}…) + próprios do app, uma vez
        // por passada. Resolução preguiçosa sobre o html custom: banda que não
        // cita o token não toca sessão nem banco.
        $bandsHtml = ($headerBand['mode'] === 'custom' ? $headerBand['html'] : '')
            . ($footerBand['mode'] === 'custom' ? $footerBand['html'] : '');
        $report['placeholders'] = $bandsHtml === ''
            ? []
            : MadGridPdfPlaceholders::withContext(
                MadGridPdfPlaceholders::normalize((array) ($report['placeholders'] ?? [])),
                $bandsHtml
            );

        $headerInner = self::pdfBandInnerHtml($headerBand, 'header', $title, $date, $totalRows, $pageCount, $report);
        $footerInner = self::pdfBandInnerHtml($footerBand, 'footer', $title, $date, $totalRows, $pageCount, $report);

        // Margens da página liberam a área das bandas (contrato doc-band).
        $marginTop    = $headerInner === '' ? 8 : $headerBand['heightMm'] + $headerBand['gapMm'];
        $marginBottom = $footerInner === '' ? 8 : $footerBand['heightMm'] + $footerBand['gapMm'];

        // ── HTML ─────────────────────────────────────────────────────
        // O CSS da tabela vem do Pdf\GridPdfStyle — a mesma fonte que o motor
        // direto lê, para os dois caminhos desenharem igual.
        $head = '<!DOCTYPE html><html><head><meta charset="UTF-8">
        <style>
            @page { margin: ' . $marginTop . 'mm 8mm ' . $marginBottom . 'mm 8mm; }
            ' . Pdf\GridPdfStyle::bodyCss() . '

            /* Contadores de página (Dompdf) — mesmos hooks do <mad-doc-page-number>. */
            .mad-page:after  { content: counter(page); }
            .mad-pages:after { content: counter(pages); }

            .doc-footer { width: 100%; border-collapse: collapse; font-size: 8px; color: #6b7280; }
            .doc-footer td { border: none; padding: 0; }
            .doc-footer-left { text-align: left; }
            .doc-footer-right { text-align: right; }
            .doc-footer-rule { height: 0.5px; background: #e3e8ef; margin-bottom: 6px; }

            .doc-header { width: 100%; border-collapse: collapse; margin: 0; }
            .doc-header td { border: none; padding: 0; }
            .doc-header-left { text-align: left; vertical-align: bottom; }
            .doc-header-right { text-align: right; vertical-align: bottom; }
            .doc-title { font-size: 17px; font-weight: bold; color: #0f172a; letter-spacing: -0.2px; }
            .doc-meta { font-size: 8px; color: #64748b; margin-top: 3px; }
            .doc-app { font-size: 10px; font-weight: bold; color: #0f172a; }
            .doc-count { font-size: 8px; color: #64748b; margin-top: 3px; }
            .doc-header-box { padding-top: 6mm; }
            .doc-header-rule { height: 2.5px; background: ' . $palette['headerRule'] . '; margin: 8px 0 0; }
            ' . Pdf\GridPdfStyle::tableCss($palette, $rowDetail) . '

            /* Badges — equivalentes ao mad-ui.css (classes precisam ser inline aqui pois Dompdf nao carrega CSS externo) */
            .mad-badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 8px; font-weight: 600; line-height: 1.2; }
            .mad-badge-success   { background: #dcfce7; color: #166534; }
            .mad-badge-info      { background: #dbeafe; color: #1e40af; }
            .mad-badge-warning   { background: #fef3c7; color: #92400e; }
            .mad-badge-danger    { background: #fee2e2; color: #991b1b; }
            .mad-badge-error     { background: #fee2e2; color: #991b1b; }
            .mad-badge-primary   { background: #e0e7ff; color: #3730a3; }
            .mad-badge-secondary { background: #f1f5f9; color: #475569; }
            .mad-badge-default   { background: #f1f5f9; color: #475569; }
            .mad-badge-outline   { background: #fff; color: #475569; border: 1px solid #cbd5e1; }
            .mad-badge-dot { display: inline-block; width: 5px; height: 5px; border-radius: 50%; background: currentColor; margin-right: 4px; vertical-align: middle; }
        </style></head><body>';

        // Bandas fixas — repetem em toda página. Offsets NEGATIVOS empurram a
        // banda pra área de margem: Dompdf ancora position:fixed na content
        // box (dentro das margens), não no papel — top:0 sobreporia o fluxo.
        $bands = '';
        if ($headerInner !== '') {
            $bands .= '<div style="position:fixed;top:-' . $marginTop . 'mm;left:0;right:0;height:'
                . $headerBand['heightMm'] . 'mm;overflow:hidden;">' . $headerInner . '</div>';
        }
        if ($footerInner !== '') {
            $bands .= '<div style="position:fixed;bottom:-' . $marginBottom . 'mm;left:0;right:0;height:'
                . $footerBand['heightMm'] . 'mm;overflow:hidden;">' . $footerInner . '</div>';
        }

        return [
            'head'         => $head,
            'bands'        => $bands,
            'headerBand'   => $headerBand,
            'footerBand'   => $footerBand,
            'marginTop'    => $marginTop,
            'marginBottom' => $marginBottom,
        ];
    }

    /**
     * Monta o HTML completo do PDF. Público como seam de teste — permite
     * inspecionar bandas/placeholders sem renderizar Dompdf.
     *
     * @param ?int $pageCount Total de páginas para {PAGE_COUNT} (null na 1ª
     *        passada — vira span de counter, que o Dompdf renderiza como 0).
     */
    public static function buildPdfHtml(
        array $columns, array $rows, string $title,
        array $groupData = [], string|array $header = '', string|array $footer = '',
        array $grandTotals = [], ?int $pageCount = null, array $report = []
    ): string {
        $totalRows = self::pdfRowCount($rows, $groupData);
        $colCount = count($columns);

        // Banda de quebra alinhada às colunas (<mad-grid group-band="cells">).
        $groupBand = ($report['groupBand'] ?? '') === 'cells' ? 'cells' : 'inline';
        $rowDetail = !empty($report['rowDetail']);

        $parts = self::pdfDocumentParts($title, $header, $footer, $totalRows, $pageCount, $report);
        $html  = $parts['head'] . $parts['bands'];

        // ── Tabela ───────────────────────────────────────────────────
        $html .= '<table><thead><tr>';
        foreach ($columns as $col) {
            // Normaliza: coluna vinda de config crua (não do setter fluente) podia
            // trazer '140' sem unidade e o PDF/HTML perdia a largura em silêncio.
            $w     = \Mad\Support\CssUnits::length((string) ($col->width ?? ''));
            $style = $w !== '' ? 'width:' . $w . ';' : '';
            $html .= '<th style="' . $style . '">' . htmlspecialchars($col->label) . '</th>';
        }
        $html .= '</tr></thead><tbody>';

        if (!empty($groupData)) {
            $zebraCounter = 0;
            $lastRow      = null;
            foreach ($groupData as $item) {
                switch ($item['type']) {
                    case 'group':
                        $html .= self::pdfGroupRow($columns, $item, $groupBand, $colCount);
                        $zebraCounter = 0;
                        break;

                    case 'row':
                        $zebraClass = ($zebraCounter % 2 === 1) ? ' class="zebra"' : '';
                        $html .= '<tr' . $zebraClass . '>';
                        foreach ($columns as $col) {
                            $val   = $col->renderValueForPdf($item['data'][$col->field] ?? '', $item['data'], $lastRow);
                            $class = match ($col->align ?? '') {
                                'center' => ' class="center"', 'right' => ' class="right"', default => '',
                            };
                            $html .= '<td' . $class . '>' . $val . '</td>';
                        }
                        $html .= '</tr>';
                        if ($rowDetail && !empty($item['data']['__detail'])) {
                            $html .= '<tr class="detail' . ($zebraClass !== '' ? ' zebra' : '') . '">'
                                   . '<td colspan="' . $colCount . '">'
                                   . htmlspecialchars((string) $item['data']['__detail']) . '</td></tr>';
                        }
                        $lastRow = $item['data'];
                        $zebraCounter++;
                        break;

                    case 'group-total':
                        $html .= self::pdfGroupTotalRow($columns, $item, $groupBand);
                        break;
                }
            }
        } else {
            $lastRow = null;
            foreach ($rows as $r => $row) {
                $zebraClass = ($r % 2 === 1) ? ' class="zebra"' : '';
                $html .= '<tr' . $zebraClass . '>';
                foreach ($columns as $col) {
                    $val   = $col->renderValueForPdf($row[$col->field] ?? '', $row, $lastRow);
                    $class = match ($col->align ?? '') {
                        'center' => ' class="center"', 'right' => ' class="right"', default => '',
                    };
                    $html .= '<td' . $class . '>' . $val . '</td>';
                }
                $html .= '</tr>';
                if ($rowDetail && !empty($row['__detail'])) {
                    $html .= '<tr class="detail' . ($zebraClass !== '' ? ' zebra' : '') . '">'
                           . '<td colspan="' . $colCount . '">'
                           . htmlspecialchars((string) $row['__detail']) . '</td></tr>';
                }
                $lastRow = $row;
            }
        }

        // Total geral (rodapé sobre TODAS as linhas)
        if (!empty($grandTotals)) {
            $cells = self::grandTotalCells($columns, $grandTotals);
            $html .= '<tr class="grand-total">';
            foreach ($columns as $i => $col) {
                $class = match ($col->align ?? '') {
                    'center' => ' class="center"', 'right' => ' class="right"', default => '',
                };
                $html .= '<td' . $class . '>' . htmlspecialchars($cells[$i] ?? '') . '</td>';
            }
            $html .= '</tr>';
        }

        $html .= '</tbody></table></body></html>';

        return $html;
    }

    /** Texto do rótulo de sub-total (group-total-label resolvido ou o default). */
    public static function groupTotalText(array $item): string
    {
        // `totalLabel` só existe quando <mad-grid group-total-label="..."> foi
        // declarado — sem ele, o texto é exatamente o de sempre.
        return isset($item['totalLabel'])
            ? (string) $item['totalLabel']
            : __('grid.total') . ': ' . (string) ($item['label'] ?? '');
    }

    /** <tr> do cabeçalho de quebra no PDF (modo inline ou cells). */
    private static function pdfGroupRow(array $columns, array $item, string $groupBand, int $colCount): string
    {
        $level = $item['level'] ?? 0;
        $label = htmlspecialchars($item['label'] ?? '');
        if (!empty($item['count'])) {
            $label .= ' <span style="font-weight:normal;opacity:.7;">(' . $item['count'] . ')</span>';
        }

        if ($groupBand === 'cells' && !empty($item['totals'])) {
            // Banda alinhada: rótulo em colspan até a 1ª coluna totalizada e
            // cada total sob a SUA coluna.
            $gt   = self::groupTotalCells($columns, $item['totals'], '');
            $span = max(1, $gt['labelSpan']);
            $html = '<tr class="group-' . $level . '"><td colspan="' . $span . '">' . $label . '</td>';
            for ($i = $span; $i < count($columns); $i++) {
                $col   = $columns[$i];
                $class = match ($col->align ?? '') {
                    'center' => ' class="center"', 'right' => ' class="right"', default => '',
                };
                $html .= '<td' . $class . '>' . htmlspecialchars($gt['cells'][$i] ?? '') . '</td>';
            }

            return $html . '</tr>';
        }

        $totalsHtml = '';
        if (!empty($item['totals'])) {
            foreach ($columns as $col) {
                if (isset($item['totals'][$col->field])) {
                    $totalsHtml .= '&nbsp;&nbsp;&nbsp;' . $col->label . ': ' . htmlspecialchars($item['totals'][$col->field]);
                }
            }
        }

        return '<tr class="group-' . $level . '"><td colspan="' . $colCount . '">'
             . $label . $totalsHtml . '</td></tr>';
    }

    /** <tr> do sub-total de quebra no PDF (modo inline ou cells). */
    private static function pdfGroupTotalRow(array $columns, array $item, string $groupBand): string
    {
        $gt = self::groupTotalCells($columns, $item['totals'] ?? [], self::groupTotalText($item));

        if ($groupBand === 'cells' && $gt['labelSpan'] > 1) {
            $span = $gt['labelSpan'];
            $html = '<tr class="group-total"><td colspan="' . $span . '">'
                  . htmlspecialchars($gt['label']) . '</td>';
            for ($i = $span; $i < count($columns); $i++) {
                $col   = $columns[$i];
                $class = match ($col->align ?? '') {
                    'center' => ' class="center"', 'right' => ' class="right"', default => '',
                };
                $html .= '<td' . $class . '>' . htmlspecialchars($gt['cells'][$i] ?? '') . '</td>';
            }

            return $html . '</tr>';
        }

        $html = '<tr class="group-total">';
        foreach ($columns as $i => $col) {
            $class = match ($col->align ?? '') {
                'center' => ' class="center"', 'right' => ' class="right"', default => '',
            };
            $html .= '<td' . $class . '>' . htmlspecialchars($gt['cells'][$i] ?? '') . '</td>';
        }

        return $html . '</tr>';
    }

    /**
     * Normaliza o input de banda (string legada ou band config) para o shape
     * interno: ['mode' => 'custom|none|default', 'html', 'heightMm', 'gapMm',
     * 'showPagination', 'legacyText'].
     */
    private static function normalizePdfBand(string|array $band, string $which): array
    {
        $defaultH = $which === 'header' ? 18 : 12; // banda default (vitrine) é compacta
        $customH  = $which === 'header' ? 28 : 14;
        $maxH     = $which === 'header' ? 60 : 40;
        $defaultGap = 4; // respiro banda ↔ conteúdo

        if (is_string($band)) {
            if ($band === '') {
                return ['mode' => 'default', 'html' => '', 'heightMm' => $defaultH, 'gapMm' => $defaultGap, 'showPagination' => true, 'legacyText' => ''];
            }
            if ($which === 'header') {
                return ['mode' => 'custom', 'html' => $band, 'heightMm' => $customH, 'gapMm' => $defaultGap, 'showPagination' => true, 'legacyText' => ''];
            }
            // Footer string legado: substitui só o texto esquerdo do footer default.
            return ['mode' => 'default', 'html' => '', 'heightMm' => $defaultH, 'gapMm' => $defaultGap, 'showPagination' => true, 'legacyText' => $band];
        }

        $mode = $band['mode'] ?? 'default';
        if (! in_array($mode, ['custom', 'none'], true)) {
            $mode = 'default';
        }
        $h = (int) ($band['heightMm'] ?? ($mode === 'custom' ? $customH : $defaultH));
        $gap = is_numeric($band['gapMm'] ?? null) ? (int) $band['gapMm'] : $defaultGap;

        return [
            'mode'           => $mode,
            'html'           => (string) ($band['html'] ?? ''),
            'heightMm'       => max(8, min($maxH, $h)),
            'gapMm'          => max(0, min(15, $gap)),
            'showPagination' => (bool) ($band['showPagination'] ?? true),
            'legacyText'     => (string) ($band['legacyText'] ?? ''),
        ];
    }

    /** HTML interno da banda ('' = banda ausente; margem daquele lado encolhe). */
    private static function pdfBandInnerHtml(array $band, string $which, string $title, string $date, int $totalRows, ?int $pageCount, array $report = []): string
    {
        if ($band['mode'] === 'none') {
            return '';
        }

        if ($band['mode'] === 'custom') {
            $inner = self::substitutePdfPlaceholders($band['html'], $title, $date, $totalRows, $pageCount, $report);
            if (trim($inner) === '') {
                return '';
            }
            // Paginação automática (quando o html não a inclui) é desenhada
            // via overlay page_text em pdf() — não entra no HTML da banda.
            return $inner;
        }

        // mode 'default' — vitrine
        $appName = (string) config('app.name', '');

        if ($which === 'header') {
            $meta = __('grid.generated_at', ['date' => $date]) . '  ·  '
                  . __('grid.records_count', ['count' => $totalRows]);
            // Linha `none`/`transparent` sai do HTML (não só fica invisível).
            $rule = self::pdfPalette($report)['headerRule'];
            $ruleHtml = in_array(strtolower($rule), ['none', 'transparent'], true)
                ? '' : '<div class="doc-header-rule"></div>';

            // O padding do topo afasta o título da borda do papel (a banda
            // começa em y=0) e a linha termina rente ao fim da banda: o
            // respiro até a tabela é só o gapMm, igual ao do rodapé.
            return '<div class="doc-header-box"><table class="doc-header"><tr>'
                . '<td class="doc-header-left">'
                .   '<div class="doc-title">' . htmlspecialchars($title) . '</div>'
                .   '<div class="doc-meta">' . htmlspecialchars($meta) . '</div>'
                . '</td>'
                . '<td class="doc-header-right">'
                .   ($appName !== '' ? '<div class="doc-app">' . htmlspecialchars($appName) . '</div>' : '')
                .   '<div class="doc-count">' . htmlspecialchars(__('grid.records_count', ['count' => $totalRows])) . '</div>'
                . '</td>'
                . '</tr></table>'
                . $ruleHtml . '</div>';
        }

        // Footer default: texto à esquerda (legado quando presente); a
        // paginação à direita é overlay page_text (ver pdf()).
        if ($band['legacyText'] !== '') {
            $text = strip_tags(str_replace(['{DATE}', '{TITLE}'], [$date, $title], $band['legacyText']));
        } else {
            $text = $title
                . ($appName !== '' ? '  ·  ' . $appName : '')
                . '  —  ' . __('grid.generated_at', ['date' => $date]);
        }

        return '<div class="doc-footer-rule"></div>'
            . '<table class="doc-footer"><tr>'
            . '<td class="doc-footer-left">' . htmlspecialchars($text) . '</td>'
            . '</tr></table>';
    }

    /**
     * Texto de paginação pro overlay page_text (null = não desenhar).
     * Usa grid.page_of quando o app tiver a chave; fallback numérico.
     * {PAGE_NUM}/{PAGE_COUNT} são interpolados nativamente pelo canvas.
     */
    private static function pdfPaginationText(array $footerBand): ?string
    {
        if ($footerBand['mode'] === 'none') {
            return null;
        }
        if ($footerBand['mode'] === 'custom') {
            // Html custom com paginação própria (counters/two-pass) ou toggle off.
            if (! $footerBand['showPagination']
                || strpos($footerBand['html'], '{PAGE_NUM}') !== false
                || strpos($footerBand['html'], '{PAGE_COUNT}') !== false) {
                return null;
            }
        }

        $pageOf = __('grid.page_of');
        if ($pageOf === 'grid.page_of' || strpos($pageOf, '{PAGE_NUM}') === false) {
            $pageOf = '{PAGE_NUM} / {PAGE_COUNT}';
        }

        return $pageOf;
    }

    /** true quando algum html custom embute {PAGE_COUNT} (exige 2º render). */
    private static function pdfBandsNeedPageCount(array $headerBand, array $footerBand): bool
    {
        foreach ([$headerBand, $footerBand] as $band) {
            if ($band['mode'] === 'custom' && strpos($band['html'], '{PAGE_COUNT}') !== false) {
                return true;
            }
        }
        return false;
    }

    /** Substitui os placeholders suportados nas bandas custom. */
    private static function substitutePdfPlaceholders(string $html, string $title, string $date, int $totalRows, ?int $pageCount, array $report = []): string
    {
        // {LOGO}/{LOGO_SMALL} DENTRO de src="..." (layout livre do editor):
        // recebe só o data-URI — o strtr abaixo injetaria uma tag <img>
        // inteira dentro do atributo e mutilaria o markup. Sem logo
        // configurado, a tag <img> inteira sai (regex quote-aware — valores
        // de atributo carregam ';' e ':' à vontade).
        foreach ([['{LOGO}', 'large'], ['{LOGO_SMALL}', 'small']] as [$tok, $type]) {
            if (strpos($html, 'src="' . $tok . '"') === false) {
                continue;
            }
            $uri = MadGridPdfBranding::image($type);
            if ($uri === '') {
                $html = (string) preg_replace(
                    '/<img\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*?src="' . preg_quote($tok, '/') . '"(?:[^>"\']|"[^"]*"|\'[^\']*\')*?>/',
                    '',
                    $html
                );
            } else {
                $html = str_replace('src="' . $tok . '"', 'src="' . $uri . '"', $html);
            }
        }

        $map = [
            '{TITLE}'          => htmlspecialchars($title),
            '{DATE}'           => $date,
            // Relatório: período e filtros escolhidos, e subtítulo declarado.
            // Ausentes = string vazia (a banda não mostra token cru).
            '{SUBTITLE}'       => htmlspecialchars((string) ($report['subtitle'] ?? '')),
            '{PERIOD}'         => htmlspecialchars((string) ($report['period']   ?? '')),
            '{FILTERS}'        => htmlspecialchars((string) ($report['filters']  ?? '')),
            // Nome novo (auto-explicativo no editor) + alias legado.
            '{TOTAL_REGISTER}' => (string) $totalRows,
            '{TOTAL}'          => (string) $totalRows,
            '{APP_NAME}'   => htmlspecialchars((string) config('app.name', '')),
            '{LOGO}'       => self::pdfLogoImg('large'),
            '{LOGO_SMALL}' => self::pdfLogoImg('small'),
            // counter(page) existe no Dompdf; o total não — 2ª passada injeta o literal.
            '{PAGE_NUM}'   => self::PDF_PAGE_NUM_HTML,
            '{PAGE_COUNT}' => $pageCount === null ? '<span class="mad-pages"></span>' : (string) $pageCount,
        ];

        // Contexto ({UNIT_NAME}/{USER_NAME}/{TENANT_NAME}) + próprios do app —
        // mapa já normalizado por MadGridPdfPlaceholders (buildPdfHtml): chaves
        // '{KEY}', estruturais fora. Mesma chave = o app sobrescreve o built-in
        // de texto; o valor é sempre escapado. strtr é passe único: valor que
        // contenha '{TITLE}' não é re-substituído.
        foreach ((array) ($report['placeholders'] ?? []) as $token => $value) {
            $map[$token] = htmlspecialchars((string) $value);
        }

        return strtr($html, $map);
    }

    /** <img> do logo do projeto (branding global) ou '' quando não configurado. */
    private static function pdfLogoImg(string $type): string
    {
        $uri = MadGridPdfBranding::image($type);
        if ($uri === '') {
            return '';
        }
        $maxH = $type === 'large' ? '14mm' : '9mm';
        return '<img src="' . $uri . '" style="max-height:' . $maxH . ';" alt="">';
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * Monta as células da linha de TOTAL GERAL.
     *
     * Cada valor vai NA SUA coluna (mapeado por field). O label "TOTAL GERAL"
     * vai na primeira coluna SEM total — assim nunca engole um valor nem é
     * engolido por ele, mesmo quando a coluna totalizada é a primeira. Se TODAS
     * as colunas têm total, o label é prefixado no valor da col 0.
     *
     * @param GridColumn[] $columns
     * @param array<string,string> $grandTotals  field => valor já renderizado
     * @return string[] célula por coluna (índice 0-based)
     */
    public static function grandTotalCells(array $columns, array $grandTotals): array
    {
        return self::totalCells($columns, $grandTotals, __('grid.grand_total'))['cells'];
    }

    /**
     * Decomposição da linha de SUB-TOTAL de um grupo — espelho exato do
     * grandTotalCells, e a razão de ele existir: o PDF escrevia o rótulo na
     * td 0 e começava os valores na td 1 (a coluna 0 sumia quando totalizada),
     * e CSV/XLSX sobrescreviam o valor da coluna 0 com o rótulo.
     *
     * @param GridColumn[]         $columns
     * @param array<string,string> $totals  field => valor já renderizado
     * @param string               $label   rótulo do sub-total (já resolvido)
     * @return array{labelSpan:int, label:string, cells:string[]}
     *         `labelSpan` = quantas colunas à esquerda ficam livres até a
     *         primeira coluna totalizada (0 = nenhuma; o rótulo já está
     *         prefixado em `cells[0]` ou numa coluna sem total adiante).
     */
    public static function groupTotalCells(array $columns, array $totals, string $label): array
    {
        return self::totalCells($columns, $totals, $label);
    }

    /**
     * Cada valor NA SUA coluna (mapeado por field). O label vai na primeira
     * coluna SEM total — assim nunca engole um valor nem é engolido por ele,
     * mesmo quando a coluna totalizada é a primeira. Se TODAS as colunas têm
     * total, o label é prefixado no valor da col 0.
     *
     * @param GridColumn[]         $columns
     * @param array<string,string> $totals
     * @return array{labelSpan:int, label:string, cells:string[]}
     */
    private static function totalCells(array $columns, array $totals, string $label): array
    {
        $cells = array_fill(0, count($columns), '');
        foreach ($columns as $i => $col) {
            if (isset($totals[$col->field])) {
                $cells[$i] = (string) $totals[$col->field];
            }
        }

        $labelIdx      = null;   // 1ª coluna SEM total (onde o rótulo cabe sozinho)
        $firstTotalIdx = null;   // 1ª coluna COM total (fim do colspan do rótulo)
        foreach ($columns as $i => $col) {
            if (!isset($totals[$col->field])) {
                if ($labelIdx === null) $labelIdx = $i;
            } elseif ($firstTotalIdx === null) {
                $firstTotalIdx = $i;
            }
        }
        $labelSpan = $firstTotalIdx ?? count($columns);

        if ($labelIdx !== null) {
            $cells[$labelIdx] = $label;
        } elseif (!empty($columns)) {
            // Todas as colunas totalizam → não há onde pôr o label sozinho.
            $cells[0] = $label . ': ' . $cells[0];
        }

        return ['labelSpan' => $labelSpan, 'label' => $label, 'cells' => $cells];
    }

    /**
     * Sanitiza nome de sheet XLSX (max 31 chars, sem `/ \ ? * [ ]`, nao pode
     * ser vazio nem soh espacos).
     */
    public static function sanitizeSheetName(string $name): string
    {
        $name = preg_replace('#[\\\\/\\?\\*\\[\\]:]#', '-', $name) ?? '';
        $name = trim($name);
        if ($name === '') {
            $name = 'Export';
        }
        return mb_substr($name, 0, 31);
    }
}