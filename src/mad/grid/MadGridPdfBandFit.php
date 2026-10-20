<?php
namespace Mad\Grid;

use Dompdf\Dompdf;
use Dompdf\FontMetrics;
use Mad\Support\PdfFontMetrics;

/**
 * MadGridPdfBandFit — leva o HTML de uma banda do PDF desenhada no canvas do
 * MadBuilder para a largura útil de OUTRA orientação do papel.
 *
 * O canvas emite um container relative com filhos `position:absolute` em mm
 * (`renderBandDesignHtml` no Studio): logo em `left:248.5mm` só existe na
 * folha paisagem (281 mm úteis). Em retrato (194 mm) a banda tem
 * `overflow:hidden` e tudo depois de 194 mm some do PDF.
 *
 * Regra do "Adaptar para esta página" do Studio (`rescaleBandDesign`):
 * k = largura nova / largura de origem; `left` vira left·k (limitado à
 * largura); `<img>` com width/height em mm vira w·k/h·k (mínimos 4/3 mm);
 * `top` e tipografia ficam. Como a fonte não escala, um texto que terminava
 * rente à borda ("Página X de Y" à direita) passaria dela: o texto é puxado
 * para a esquerda até caber, com 1 mm de folga, pela largura estimada
 * (Helvetica, a fonte do corpo do PDF; tokens de número/data medidos com o
 * maior valor usual).
 *
 * Só mexe em elemento `position:absolute` com `left` em mm. HTML legado
 * (tabela, fluxo normal) passa intacto, byte a byte.
 */
final class MadGridPdfBandFit
{
    /** Largura útil do A4 com margens de 8 mm — `pdfContentWidthMm` do Studio. */
    public const CONTENT_WIDTH_MM = ['landscape' => 281.0, 'portrait' => 194.0];

    /** Mínimos da imagem reescalada (mesmos do Studio). */
    private const IMAGE_MIN_W_MM = 4.0;
    private const IMAGE_MIN_H_MM = 3.0;

    /** Folga entre o fim do texto puxado e a borda da banda (o corte do overflow). */
    private const TEXT_EDGE_GAP_MM = 1.0;

    /**
     * Valor medido no lugar de cada token na estimativa da largura do texto.
     * Data no formato do export (d/m/Y H:i); números com 3 dígitos. Os demais
     * tokens ({TITLE}…) entram como estão — o valor só existe na exportação.
     */
    private const TOKEN_SAMPLES = [
        '{PAGE_NUM}'       => '000',
        '{PAGE_COUNT}'     => '000',
        '{DATE}'           => '00/00/0000 00:00',
        '{TOTAL_REGISTER}' => '00000',
    ];

    /** Tag de abertura com atributos (aspas respeitadas) + texto até a próxima tag. */
    private const TAG_RE = '/<([a-zA-Z][a-zA-Z0-9]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>([^<]*)/';

    private static ?FontMetrics $metrics = null;
    private static float $dpi = 96.0;

    /** Largura útil da folha na orientação ('portrait'|'landscape'; outro valor = paisagem). */
    public static function contentWidthMm(string $orientation): float
    {
        return self::CONTENT_WIDTH_MM[$orientation] ?? self::CONTENT_WIDTH_MM['landscape'];
    }

    /**
     * HTML desenhado na orientação `$from` ajustado à largura útil de `$to`.
     * Mesma orientação → o mesmo HTML, intocado.
     */
    public static function toOrientation(string $html, string $from, string $to): string
    {
        return self::rescale($html, self::contentWidthMm($from), self::contentWidthMm($to));
    }

