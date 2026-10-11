<?php
namespace Mad\Grid;

use Mad\Service\MadScratchStorage;

/**
 * CSV da grid escrito linha a linha — mesmo conteúdo do MadGridExporter::csv()
 * (BOM UTF-8, `;`, texto que a grid mostra), mas sem exigir todas as linhas na
 * memória: a exportação em fluxo do MadDataGrid manda lote a lote.
 *
 * O separador é `;` por padrão (o Excel em português abre direto) e a tela
 * troca com `<mad-grid csv-delimiter=",">` ou `$csvDelimiter` — ver
 * delimiter() para as formas aceitas.
 *
 *   $w = GridCsvWriter::open($columns, ',');
 *   $w->dataRow($row) | $w->groupItems($items) …
 *   $w->grandTotalRow($grandTotals);
 *   $key = $w->finish();              // chave output/<uniqid>.csv no scratch
 */
final class GridCsvWriter
{
    /** Separador de sempre: o Excel em português abre o arquivo direto. */
    public const DEFAULT_DELIMITER = ';';

    /** Nomes aceitos além do próprio caractere (`csv-delimiter="tab"`). */
    private const DELIMITER_ALIASES = [
        'semicolon' => ';',
        'comma'     => ',',
        'pipe'      => '|',
        'tab'       => "\t",
        '\\t'       => "\t",
    ];

    /** @var resource */
    private $fp;
    /** @var GridColumn[] */
    private array $columns;
    private string $key;
    private string $delimiter;

    /** @param GridColumn[] $columns */
    public static function open(array $columns, string $delimiter = self::DEFAULT_DELIMITER): self
    {
        return new self($columns, self::delimiter($delimiter));
    }

    /**
     * Separador do CSV a partir do que a tela declarou. Aceita o caractere
     * (`;` `,` `|` `#`…, inclusive o TAB de verdade) ou um nome: `semicolon`,
     * `comma`, `pipe`, `tab` (ou `\t` escrito no Blade). Vazio = `;`.
     *
     * Recusa — e cai no `;`, com aviso no log — o que embaralharia o arquivo:
     * mais de um caractere (o fputcsv só separa por 1 byte), aspas (são o
     * delimitador de texto), `\\` (o escape), quebra de linha, letra e número
     * (um `1` como separador partiria todo valor que tem 1).
     */
    public static function delimiter(?string $raw): string
    {
        $raw = (string) $raw;
        if ($raw === '') {
            return self::DEFAULT_DELIMITER;
        }

        // TAB e espaço só valem como vieram: o trim os apagaria.
        $value = strlen($raw) === 1 ? $raw : trim($raw);
        $value = self::DELIMITER_ALIASES[strtolower($value)] ?? $value;

        if (strlen($value) === 1 && !ctype_alnum($value) && !in_array($value, ['"', '\\', "\n", "\r", "\0"], true)) {
            return $value;
        }

        \Illuminate\Support\Facades\Log::warning(
            'Mad\\Grid: csv-delimiter "' . addcslashes($raw, "\0..\37") . '" não é um separador de CSV válido '
            . '(use um caractere como ; , | ou tab). Exportando com ";".'
        );

        return self::DEFAULT_DELIMITER;
    }

    /** @param GridColumn[] $columns */
    private function __construct(array $columns, string $delimiter)
    {
        $this->columns   = array_values($columns);
        $this->key       = 'output/' . uniqid() . '.csv';
        $this->delimiter = $delimiter;

        // php://temp: até 2 MB em memória, o resto em arquivo temporário.
        $fp = fopen('php://temp', 'w+');
        if ($fp === false) {
            throw new \RuntimeException(__('ui.export_write_failed', ['dir' => MadScratchStorage::DISK]));
        }
        $this->fp = $fp;

        fwrite($this->fp, "\xEF\xBB\xBF"); // BOM UTF-8
        fputcsv($this->fp, array_map(fn ($c) => $c->label, $this->columns), $this->delimiter);
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
        fputcsv($this->fp, $line, $this->delimiter);
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
                    fputcsv($this->fp, $line, $this->delimiter);
                    break;

                case 'row':
                    $this->dataRow($item['data']);
                    break;

                case 'group-total':
                    fputcsv($this->fp, MadGridExporter::groupTotalCells(
                        $this->columns,
                        $item['totals'] ?? [],
                        MadGridExporter::groupTotalText($item)
                    )['cells'], $this->delimiter);
                    break;
            }
        }
    }

    /** Total geral (rodapé sobre TODAS as linhas). */
    public function grandTotalRow(array $grandTotals, ?array $raw = null): void
    {
        if (!empty($grandTotals)) {
            fputcsv($this->fp, MadGridExporter::grandTotalCells($this->columns, $grandTotals), $this->delimiter);
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
