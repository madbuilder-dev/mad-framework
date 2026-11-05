<?php

namespace Mad\Help;

/**
 * Marcadores numerados nos prints do manual (fórum #227, FW-A).
 *
 * O Studio grava em `manual.json` → `pages[].prints[].markers` os números que o
 * autor pôs sobre a imagem (`{n, x_pct, y_pct, label}`), e a tabela "Nº | O que
 * é" do texto cita esses números. Depois do {@see \Mad\Site\MadDocMarkdown},
 * cada `<img>` cujo arquivo está nos prints da página vira uma figura com
 * posição relativa e um `<span class="mad-help-marker">` por marcador, em
 * porcentagem — acompanha a largura da imagem.
 *
 *   - Parágrafo só com a imagem → `<figure class="mad-help-figure">`.
 *   - Imagem no meio do texto → `<span class="mad-help-figure">` (figure dentro
 *     de <p> não é HTML válido; o navegador quebraria o parágrafo).
 *
 * Valores já validados por {@see ManualRepository::normalizeMarkers()} (n
 * inteiro, x/y entre 0 e 100); o rótulo sai escapado.
 */
final class ManualFigures
{
    /**
     * @param array  $page      página do índice (ManualRepository::find)
     * @param string $printsUrl endereço da rota dos prints (…/ajuda/prints)
     */
    public static function decorate(string $html, array $page, string $printsUrl): string
    {
        $byName = [];
        foreach ((array) ($page['prints'] ?? []) as $print) {
            if (is_array($print) && isset($print['name'])) {
                $byName[(string) $print['name']] = ManualRepository::normalizeMarkers((array) ($print['markers'] ?? []));
            }
        }
        if ($byName === [] || $html === '') {
            return $html;
        }
        $prefix = rtrim($printsUrl, '/') . '/';

        return preg_replace_callback(
            '#<p>\s*(<img\b[^>]*>)\s*</p>|(<img\b[^>]*>)#i',
            static function (array $m) use ($byName, $prefix): string {
                $block = ($m[1] ?? '') !== '';
                $img   = $block ? $m[1] : $m[2];
                if (!preg_match('#\bsrc="([^"]*)"#i', $img, $src)) {
                    return $m[0];
                }
                $url = html_entity_decode($src[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (!str_starts_with($url, $prefix)) {
                    return $m[0];
                }
                $name = rawurldecode(substr($url, strlen($prefix)));
                if (!array_key_exists($name, $byName)) {
                    return $m[0];
                }

                $markers = '';
                foreach ($byName[$name] as $mk) {
                    $label = $mk['label'];
                    $aria  = $label !== '' ? $mk['n'] . ': ' . $label : (string) $mk['n'];
                    $markers .= sprintf(
                        '<span class="mad-help-marker" style="left:%s%%;top:%s%%" aria-label="%s"%s>%d</span>',
                        self::pct($mk['x_pct']),
                        self::pct($mk['y_pct']),
                        htmlspecialchars($aria, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                        $label !== '' ? ' title="' . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"' : '',
                        $mk['n']
                    );
                }

                $tag   = $block ? 'figure' : 'span';
                $attrs = ' class="mad-help-figure" data-mad-help-print="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '"';

                return '<' . $tag . $attrs . '>' . $img . $markers . '</' . $tag . '>';
            },
            $html
        ) ?? $html;
    }

    /** Porcentagem com ponto decimal (independe do locale), sem zeros sobrando. */
    private static function pct(float $v): string
    {
        $s = rtrim(rtrim(number_format(max(0.0, min(100.0, $v)), 3, '.', ''), '0'), '.');

        return $s === '' ? '0' : $s;
    }
}