    /** Reposiciona os elementos absolutos em mm de `$fromMm` para `$toMm` de largura útil. */
    public static function rescale(string $html, float $fromMm, float $toMm): string
    {
        if (!($fromMm > 0) || !($toMm > 0) || $fromMm === $toMm || stripos($html, 'absolute') === false) {
            return $html;
        }
        $k = $toMm / $fromMm;

        return (string) (preg_replace_callback(self::TAG_RE, function (array $m) use ($k, $toMm): string {
            [$all, $tag, $attrs, $text] = $m;

            $styleRe = '/(?<![\w-])(style\s*=\s*)("([^"]*)"|\'([^\']*)\')/i';
            if (!preg_match($styleRe, $attrs, $s)) {
                return $all;
            }
            $style = ($s[3] ?? '') !== '' ? $s[3] : ($s[4] ?? '');
            if (!preg_match('/(?<![\w-])position\s*:\s*absolute/i', $style)
                || !preg_match('/(?<![\w-])left\s*:\s*(\d+(?:\.\d+)?|\.\d+)mm/i', $style, $l)) {
                return $all;
            }

            $x = max(0.0, min($toMm, self::r1((float) $l[1] * $k)));
            $isImage = strcasecmp($tag, 'img') === 0;
            if (!$isImage && trim($text) !== '') {
                $room = floor(($toMm - self::TEXT_EDGE_GAP_MM - self::textWidthMm($text, $style)) * 10) / 10;
                $x = max(0.0, min($x, $room));
            }

            $style = self::setMm($style, 'left', $x);
            if ($isImage) {
                $style = (string) preg_replace_callback(
                    '/((?<![\w-])(width|height)\s*:\s*)(\d+(?:\.\d+)?|\.\d+)mm/i',
                    function (array $d) use ($k): string {
                        $min = strtolower($d[2]) === 'width' ? self::IMAGE_MIN_W_MM : self::IMAGE_MIN_H_MM;
                        return $d[1] . self::mm(max($min, self::r1((float) $d[3] * $k))) . 'mm';
                    },
                    $style
                );
            }

            $q = $s[2][0];
            $attrs = str_replace($s[0], $s[1] . $q . $style . $q, $attrs);

            return '<' . $tag . $attrs . '>' . $text;
        }, $html) ?? $html);
    }

    /** Troca o valor (em mm) da propriedade `$prop` do style, mantendo o resto byte a byte. */
    private static function setMm(string $style, string $prop, float $value): string
    {
        return (string) preg_replace(
            '/((?<![\w-])' . $prop . '\s*:\s*)(\d+(?:\.\d+)?|\.\d+)mm/i',
            '${1}' . self::mm($value) . 'mm',
            $style,
            1
        );
    }

    /** Largura estimada, em mm, do texto do elemento com a fonte/tamanho do style. */
    private static function textWidthMm(string $text, string $style): float
    {
        $plain = strtr(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'), self::TOKEN_SAMPLES);
        $plain = trim((string) preg_replace('/\s+/u', ' ', $plain));
        if ($plain === '') {
            return 0.0;
        }

        // Tamanho em px (o canvas emite px) ou pt; sem tamanho = o do corpo (9px).
        $sizePt = 9 * 72 / self::$dpi;
        if (preg_match('/(?<![\w-])font-size\s*:\s*(\d+(?:\.\d+)?)(px|pt)/i', $style, $f)) {
            $sizePt = strtolower($f[2]) === 'pt' ? (float) $f[1] : (float) $f[1] * 72 / self::$dpi;
        }
        $bold = (bool) preg_match('/(?<![\w-])font-weight\s*:\s*(bold|bolder|[6-9]00)/i', $style);

        try {
            $fm   = self::metrics();
            $font = $fm->getFont('Helvetica', $bold ? 'bold' : 'normal');
            $pt   = $font !== null ? (float) $fm->getTextWidth($plain, $font, $sizePt) : null;
        } catch (\Throwable) {
            $pt = null;
        }
        // Sem métrica (fonte indisponível): média larga da Helvetica.
        $pt ??= mb_strlen($plain) * $sizePt * ($bold ? 0.62 : 0.56);

        return $pt * 25.4 / 72;
    }

    private static function metrics(): FontMetrics
    {
        if (self::$metrics === null) {
            // Mesmas opções e FontMetrics do export (Helvetica core).
            $dompdf = PdfFontMetrics::install(new Dompdf(MadGridExporter::PDF_DOMPDF_OPTIONS));
            self::$dpi = (float) ($dompdf->getOptions()->getDpi() ?: 96);
            self::$metrics = $dompdf->getFontMetrics();
        }

        return self::$metrics;
    }

    /** Arredonda a 0,1 mm como o Math.round do Studio (valores ≥ 0). */
    private static function r1(float $v): float
    {
        return floor($v * 10 + 0.5) / 10;
    }

    /** 171.6 → "171.6"; 87.0 → "87" (mesmo texto do mm1() do Studio). */
    private static function mm(float $v): string
    {
        return rtrim(rtrim(sprintf('%.1f', $v), '0'), '.');
    }
}
