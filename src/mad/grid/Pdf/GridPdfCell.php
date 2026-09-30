<?php
namespace Mad\Grid\Pdf;

/**
 * Uma célula (td/th) do PDF: conteúdo inline, estilo computado e, depois do
 * layout, as linhas quebradas. `span` = colspan.
 */
final class GridPdfCell
{
    /** @var array<int, array> itens inline: ['t'=>'text'|'badge', 's'=>…, 'font'=>…, …] */
    public array $items = [];
    public string $align = 'left';
    public int $col = 0;
    public int $span = 1;
    /** @var array estilo de GridPdfStyle::cell() + 'font' */
    public array $st = [];
    /** Largura CSS declarada no <th> (pt); null = auto. */
    public ?float $width = null;

    /** @var array<int, array{h: float, w: float, frames: array}> linhas depois do layout */
    public array $lines = [];
    public float $contentH = 0.0;
    public ?float $layoutWidth = null;
}
