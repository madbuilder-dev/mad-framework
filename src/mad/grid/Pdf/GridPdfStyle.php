<?php
namespace Mad\Grid\Pdf;

use Dompdf\Css\Color;
use Mad\Grid\MadGridExporter;

/**
 * Fonte ÚNICA do estilo da tabela do PDF da listagem.
 *
 * O PDF tem dois desenhistas: o motor direto (GridPdfRenderer, o caminho
 * rápido) e o Dompdf lendo o HTML do MadGridExporter::buildPdfHtml() (o
 * fallback). Os dois só produzem o mesmo PDF se lerem os MESMOS números —
 * tamanho de fonte, padding, cor, borda. Por isso as declarações moram aqui
 * como CSS de verdade: o buildPdfHtml() imprime estas strings no <style> e o
 * motor as interpreta com o mesmo significado que o Dompdf dá a elas. Mudou
 * uma cor ou um padding aqui, os dois caminhos mudam juntos; declaração que o
 * motor não sabe interpretar derruba o teste de paridade em vez de divergir
 * em silêncio.
 *
 * Valor com `{chave}` vem da paleta (MadGridExporter::PDF_PALETTE, sobrescrita
 * pelo "palette" do app/config/pdf-export.json via MadGridPdfBranding).
 */
final class GridPdfStyle
{
    /** px → pt. O Dompdf trabalha a 96 dpi. */
    public const PX = 0.75;
    public const MM = 72 / 25.4;

    public const BODY = [
        'font-family' => 'Helvetica, Arial, sans-serif',
        'font-size'   => '9px',
        'color'       => '#334155',
        'margin'      => '0',
    ];

    /** Regras da tabela, na ordem do <style> (a ordem também é a da cascata). */
    public const TABLE_RULES = [
        'table'           => ['width' => '100%', 'border-collapse' => 'collapse'],
        'th'              => ['background' => '#0f172a', 'color' => '#fff', 'font-weight' => 'bold', 'padding' => '7px 9px', 'text-align' => 'left', 'font-size' => '7.5px', 'text-transform' => 'uppercase', 'letter-spacing' => '0.5px'],
        'td'              => ['padding' => '6px 9px', 'border-bottom' => '1px solid #eef2f7'],
        'tr.zebra td'     => ['background' => '#f8fafc'],
        '.right'          => ['text-align' => 'right'],
        '.center'         => ['text-align' => 'center'],
        '.group-0 td'     => ['background' => '{group0Bg}', 'color' => '{group0Fg}', 'font-weight' => 'bold', 'font-size' => '9.5px', 'padding' => '7px 9px', 'border-left' => '3px solid {group0Rule}', 'border-bottom' => '1px solid {group0Border}'],
        '.group-1 td'     => ['background' => '{group1Bg}', 'color' => '{group1Fg}', 'font-weight' => 'bold', 'font-size' => '8.5px', 'padding' => '6px 9px 6px 22px', 'border-left' => '3px solid {group1Rule}'],
        '.group-total td' => ['background' => '{groupTotalBg}', 'color' => '{groupTotalFg}', 'font-weight' => 'bold', 'font-size' => '8px', 'border-top' => '1px solid {groupTotalBorder}'],
        '.grand-total td' => ['background' => '{grandTotalBg}', 'color' => '{grandTotalFg}', 'font-weight' => 'bold', 'font-size' => '9px', 'padding' => '7px 9px', 'border-top' => '2px solid {grandTotalBorder}'],
    ];

    /** row-detail: 2ª linha descritiva, colada na linha de dado (só entra no CSS quando a grid usa). */
    public const DETAIL_RULE = [
        'tr.detail td' => ['font-size' => '8px', 'color' => '#64748b', 'padding-top' => '0', 'border-bottom' => '1px solid #eef2f7'],
    ];

    /**
     * Cascata de cada tipo de linha: regras aplicadas em ordem sobre o body
     * (herança de fonte/cor). `zebra` acrescenta o fundo listrado.
     */
    private const KINDS = [
        'th'     => ['th'],
        'data'   => ['td'],
        'detail' => ['td', 'tr.detail td'],
        'group0' => ['td', '.group-0 td'],
        'group1' => ['td', '.group-1 td'],
        'gtotal' => ['td', '.group-total td'],
        'grand'  => ['td', '.grand-total td'],
    ];

    // ── CSS (buildPdfHtml) ───────────────────────────────────────────────

    public static function bodyCss(): string
    {
        return self::rule('body', self::BODY, []);
    }

