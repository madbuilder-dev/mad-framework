<?php
namespace Mad\Grid\Pdf;

/** Uma linha (tr) do PDF: cabeçalho, dado, detalhe, quebra, sub-total ou total geral. */
final class GridPdfRow
{
    /** @var GridPdfCell[] */
    public array $cells = [];

    public function __construct(public string $kind)
    {
    }
}
