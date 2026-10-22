<?php

namespace Mad\Ui;

use Mad\Component\MadRenderContext;

/**
 * Peças comuns de `<mad-kpi-card>` e `<mad-metric-card>` (e, por ele, do
 * `<mad-db-metric-card>`): a largura estimada do valor, que o tema ERP usa
 * para encolher o número até caber numa linha, e o clique do card.
 *
 * Clique (mad-ui.js, ouvinte delegado no document):
 *   click-filter="campo=valor" → MadWire.call(tela, 'setProp', [campo, valor]);
 *                                de novo no card ativo, limpa (valor '')
 *   click-target="Tela"        → Mad.go(Tela, show) — `Tela::metodo` também vale
 *   sempre                     → evento `mad-kpi-click` {label, field, value,
 *                                pressed} no card, cancelável
 *
 * O filtro passa pela mesma lista do setProp da MadFiltersTrait: só grava
 * filtro que a tela declarou (public string/array). Tela sem setProp recebe só
 * o evento.
 */
final class MadKpi
{
    /** Caracteres que ocupam metade da largura de um dígito. */
    private const NARROW = " .,:;'!|()[]/-·\u{00A0}\u{202F}";

    /** Caracteres mais largos que um dígito. */
    private const WIDE = '%@MWmw—';

    /**
     * Largura do valor em "dígitos" (1 por dígito/letra, 0,5 por pontuação e
     * espaço, 1,4 por M/W/%/@/—) — o CSS do ERP divide a largura do card por
     * isto para achar o tamanho da fonte que cabe.
     */
    public static function valueWidth(string $value): float
    {
        $units = 0.0;
        foreach (mb_str_split($value) as $ch) {
            if (str_contains(self::NARROW, $ch)) {
                $units += 0.5;
            } elseif (str_contains(self::WIDE, $ch)) {
                $units += 1.4;
            } else {
                $units += 1.0;
            }
        }

        return max(1.0, round($units, 1));
    }

    /**
     * Atributos do clique do card, prontos para o HTML (escapados), e o
     * estado do filtro: `['attrs' => string, 'clickable' => bool, 'pressed' => ?bool]`.
     * `pressed` é null quando o card não filtra.
     */
    public static function click(?string $clickFilter, ?string $clickTarget, string $label = ''): array
    {
        $clickFilter = trim((string) $clickFilter);
        $clickTarget = trim((string) $clickTarget);
        $out = ['attrs' => '', 'clickable' => false, 'pressed' => null];

        $attrs = [];
        $filter = self::parseFilter($clickFilter);
        if ($filter !== null) {
            [$field, $value] = $filter;
            $host = MadRenderContext::getComponent();
            $out['pressed'] = self::hostHasValue($host, $field, $value);
            $attrs['data-kpi-field'] = $field;
            $attrs['data-kpi-value'] = $value;
            if ($host !== null && method_exists($host, 'setProp')) {
                $attrs['data-kpi-wire'] = '1';
            }
            $attrs['aria-pressed'] = $out['pressed'] ? 'true' : 'false';
        } elseif ($clickTarget !== '') {
            try {
                $nav = MadAction::navTarget($clickTarget, 'show', []);
            } catch (\Throwable) {
                $nav = null;
            }
            if ($nav !== null) {
                $attrs['data-kpi-target'] = $nav['class'];
                $attrs['data-kpi-method'] = $nav['method'];
                $attrs['data-kpi-url']    = $nav['url'];
            }
        }
        if ($attrs === []) {
            return $out;
        }

        $attrs = ['data-mad-kpi-click' => '1', 'role' => 'button', 'tabindex' => '0'] + $attrs;
        if ($label !== '') {
            $attrs['data-kpi-label'] = $label;
        }
        $html = '';
        foreach ($attrs as $k => $v) {
            $html .= ' ' . $k . '="' . e($v) . '"';
        }
        $out['attrs'] = $html;
        $out['clickable'] = true;

        return $out;
    }

    /**
     * `campo=valor` → [campo, valor]; `campo=` limpa o filtro (card "Todos").
     * Campo fora de `[A-Za-z_][A-Za-z0-9_]*` é ignorado.
     */
    public static function parseFilter(string $spec): ?array
    {
        $pos = strpos($spec, '=');
        if ($pos === false) {
            return null;
        }
        $field = trim(substr($spec, 0, $pos));
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $field)) {
            return null;
        }

        return [$field, trim(substr($spec, $pos + 1))];
    }

    /** O filtro `campo` da tela está em `valor`? Só propriedade pública conta. */
    private static function hostHasValue(?object $host, string $field, string $value): bool
    {
        if ($host === null || !array_key_exists($field, get_object_vars($host))) {
            return false;
        }
        $current = $host->$field;
        if (is_array($current)) {
            return $value === '' ? $current === [] : in_array($value, array_map('strval', $current), true);
        }

        return is_scalar($current) || $current === null ? (string) $current === $value : false;
    }
}
