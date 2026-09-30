<?php
namespace Mad\Grid\Pdf;

/**
 * Conteúdo que o motor direto não desenha IDÊNTICO ao Dompdf (HTML na célula,
 * badge que quebraria linha, linha maior que a página…). Não é erro para o
 * usuário: o MadGridExporter pega e gera o PDF pelo caminho HTML/Dompdf.
 */
final class GridPdfUnsupported extends \RuntimeException
{
}
