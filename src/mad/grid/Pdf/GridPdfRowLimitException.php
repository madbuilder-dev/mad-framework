<?php
namespace Mad\Grid\Pdf;

/**
 * Linhas demais para o caminho de PDF escolhido. O MadDataGrid transforma em
 * aviso (toast) sugerindo Excel/CSV — gerar mesmo assim estouraria a memória
 * ou o tempo da requisição, e o usuário veria só um erro 500.
 */
final class GridPdfRowLimitException extends \RuntimeException
{
    public function __construct(public readonly int $max, public readonly int $rows = 0)
    {
        parent::__construct("PDF com {$rows} linhas acima do limite de {$max}");
    }

    /**
     * Texto do aviso. App atualizado só pelo canal do framework ainda não tem
     * a chave nova no lang/ dele: cai no texto em pt-BR em vez de mostrar
     * `grid.export_pdf_too_many` cru.
     */
    public static function userMessage(int $max): string
    {
        $locale = function_exists('app') ? (string) app()->getLocale() : '';
        $n = number_format($max, 0, '', str_starts_with($locale, 'en') ? ',' : '.');
        $msg = __('grid.export_pdf_too_many', ['max' => $n]);

        return $msg === 'grid.export_pdf_too_many'
            ? "Esta listagem tem mais de {$n} linhas para gerar em PDF. Filtre os registros ou exporte para Excel ou CSV."
            : $msg;
    }
}
