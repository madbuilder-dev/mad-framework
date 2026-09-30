<?php
namespace Mad\Grid;

use Mad\Service\MadScratchStorage;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Border;
use OpenSpout\Common\Entity\Style\BorderName;
use OpenSpout\Common\Entity\Style\BorderPart;
use OpenSpout\Common\Entity\Style\BorderStyle;
use OpenSpout\Common\Entity\Style\BorderWidth;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\CellVerticalAlignment;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

/**
 * XLSX da grid escrito EM FLUXO (OpenSpout): cada linha vai para o arquivo e
 * sai da memória. Até a 5.94 a planilha inteira vivia no PhpSpreadsheet
 * (~0,6 KB por célula, estilo aplicado célula a célula): 100 mil linhas
 * levavam ~4 min e 1,2 GB.
 *
 * Tipo, valor e formato de cada célula vêm do GridExportCell::forXlsx() — a
 * leitura da coluna é a mesma da tela. O arquivo sai igual ao de antes:
 * cabeçalho escuro, zebra, faixas de quebra mescladas, sub-total e total
 * geral numéricos quando reproduzem o texto da tela.
 *
 * Uso incremental (exportação em fluxo do MadDataGrid) ou pelo
 * MadGridExporter::xlsx() com as linhas já carregadas:
 *
 *   $w = GridXlsxWriter::open($columns, $title);
 *   $w->dataRow($row) | $w->groupItems($items) …
 *   $w->grandTotalRow($grandTotals, $raw);
 *   $key = $w->finish();              // chave output/<uniqid>.xlsx no scratch
 */
final class GridXlsxWriter
{
    private const FONT = 'Calibri';
    private const SIZE = 11;

    private Writer $writer;
    private Options $options;
    /** @var GridColumn[] */
    private array $columns;
    private int $colCount;
    /** Última linha escrita da planilha (1-based). */
    private int $rowNum = 0;
    /** @var array<string, Style> estilos reusados: o OpenSpout registra por instância. */
    private array $styles = [];
    /** @var array<int, string> */
    private array $align = [];
    /** Linhas de cada grupo aberto, por nível — o número cru do sub-total sai delas. */
    private array $openGroups = [];
    private bool $closed = false;

    /** O app tem a biblioteca? Um app atualizado só pelo canal do framework pode não ter. */
    public static function available(): bool
    {
        return class_exists(Writer::class);
    }

    /** @param GridColumn[] $columns */
    public static function open(array $columns, string $title = 'export'): self
    {
        if (!self::available()) {
            throw new \RuntimeException(self::unavailableMessage());
        }

        $disk = MadScratchStorage::disk();
        $disk->makeDirectory('output');
        $disk->makeDirectory('export-tmp');
        $key = 'output/' . uniqid() . '.xlsx';

        return new self($key, $disk->path($key), $disk->path('export-tmp'), $columns, $title);
    }

    public static function unavailableMessage(): string
    {
        $msg = __('grid.export_xlsx_unavailable');

        return $msg === 'grid.export_xlsx_unavailable'
            ? 'A exportação para Excel precisa de uma biblioteca que este sistema ainda não tem. Republique o projeto para atualizar.'
            : $msg;
    }

    /** @param GridColumn[] $columns */
    private function __construct(
        private string $key,
        string $file,
        string $tempFolder,
        array $columns,
        string $title,
    ) {
        $this->columns  = array_values($columns);
        $this->colCount = count($this->columns);

        $this->options = new Options(
            new Style(fontSize: self::SIZE, fontName: self::FONT),
            tempFolder: $tempFolder,
        );
        $this->writer = new Writer($this->options);
        $this->writer->openToFile($file);

        $sheet = $this->writer->getCurrentSheet();
        $sheet->setName(self::sheetName($title));

        foreach ($this->columns as $i => $col) {
            // Largura antes da 1ª linha: o <cols> sai antes do <sheetData>.
            $w = $col->width ? (int) str_replace('px', '', $col->width) / 7 : 18;
            $sheet->setColumnWidth((float) max($w, 10), $i + 1);
            $this->align[$i] = match ($col->align ?? '') {
                'center' => CellAlignment::CENTER,
                'right'  => CellAlignment::RIGHT,
                default  => CellAlignment::LEFT,
            };
        }

        // Cabeçalho — texto explícito (rótulo "2026" não vira número).
        $cells = [];
        foreach ($this->columns as $i => $col) {
            $cells[$i] = new StringCell(GridExportCell::plainText((string) $col->label), $this->style('hdr', $i, ''));
        }
        $this->add($cells);
    }

