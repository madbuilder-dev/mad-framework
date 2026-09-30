<?php
namespace Mad\Grid\Pdf;

use Dompdf\Adapter\CPDF;
use Dompdf\FontMetrics;

/**
 * Métricas de texto do motor direto — as MESMAS do Dompdf, lidas da mesma
 * fonte (a tabela de larguras do AFM carregada pelo Cpdf), com as mesmas
 * contas do Cpdf::getTextWidth/getFontHeight. É o que faz a quebra de linha e
 * a largura das colunas saírem idênticas às do HTML: uma diferença de 0,001 pt
 * numa palavra muda onde a linha quebra e, daí, a altura da linha e a página.
 *
 * Só fontes core (Windows-1252, como a Helvetica do PDF da listagem). Fonte
 * Unicode embutida (TTF) tem outro caminho no Cpdf — o motor recusa
 * (GridPdfUnsupported) e o PDF cai no Dompdf.
 */
final class GridPdfMetrics
{
    /** @var array<string, array<int, int|float>> larguras por código, por fonte */
    private array $widths = [];
    /** @var array<string, float> Ascender - Descender (unidades de 1/1000) por fonte */
    private array $heights = [];
    /** @var array<string, array<string, float>> cache de larguras de strings curtas */
    private array $cache = [];
    private int $cached = 0;
    /** @var array<string, bool> suporte de glifo por fonte|caractere */
    private array $supports = [];
    /** @var array<string, string> família+peso → arquivo de fonte */
    private array $fonts = [];

    public function __construct(private CPDF $canvas, private FontMetrics $fm, private bool $subsetting) {}

    /** Arquivo de fonte que o Dompdf escolheria para as famílias + peso (Style::_get_font_family). */
    public function font(array $families, string $weight): string
    {
        $key = implode(',', $families) . '|' . $weight;
        if (isset($this->fonts[$key])) {
            return $this->fonts[$key];
        }
        $subtype = $this->fm->getType($weight . ' normal');
        foreach ($families as $family) {
            $f = $this->fm->getFont($family, $subtype);
            if ($f !== null) {
                return $this->fonts[$key] = $f;
            }
        }
        $f = $this->fm->getFont(null, $subtype) ?? throw new GridPdfUnsupported('fonte não encontrada');

        return $this->fonts[$key] = $f;
    }

    /** Fontes de cada família, na ordem — base do mapeamento de glifos (apply_font_mapping). */
    public function familyFonts(array $families, string $weight): array
    {
        $key = implode(',', $families) . '|' . $weight;
        if (isset($this->familyFonts[$key])) {
            return $this->familyFonts[$key];
        }
        $subtype = $this->fm->getType($weight . ' normal');
        $out = [];
        foreach ($families as $family) {
            $f = $this->fm->getFont($family, $subtype);
            if ($f !== null) $out[] = $f;
        }

        return $this->familyFonts[$key] = $out;
    }

    /** @var array<string, string[]> */
    private array $familyFonts = [];

    private function load(string $font): void
    {
        $cpdf = $this->canvas->get_cpdf();
        $cpdf->selectFont($font, '', true, $this->subsetting);
        $f = $cpdf->fonts[$font] ?? throw new GridPdfUnsupported("fonte {$font}");
        if (!empty($f['isUnicode'])) {
            throw new GridPdfUnsupported("fonte Unicode {$font}");
        }
        $h = isset($f['Ascender'], $f['Descender'])
            ? $f['Ascender'] - $f['Descender']
            : $f['FontBBox'][3] - $f['FontBBox'][1];
        if (isset($f['FontHeightOffset'])) {
            $h += (int) $f['FontHeightOffset'];
        }
        $this->widths[$font] = $f['C'];
        $this->heights[$font] = $h;
    }

    /** Cpdf::getFontHeight (altura da fonte, sem o font-height-ratio do Dompdf). */
    public function cpdfHeight(string $font, float $size): float
    {
        if (!isset($this->heights[$font])) $this->load($font);

        // Mesma ordem de operações do Cpdf: o resultado vai com 3 casas para o PDF.
        return $size * $this->heights[$font] / 1000;
    }