    /** Regras da tabela para o <style> — linhas separadas com a indentação do buildPdfHtml. */
    public static function tableCss(array $palette, bool $rowDetail): string
    {
        $lines = [];
        foreach (self::TABLE_RULES as $selector => $decls) {
            $lines[] = self::rule($selector, $decls, $palette);
        }
        $css = implode("\n            ", $lines);
        if ($rowDetail) {
            $css .= "\n            /* row-detail: 2ª linha descritiva, colada na linha de dado. */\n            "
                  . self::rule('tr.detail td', self::DETAIL_RULE['tr.detail td'], $palette);
        }

        return $css;
    }

    private static function rule(string $selector, array $decls, array $palette): string
    {
        $parts = [];
        foreach ($decls as $prop => $value) {
            $parts[] = $prop . ': ' . self::fill($value, $palette);
        }

        return $selector . ' { ' . implode('; ', $parts) . '; }';
    }

    private static function fill(string $value, array $palette): string
    {
        if (!str_contains($value, '{')) {
            return $value;
        }
        $map = [];
        foreach ($palette as $k => $v) {
            $map['{' . $k . '}'] = (string) $v;
        }

        return strtr($value, $map);
    }

    // ── Motor ────────────────────────────────────────────────────────────

    /**
     * Estilo computado de uma célula: o que o Dompdf calcula para o <td>/<th>
     * daquele tipo de linha, em pt.
     *
     * @return array{families: string[], weight: string, size: float, color: array|null,
     *               pad: float[], bTop: array, bRight: array, bBottom: array, bLeft: array,
     *               bg: array|null, cs: float, upper: bool}
     *         Borda = [largura pt, estilo, cor]; pad = [t, r, b, l].
     */
    public static function cell(string $kind, bool $zebra, array $palette): array
    {
        $rules = self::KINDS[$kind] ?? throw new \InvalidArgumentException("tipo de linha desconhecido: {$kind}");
        $all = self::TABLE_RULES + self::DETAIL_RULE;

        // Herdado do body; o resto nasce no valor inicial do CSS.
        $s = [
            'families' => self::families(self::BODY['font-family']),
            'weight'   => '400',
            'size'     => self::length(self::BODY['font-size']),
            'color'    => self::color(self::BODY['color']),
            'pad'      => [0.0, 0.0, 0.0, 0.0],
            'bTop'     => self::NONE, 'bRight' => self::NONE, 'bBottom' => self::NONE, 'bLeft' => self::NONE,
            'bg'       => null,
            'cs'       => 0.0,
            'upper'    => false,
        ];

        $chain = [];
        foreach ($rules as $sel) {
            $chain[] = $all[$sel];
            // `tr.zebra td` tem a mesma especificidade de `tr.detail td` e vem
            // antes no <style>: aplica logo depois do `td`.
            if ($sel === 'td' && $zebra) {
                $chain[] = $all['tr.zebra td'];
            }
        }
        foreach ($chain as $decls) {
            foreach ($decls as $prop => $value) {
                self::apply($s, $prop, self::fill($value, $palette));
            }
        }

        return $s;
    }

    /**
     * Estilo do badge (span inline-block do GridColumn::renderValueForPdf)
     * sobre o estilo herdado da célula: fonte, cor e letter-spacing herdam;
     * caixa (padding, fundo, borda, raio) nasce zerada.
     * null = declaração que o motor não reproduz (o PDF cai no Dompdf).
     */
    public static function inline(string $style, array $parent): ?array
    {
        $s = [
            'families' => $parent['families'], 'weight' => $parent['weight'], 'size' => $parent['size'],
            'color' => $parent['color'], 'cs' => $parent['cs'], 'upper' => $parent['upper'],
            'pad' => [0.0, 0.0, 0.0, 0.0], 'bg' => null,
            'bTop' => self::NONE, 'bRight' => self::NONE, 'bBottom' => self::NONE, 'bLeft' => self::NONE,
            'display' => 'inline', 'radius' => 0.0,
        ];
        foreach (explode(';', $style) as $decl) {
            if (trim($decl) === '') continue;
            $kv = explode(':', $decl, 2);
            if (count($kv) !== 2) return null;
            [$prop, $value] = [strtolower(trim($kv[0])), trim($kv[1])];
            if ($prop === 'display') { $s['display'] = $value; continue; }
            if ($prop === 'border-radius') {
                $r = self::length($value);
                if ($r === null) return null;
                $s['radius'] = $r;
                continue;
            }
            try {
                self::apply($s, $prop, $value);
            } catch (\LogicException) {
                return null;
            }
        }

        return $s;
    }

    public const NONE = [0.0, 'none', null];