    /** Linha de dados; zebra nas linhas pares da planilha. */
    public function dataRow(array $row): void
    {
        $kind  = (($this->rowNum + 1) % 2) === 0 ? 'z' : 'd';
        $cells = [];
        foreach ($this->columns as $i => $col) {
            $spec      = GridExportCell::forXlsx($col, $row[$col->field] ?? '', $row);
            $cells[$i] = $this->cell($spec, $this->style($kind, $i, $spec['format']));
        }
        $this->add($cells);
    }

    /**
     * Itens achatados do `_computeGroupData()` (group / row / group-total), na
     * ordem. Pode ser chamado várias vezes — a exportação em fluxo manda um
     * grupo de topo por vez.
     */
    public function groupItems(array $items): void
    {
        foreach ($items as $item) {
            switch ($item['type']) {
                case 'group':
                    $level = (int) ($item['level'] ?? 0);
                    foreach (array_keys($this->openGroups) as $lvl) {
                        if ($lvl >= $level) unset($this->openGroups[$lvl]);
                    }
                    $this->openGroups[$level] = [];
                    $this->groupRow($item, $level);
                    break;

                case 'row':
                    foreach (array_keys($this->openGroups) as $lvl) {
                        $this->openGroups[$lvl][] = $item['data'];
                    }
                    $this->dataRow($item['data']);
                    break;

                case 'group-total':
                    $level = (int) ($item['level'] ?? 0);
                    $gt    = MadGridExporter::groupTotalCells($this->columns, $item['totals'] ?? [], MadGridExporter::groupTotalText($item));
                    $rows  = $this->openGroups[$level] ?? null;
                    $this->totalRow('gt', $gt['cells'], $rows === null ? null : GridRenderHelpers::computeRawTotals($rows, $this->columns));
                    unset($this->openGroups[$level]);
                    // Rótulo sozinho à esquerda ocupa as colunas livres até a
                    // primeira totalizada (mesma leitura da tela).
                    if ($gt['labelSpan'] > 1) {
                        $this->options->mergeCells(0, $this->rowNum, $gt['labelSpan'] - 1, $this->rowNum);
                    }
                    break;
            }
        }
    }

    /**
     * Total geral (rodapé sobre TODAS as linhas).
     *
     * @param array<string,string>    $grandTotals field => texto da tela
     * @param array<int,int|float>|null $raw      índice da coluna => número cru
     *        (computeRawTotals ou o acumulador da exportação em fluxo)
     */
    public function grandTotalRow(array $grandTotals, ?array $raw): void
    {
        if (empty($grandTotals)) {
            return;
        }
        $this->totalRow('tt', MadGridExporter::grandTotalCells($this->columns, $grandTotals), $raw);
    }

    /** Fecha o arquivo e devolve a chave no scratch. */
    public function finish(): string
    {
        $this->close();

        return $this->key;
    }

    /** Falhou no meio: fecha e apaga o arquivo parcial. */
    public function abort(): void
    {
        try {
            $this->close();
        } catch (\Throwable) {
            // o arquivo parcial vai embora de qualquer jeito
        }
        MadScratchStorage::delete($this->key);
    }

    // ── Internos ─────────────────────────────────────────────────────────

    private function close(): void
    {
        if (!$this->closed) {
            $this->closed = true;
            $this->writer->close();
        }
    }

    private function groupRow(array $item, int $level): void
    {
        $label = $item['label'] ?? '';
        if (!empty($item['count'])) $label .= '  (' . $item['count'] . ')';
        if (!empty($item['totals'])) {
            foreach ($this->columns as $col) {
                if (isset($item['totals'][$col->field])) {
                    $label .= '    ' . $col->label . ': ' . $item['totals'][$col->field];
                }
            }
        }
        $label = GridExportCell::plainText((string) $label);
        // O OpenSpout não tem recuo de célula: o nível da quebra vira espaço rígido.
        if ($level > 0) {
            $label = str_repeat("\u{00A0}", $level * 4) . $label;
        }

        $style = $this->style($level === 0 ? 'g0' : 'g1', 0, '');
        $cells = [0 => $label === '' ? new EmptyCell(null, $style) : new StringCell($label, $style)];
        for ($i = 1; $i < $this->colCount; $i++) {
            $cells[$i] = new EmptyCell(null, $style);
        }
        $this->add($cells);
        if ($this->colCount > 1) {
            $this->options->mergeCells(0, $this->rowNum, $this->colCount - 1, $this->rowNum);
        }
    }