    /** FontMetrics::getFontBaseline — altura × ratio ÷ ratio (as contas do Dompdf, ida e volta). */
    public function baseline(string $font, float $size): float
    {
        $ratio = $this->ratio();

        return ($this->cpdfHeight($font, $size) * $ratio) / $ratio;
    }

    private ?float $ratio = null;

    private function ratio(): float
    {
        return $this->ratio ??= (float) $this->canvas->get_dompdf()->getOptions()->getFontHeightRatio();
    }

    /** Altura da linha de texto com line-height:normal (Text::get_margin_height). */
    public function lineHeight(string $font, float $size): float
    {
        // line_height (normal = 1.2 × size) / size × FontMetrics::getFontHeight (cpdf × ratio)
        return ((1.2 * $size) / $size) * ($this->cpdfHeight($font, $size) * $this->ratio());
    }

    /** Cpdf::getTextWidth para fonte core. */
    public function width(string $text, string $font, float $size, float $charSpacing = 0.0): float
    {
        if ($text === '') return 0.0;
        $key = $font . '|' . $size . '|' . $charSpacing;
        if (isset($this->cache[$key][$text])) {
            return $this->cache[$key][$text];
        }
        if (!isset($this->widths[$font])) $this->load($font);
        $C = $this->widths[$font];

        $t = preg_replace('/[\x00-\x1F\x7F]/u', '', $text) ?? '';
        $t = mb_convert_encoding($t, 'Windows-1252', 'UTF-8');
        $w = 0;
        foreach (count_chars($t, 1) as $code => $n) {
            $w += ($C[$code] ?? ($C[0xFFFD] ?? $C[0x20])) * $n;
        }
        if ($charSpacing != 0) {
            $w += $charSpacing * (1000 / ($size > 0 ? $size : 1)) * strlen($t);
        }
        $r = $w * $size / 1000;

        // Mesmo critério do FontMetrics::getTextWidth (string curta), com teto:
        // 100 mil linhas têm ~1 milhão de strings distintas.
        if (!isset($text[50])) {
            if (++$this->cached > 20000) {
                $this->cache = [];
                $this->cached = 0;
            }
            $this->cache[$key][$text] = $r;
        }

        return $r;
    }

    /**
     * Quebra o texto em trechos pela fonte que desenha cada caractere —
     * FontMetrics::mapTextToFonts + Text::apply_font_mapping. O Dompdf faz
     * disso frames de texto SEPARADOS (posições e quebras de linha próprias);
     * o motor precisa dos mesmos trechos para desenhar igual.
     *
     * @return array<int, array{0: string, 1: string}> [texto, fonte]
     */
    public function runs(string $text, array $fonts, string $base): array
    {
        // Caminho rápido: ASCII imprimível existe em toda fonte core.
        if ($text === '' || preg_match('/^[\x20-\x7E]*$/', $text)) {
            return [[$text, $base]];
        }
        $chars = mb_str_split($text, 1, 'UTF-8');
        $runs = [];
        $curFont = false;
        $buf = '';
        foreach ($chars as $ch) {
            if (preg_match('/[\x00-\x1F\x7F]/u', $ch)) {
                $buf .= $ch; // não-imprimível não muda o trecho
                continue;
            }
            $mapped = null;
            foreach ($fonts as $f) {
                $k = $f . '|' . $ch;
                $ok = $this->supports[$k] ??= $this->canvas->font_supports_char($f, $ch);
                if ($ok) { $mapped = $f; break; }
            }
            if ($curFont !== false && $mapped !== $curFont) {
                $runs[] = [$buf, $curFont ?? $base];
                $buf = '';
            }
            $curFont = $mapped;
            $buf .= $ch;
        }
        if ($buf !== '' || $runs === []) {
            $runs[] = [$buf, ($curFont === false ? null : $curFont) ?? $base];
        }
        foreach ($runs as [, $f]) {
            if ($f !== $base) {
                // Glifo que só OUTRA fonte da lista tem: outra fonte, outras métricas.
                $this->load($f);
            }
        }

        return $runs;
    }
}
