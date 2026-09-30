<?php
namespace Mad\Grid;

use Mad\Service\MadScratchStorage;

/**
 * CSV da grid escrito linha a linha — mesmo conteúdo do MadGridExporter::csv()
 * (BOM UTF-8, `;`, texto que a grid mostra), mas sem exigir todas as linhas na
 * memória: a exportação em fluxo do MadDataGrid manda lote a lote.
 *
 *   $w = GridCsvWriter::open($columns);
 *   $w->dataRow($row) | $w->groupItems($items) …
 *   $w->grandTotalRow($grandTotals);
 *   $key = $w->finish();              // chave output/<uniqid>.csv no scratch
 */
final class GridCsvWriter
{
    /** @var resource */
    private $fp;
    /** @var GridColumn[] */
    private array $columns;
    private string $key;

    /** @param GridColumn[] $columns */
    public static function open(array $columns): self
    {
        return new self($columns);
    }

    /** @param GridColumn[] $columns */
    private function __construct(array $columns)
    {
        $this->columns = array_values($columns);
        $this->key     = 'output/' . uniqid() . '.csv';

        // php://temp: até 2 MB em memória, o resto em arquivo temporário.
        $fp = fopen('php://temp', 'w+');
        if ($fp === false) {
            throw new \RuntimeException(__('ui.export_write_failed', ['dir' => MadScratchStorage::DISK]));
        }
        $this->fp = $fp;

        fwrite($this->fp, "\xEF\xBB\xBF"); // BOM UTF-8
        fputcsv($this->fp, array_map(fn ($c) => $c->label, $this->columns), ';');
    }

    /**
     * Linha de dados: o texto que a grid mostra em cada coluna — máscara do
     * formatador (CPF, CNPJ, telefone…), transform do projeto e rótulo do
     * badge. CSV não tem tipo; o texto da tela é o contrato.
     */
    public function dataRow(array $row): void
    {
        $line = [];
        foreach ($this->columns as $col) {
            $line[] = GridExportCell::displayText($col, $row[$col->field] ?? '', $row);
        }
        fputcsv($this->fp, $line, ';');
    }

    /** Itens achatados do `_computeGroupData()` (group / row / group-total), na ordem. */
    public function groupItems(array $items): void
    {
        foreach ($items as $item) {
            switch ($item['type']) {
                case 'group':
                    $line  = array_fill(0, count($this->columns), '');
                    $label = str_repeat('  ', (int) ($item['level'] ?? 0)) . $item['label'];
                    if (!empty($item['count'])) $label .= ' (' . $item['count'] . ')';
                    // Totais inline
                    if (!empty($item['totals'])) {
                        foreach ($this->columns as $col) {
                            if (isset($item['totals'][$col->field])) {
                                $label .= '  |  ' . $col->label . ': ' . $item['totals'][$col->field];
                            }
                        }
                    }
                    $line[0] = $label;
                    fputcsv($this->fp, $line, ';');
                    break;

                case 'row':
                    $this->dataRow($item['data']);
                    break;

                case 'group-total':
                    fputcsv($this->fp, MadGridExporter::groupTotalCells(
                        $this->columns,
                        $item['totals'] ?? [],
                        MadGridExporter::groupTotalText($item)
                    )['cells'], ';');
                    break;
            }
        }
    }

    /** Total geral (rodapé sobre TODAS as linhas). */
    public function grandTotalRow(array $grandTotals, ?array $raw = null): void
    {
        if (!empty($grandTotals)) {
            fputcsv($this->fp, MadGridExporter::grandTotalCells($this->columns, $grandTotals), ';');
        }
    }

    /** Persiste no scratch (MadScratchStorage) e devolve a CHAVE. */
    public function finish(): string
    {
        rewind($this->fp);
        $ok = MadScratchStorage::disk()->writeStream($this->key, $this->fp);
        $this->closeStream();
        if ($ok === false) {
            throw new \RuntimeException(__('ui.export_write_failed', ['dir' => MadScratchStorage::DISK]));
        }

        return $this->key;
    }

    public function abort(): void
    {
        $this->closeStream();
    }

    private function closeStream(): void
    {
        if (is_resource($this->fp)) {
            fclose($this->fp);
        }
    }
}