    /**
     * Linha de total. O valor de cada coluna totalizada vira NÚMERO com o
     * formato da coluna — somável no Excel — quando o número recalculado
     * reproduz exatamente o texto da tela. Qualquer divergência (`total-mask`,
     * rótulo prefixado quando todas as colunas totalizam, `_computeTotals`
     * sobrescrito pelo app, transform) mantém o texto: número só quando é o
     * MESMO número.
     *
     * @param string[]               $cellsText células já decompostas (totalCells)
     * @param array<int,int|float>|null $raw    null = linhas desconhecidas
     */
    private function totalRow(string $kind, array $cellsText, ?array $raw): void
    {
        $cells = [];
        foreach ($this->columns as $i => $col) {
            $cellVal = (string) ($cellsText[$i] ?? '');
            if ($cellVal === '') {
                $cells[$i] = new EmptyCell(null, $this->style($kind, $i, ''));
                continue;
            }

            $spec = null;
            if ($raw !== null && array_key_exists($i, $raw) && empty($col->totalMask)
                && GridRenderHelpers::renderTotal($col, $raw[$i]) === $cellVal) {
                $candidate = GridExportCell::forXlsx($col, $raw[$i]);
                if ($candidate['type'] === GridExportCell::NUMBER) {
                    $spec = $candidate;
                }
            }
            $spec ??= ['type' => GridExportCell::TEXT, 'value' => GridExportCell::plainText($cellVal), 'format' => ''];
            $cells[$i] = $this->cell($spec, $this->style($kind, $i, $spec['format']));
        }
        $this->add($cells);
    }

    /** Texto vai SEMPRE como StringCell: "12345678000190" não vira número nem "=1+1" fórmula. */
    private function cell(array $spec, Style $style): Cell
    {
        return match ($spec['type']) {
            GridExportCell::NUMBER, GridExportCell::DATE => new NumericCell($spec['value'], $style),
            GridExportCell::TEXT => new StringCell((string) $spec['value'], $style),
            default => new EmptyCell(null, $style),
        };
    }

    private function add(array $cells): void
    {
        $this->writer->addRow(new Row($cells));
        $this->rowNum++;
    }

    /**
     * Estilo por (tipo de linha, coluna, formato), criado uma vez e reusado.
     * hdr = cabeçalho · d = dado · z = dado zebrado · g0/g1 = quebra ·
     * gt = sub-total · tt = total geral. Cores de fundo/borda em ARGB: com
     * 6 dígitos os leitores enxergam alfa 00.
     */
    private function style(string $kind, int $i, string $format): Style
    {
        $key = $kind . '|' . $i . '|' . $format;
        if (isset($this->styles[$key])) {
            return $this->styles[$key];
        }

        $align = $this->align[$i] ?? null;
        $fmt   = $format !== '' ? $format : null;

        return $this->styles[$key] = match ($kind) {
            'hdr' => new Style(fontBold: true, fontSize: 10, fontColor: 'FFFFFF', fontName: self::FONT,
                cellAlignment: $align, cellVerticalAlignment: CellVerticalAlignment::CENTER,
                border: new Border(new BorderPart(BorderName::BOTTOM, 'FF000000', BorderWidth::THIN, BorderStyle::SOLID)),
                backgroundColor: 'FF1F2937'),
            'd'   => new Style(fontSize: self::SIZE, fontName: self::FONT, cellAlignment: $align, format: $fmt),
            'z'   => new Style(fontSize: self::SIZE, fontName: self::FONT, cellAlignment: $align,
                backgroundColor: 'FFF9FAFB', format: $fmt),
            'g0'  => new Style(fontBold: true, fontSize: 10, fontColor: 'FFFFFF', fontName: self::FONT,
                backgroundColor: 'FF374151'),
            'g1'  => new Style(fontBold: true, fontSize: 9, fontColor: 'FFFFFF', fontName: self::FONT,
                backgroundColor: 'FF6B7280'),
            'gt'  => new Style(fontBold: true, fontSize: 9, fontName: self::FONT, cellAlignment: $align,
                border: new Border(new BorderPart(BorderName::TOP, 'FFD1D5DB', BorderWidth::THIN, BorderStyle::SOLID)),
                backgroundColor: 'FFE5E7EB', format: $fmt),
            'tt'  => new Style(fontBold: true, fontSize: 10, fontColor: 'FFFFFF', fontName: self::FONT, cellAlignment: $align,
                border: new Border(new BorderPart(BorderName::TOP, 'FF111827', BorderWidth::THIN, BorderStyle::DOUBLE)),
                backgroundColor: 'FF1F2937', format: $fmt),
        };
    }

    /** sanitizeSheetName + o que o OpenSpout recusa (apóstrofo nas pontas, "History"). */
    private static function sheetName(string $title): string
    {
        $name = trim(MadGridExporter::sanitizeSheetName($title), "'");

        return ($name === '' || strcasecmp($name, 'History') === 0) ? 'Export' : $name;
    }
}
