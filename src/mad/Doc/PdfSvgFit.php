<?php

namespace Mad\Doc;

/**
 * SVG embutido em `<img src="data:image/svg+xml…">` no PDF (fw#509).
 *
 * O dompdf (php-svg-lib) desenha o SVG no tamanho do `viewBox` quando ele
 * difere do `width`/`height`: o desenho transbordava a caixa da imagem e o texto
 * seguinte subia por cima. Aqui o SVG é reescrito para o tamanho final da caixa
 * (`width`/`height` da `<img>`, senão os do próprio SVG): `viewBox` igual ao
 * tamanho e o conteúdo escalado num `<g transform>`, com a mesma regra do
 * navegador (`preserveAspectRatio` meet centrado, ou `none`).
 */
final class PdfSvgFit
{
    public static function fitHtml(string $html): string
    {
        if (!str_contains($html, 'data:image/svg+xml')) {
            return $html;
        }

        return (string) preg_replace_callback('/<img\b[^>]*>/i', static function (array $m): string {
            $tag = $m[0];
            if (!preg_match('/\bsrc=(["\'])(data:image\/svg\+xml(;[^,"\']*)?,([^"\']*))\1/i', $tag, $src)) {
                return $tag;
            }
            $isB64 = stripos($src[3] ?? '', 'base64') !== false;
            $svg = $isB64 ? base64_decode($src[4], true) : rawurldecode($src[4]);
            if (!is_string($svg) || $svg === '') {
                return $tag;
            }
            $fit = self::fitSvg($svg, self::attrPx($tag, 'width'), self::attrPx($tag, 'height'));
            if ($fit === null) {
                return $tag;
            }
            $uri = 'data:image/svg+xml;base64,' . base64_encode($fit);

            return str_replace($src[2], $uri, $tag);
        }, $html);
    }

    /**
     * SVG reescrito para a caixa $boxW × $boxH (null = o tamanho do próprio SVG),
     * ou null quando nada precisa mudar (sem viewBox, tamanho desconhecido, já cabe).
     */
    public static function fitSvg(string $svg, ?float $boxW = null, ?float $boxH = null): ?string
    {
        if (!preg_match('/<svg\b[^>]*>/i', $svg, $open, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $tag = $open[0][0];
        if (!preg_match('/\bviewBox=(["\'])\s*([-\d.eE]+)[\s,]+([-\d.eE]+)[\s,]+([-\d.eE]+)[\s,]+([-\d.eE]+)\s*\1/', $tag, $vb)) {
            return null;
        }
        [$vx, $vy, $vw, $vh] = [(float) $vb[2], (float) $vb[3], (float) $vb[4], (float) $vb[5]];
        if ($vw <= 0 || $vh <= 0) {
            return null;
        }

        $ownW = self::attrPx($tag, 'width');
        $ownH = self::attrPx($tag, 'height');
        $w = $boxW ?? $ownW;
        $h = $boxH ?? $ownH;
        if ($w === null && $h === null) {
            return null;
        }
        // Só um lado conhecido: o outro segue a proporção do viewBox.
        $w ??= $h * $vw / $vh;
        $h ??= $w * $vh / $vw;
        if ($w <= 0 || $h <= 0) {
            return null;
        }
        // Já cabe: caixa = viewBox na origem, e o width/height do SVG (se houver)
        // também — width ≠ viewBox é justamente o que o dompdf desenha errado.
        $same = static fn (?float $a, float $b): bool => $a === null || abs($a - $b) < 0.01;
        if ($same($w, $vw) && $same($h, $vh) && $same($ownW, $vw) && $same($ownH, $vh) && $vx == 0.0 && $vy == 0.0) {
            return null;
        }

        $sx = $w / $vw;
        $sy = $h / $vh;
        $none = (bool) preg_match('/\bpreserveAspectRatio=(["\'])\s*none\b/i', $tag);
        if ($none) {
            $transform = sprintf('scale(%s %s) translate(%s %s)', self::n($sx), self::n($sy), self::n(-$vx), self::n(-$vy));
        } else {
            $s  = min($sx, $sy);
            $tx = ($w - $vw * $s) / 2 - $vx * $s;
            $ty = ($h - $vh * $s) / 2 - $vy * $s;
            $transform = sprintf('translate(%s %s) scale(%s)', self::n($tx), self::n($ty), self::n($s));
        }

        $newTag = preg_replace('/\s(?:width|height|viewBox|preserveAspectRatio)=(["\']).*?\1/i', '', $tag);
        $newTag = preg_replace('/^<svg\b/i', sprintf('<svg width="%s" height="%s" viewBox="0 0 %s %s"', self::n($w), self::n($h), self::n($w), self::n($h)), (string) $newTag);

        $start = $open[0][1] + strlen($tag);
        $close = strripos($svg, '</svg>');
        if ($close === false || $close < $start || str_ends_with(rtrim($tag), '/>')) {
            return null;
        }

        return substr($svg, 0, $open[0][1]) . $newTag
            . '<g transform="' . $transform . '">' . substr($svg, $start, $close - $start) . '</g>'
            . substr($svg, $close);
    }

    /** Valor em px de um atributo (`width="300"`, `300px`, ou no `style`); null em %/em/auto. */
    private static function attrPx(string $tag, string $name): ?float
    {
        if (preg_match('/\s' . $name . '=(["\']?)\s*([\d.]+)\s*(px)?\s*\1(?=[\s>\/])/i', $tag, $m)) {
            return (float) $m[2] > 0 ? (float) $m[2] : null;
        }
        if (preg_match('/\bstyle=(["\'])(.*?)\1/i', $tag, $st)
            && preg_match('/(?:^|;)\s*' . $name . '\s*:\s*([\d.]+)\s*px\b/i', $st[2], $m)) {
            return (float) $m[1] > 0 ? (float) $m[1] : null;
        }

        return null;
    }

    private static function n(float $v): string
    {
        $s = rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.');

        return $s === '-0' || $s === '' ? '0' : $s;
    }
}
