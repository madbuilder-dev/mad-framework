<?php

namespace Mad\Doc;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Thin DOMPDF wrapper used by MadBuilder-generated documents.
 *
 * Two entry points:
 *   - fromHtml($html, $opts): turn a pre-rendered HTML string into PDF bytes
 *   - fromView($view, $data, $opts): render a Blade view (typically the
 *     generated <mad-doc-page>...</mad-doc-page> template) and pipe the
 *     result into fromHtml()
 *
 * `$opts` supports: paper, orientation, isRemoteEnabled, chroot, font_dir,
 * default_font. Sensible defaults cover the common case (A4 portrait,
 * DejaVu Sans, remote disabled, chroot = storage/app).
 */
class MadDocPdf
{
    /**
     * Span do "total de páginas" emitido pelo <mad-doc-page-number> ({total})
     * e pelas bandas de layout livre. O CSS `.mad-pages:after
     * {content:counter(pages)}` NÃO funciona no Dompdf 3.x (counter(pages)
     * inexiste — sai 0/vazio), então fromHtml faz two-pass quando o span
     * está presente.
     */
    private const PAGES_SPAN = '<span class="mad-pages"></span>';

    public static function fromHtml(string $html, array $opts = []): string
    {
        // Two-pass: 1ª render conta as páginas, o span vira literal e o
        // documento re-renderiza (mesma estratégia do exporter de grid).
        if (str_contains($html, self::PAGES_SPAN)) {
            $probe = self::renderDompdf($html, $opts);
            $total = max(1, (int) $probe->getCanvas()->get_page_count());
            $html = str_replace(self::PAGES_SPAN, (string) $total, $html);
        }

        return self::renderDompdf($html, $opts)->output();
    }

    private static function renderDompdf(string $html, array $opts): Dompdf
    {
        $dompdf = new Dompdf(self::options($opts));
        $dompdf->setPaper(
            $opts['paper'] ?? 'A4',
            $opts['orientation'] ?? 'portrait',
        );
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();
        return $dompdf;
    }

    /**
     * Render a Blade view through MadBlade and hand it to DOMPDF.
     * Used by MadBuilder-generated controllers:
     *   return response(MadDocPdf::fromView('docs.pedido-venda', [...]))
     *     ->header('Content-Type', 'application/pdf');
     *
     * IMPORTANTE: usa Mad\View\MadBlade::render(), NÃO o helper view() do
     * Laravel. O preprocessing das tags <mad-doc-*> (alias mad-→x-, compilers
     * de doc-data-table/repeater) só roda no factory do MadBlade — o view()
     * default emite as tags literais e o documento sai quebrado.
     */
    public static function fromView(string $view, array $data = [], array $opts = []): string
    {
        if (! class_exists(\Mad\View\MadBlade::class)) {
            throw new \RuntimeException('Mad\\View\\MadBlade não disponível — use fromHtml() directly.');
        }
        $html = \Mad\View\MadBlade::render($view, $data);
        return self::fromHtml($html, $opts);
    }

    private static function options(array $opts): Options
    {
        $o = new Options();
        $o->set('defaultFont', $opts['default_font'] ?? 'DejaVu Sans');
        $o->set('isHtml5ParserEnabled', true);
        $o->set('isRemoteEnabled', (bool) ($opts['isRemoteEnabled'] ?? false));
        $o->set('isPhpEnabled', false);

        // Chroot restricts which disk paths DOMPDF can pull images from.
        // Default to the app's storage/app so documents can reference
        // uploaded files (logos, signatures) without opening the whole FS.
        $chroot = $opts['chroot'] ?? null;
        if ($chroot === null && function_exists('storage_path')) {
            $chroot = storage_path('app');
        }
        if ($chroot) {
            $o->set('chroot', $chroot);
        }

        if (isset($opts['font_dir'])) {
            $o->set('fontDir', $opts['font_dir']);
        }
        return $o;
    }
}