    private static function apply(array &$s, string $prop, string $value): void
    {
        switch ($prop) {
            case 'background':
            case 'background-color':
                $c = self::color($value);
                $s['bg'] = is_array($c) ? $c : null;
                return;
            case 'color':
                $s['color'] = self::color($value);
                return;
            case 'font-weight':
                $s['weight'] = match (strtolower($value)) {
                    'bold', 'bolder' => '700',
                    'normal', 'lighter' => '400',
                    default => ctype_digit($value) ? $value : throw new \LogicException("font-weight {$value}"),
                };
                return;
            case 'font-size':
                $s['size'] = self::length($value) ?? throw new \LogicException("font-size {$value}");
                return;
            case 'padding':
                $v = array_map(fn($x) => self::length($x) ?? throw new \LogicException("padding {$value}"), preg_split('/\s+/', trim($value)));
                $s['pad'] = match (count($v)) {
                    1 => [$v[0], $v[0], $v[0], $v[0]],
                    2 => [$v[0], $v[1], $v[0], $v[1]],
                    3 => [$v[0], $v[1], $v[2], $v[1]],
                    default => [$v[0], $v[1], $v[2], $v[3]],
                };
                return;
            case 'padding-top':    $s['pad'][0] = self::length($value) ?? throw new \LogicException($prop); return;
            case 'padding-right':  $s['pad'][1] = self::length($value) ?? throw new \LogicException($prop); return;
            case 'padding-bottom': $s['pad'][2] = self::length($value) ?? throw new \LogicException($prop); return;
            case 'padding-left':   $s['pad'][3] = self::length($value) ?? throw new \LogicException($prop); return;
            case 'border-top':    $s['bTop'] = self::border($value); return;
            case 'border-right':  $s['bRight'] = self::border($value); return;
            case 'border-bottom': $s['bBottom'] = self::border($value); return;
            case 'border-left':   $s['bLeft'] = self::border($value); return;
            case 'letter-spacing':
                $s['cs'] = self::length($value) ?? throw new \LogicException("letter-spacing {$value}");
                return;
            case 'text-transform':
                $s['upper'] = strtolower($value) === 'uppercase'
                    ?: ($value === 'none' ? false : throw new \LogicException("text-transform {$value}"));
                return;
            case 'text-align':
                // `th` alinha à esquerda; `td` herda o alinhamento da coluna (.right/.center).
                if (strtolower($value) !== 'left') throw new \LogicException("text-align {$value}");
                return;
        }

        throw new \LogicException("GridPdfStyle: propriedade '{$prop}' não suportada pelo motor");
    }

    /** "1px solid #eef2f7" → [0.75, 'solid', cor]. Só `solid` (outros estilos o motor não desenha). */
    private static function border(string $value): array
    {
        $p = preg_split('/\s+/', trim($value), 3);
        if (count($p) !== 3 || $p[1] !== 'solid') {
            throw new \LogicException("border {$value}");
        }
        $w = self::length($p[0]) ?? throw new \LogicException("border {$value}");
        $c = self::color($p[2]);

        return is_array($c) ? [$w, 'solid', $c] : self::NONE;
    }

    /** Comprimento em pt; null = unidade que o motor não converte igual ao Dompdf. */
    public static function length(string $v): ?float
    {
        $v = trim($v);
        if ($v === '0') return 0.0;
        if (!preg_match('/^(\d*\.?\d+)(px|pt|mm|cm|in)$/i', $v, $m)) {
            return null;
        }

        // Mesmas contas do Dompdf (Style::single_length_in_pt, 96 dpi).
        $n = (float) $m[1];

        return match (strtolower($m[2])) {
            'px' => ($n * 72) / 96,
            'pt' => $n,
            'mm' => $n * 72 / 25.4,
            'cm' => $n * 72 / 2.54,
            'in' => $n * 72,
        };
    }

    /** Cor no formato do canvas do Dompdf (mesmo parser). null = transparente/inválida. */
    public static function color(string $v): ?array
    {
        $c = Color::parse($v);

        return is_array($c) ? $c : null;
    }

    /** "Helvetica, Arial, sans-serif" → famílias na ordem de preferência (como o Dompdf). */
    private static function families(string $v): array
    {
        return array_values(array_filter(array_map(
            fn($f) => strtolower(trim($f, " \t\n\r\0\x0B\"'")),
            explode(',', $v)
        )));
    }

    /** Paleta efetiva (default do exporter + override do projeto). */
    public static function palette(): array
    {
        return \Mad\Grid\MadGridPdfBranding::palette() + MadGridExporter::PDF_PALETTE;
    }
}
