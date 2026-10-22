<?php

namespace Mad\Form;

use Mad\Component\MadRenderContext;

/**
 * Formulário em leitura (fw#498): a ficha de um registro mostra rótulo e valor,
 * não controles — nada de "Selecione..." vazio, toggle desligado ou caixa de
 * texto para quem só pode ler.
 *
 * Liga de dois jeitos:
 *   <mad-form read>              todos os campos, "—" no vazio
 *   <mad-form read="filled">     só os campos preenchidos
 *   $this->form->readMode()      o mesmo pelo PHP (ex.: no onEdit, por perfil);
 *   $this->form->readMode('filled')
 *
 * Cada campo que conhece o modo (input, textarea, select, dbcombo, date,
 * datetime, money, number, switch, checkbox, radio, dbradio) chama field() no
 * lugar do controle: rótulo e valor no visual do <mad-display-field> e o
 * valor num <input type="hidden">, para a ação da tela que lê o formulário
 * continuar recebendo o mesmo dado. Os outros campos ficam travados pelo CSS
 * (.mad-form-read).
 */
final class ReadMode
{
    public const ALL    = 'all';
    public const FILLED = 'filled';

    /**
     * Modo do formulário em volta do campo: '' (edição), 'all' ou 'filled'.
     * O PHP (`$this->form->readMode()`) vence a tag; a tag é achada na pilha de
     * componentes do Blade, porque o campo é desenhado antes do <mad-form>.
     *
     * @param object|null $env  o `$__env` da view do campo
     */
    public static function mode($env = null): string
    {
        $form = MadRenderContext::getForm();
        if ($form && $form->readMode !== '') {
            return $form->readMode;
        }
        if ($env && method_exists($env, 'getConsumableComponentData')) {
            return self::normalize($env->getConsumableComponentData('read', null));
        }

        return '';
    }

    /** Valor da prop `read` / do readMode(): true|'all' → 'all', 'filled' → 'filled', resto → ''. */
    public static function normalize($v): string
    {
        if ($v === true) {
            return self::ALL;
        }
        if (! is_string($v) && ! is_int($v)) {
            return '';
        }
        $v = strtolower(trim((string) $v));
        if ($v === self::FILLED) {
            return self::FILLED;
        }

        return in_array($v, ['1', 'true', 'all', 'read', 'yes', 'sim'], true) ? self::ALL : '';
    }

    /**
     * Campo em leitura.
     *
     * @param array{
     *     name?: string, label?: string, text?: string|null, html?: string|null,
     *     raw?: string|array|null, hidden?: bool, bool?: bool, on?: bool, class?: string, style?: string
     * } $o  `text` (escapado) ou `html` (já seguro); `raw` vai no hidden; `bool`
     *       = campo sim/não, ligado conforme `on` (desligado conta como vazio no
     *       modo 'filled').
     */
    public static function field(string $mode, array $o): string
    {
        $name  = (string) ($o['name'] ?? '');
        $raw   = $o['raw'] ?? '';
        $isBool = ! empty($o['bool']);
        $html  = $o['html'] ?? null;
        $text  = $o['text'] ?? null;
        $empty = $html === null
            ? ($text === null || trim((string) $text) === '')
            : trim(strip_tags((string) $html)) === '';
        if ($isBool) {
            $on    = array_key_exists('on', $o) ? (bool) $o['on'] : self::truthy($raw);
            $text  = $on ? mad_t('mad.field.yes') : mad_t('mad.field.no');
            $html  = null;
            $empty = ! $on;
        }

        $hiddenInputs = self::hiddenInputs($name, $raw);
        if ($mode === self::FILLED && $empty) {
            // Fora da tela, mas o valor continua no formulário.
            return '<div class="mad-field-read-skip" hidden'
                . ($name !== '' ? ' data-mad-field="' . e($name) . '"' : '') . '>' . $hiddenInputs . '</div>';
        }

        if ($empty && ! $isBool) {
            $value = '<span class="mad-display-field-empty">—</span>';
        } else {
            $value = $html !== null ? (string) $html : nl2br(e((string) $text));
        }
        $label = (string) ($o['label'] ?? '');
        $class = 'mad-field mad-display-field mad-field-read'
            . (! empty($o['hidden']) ? ' mad-hidden' : '')
            . (! empty($o['class']) ? ' ' . $o['class'] : '');

        return '<div class="' . e($class) . '"'
            . ($name !== '' ? ' data-mad-field="' . e($name) . '"' : '')
            . (! empty($o['style']) ? ' style="' . e($o['style']) . '"' : '') . '>'
            . ($label !== '' ? '<div class="mad-display-field-header"><span class="mad-display-field-label">' . $label . '</span></div>' : '')
            . '<div class="mad-display-field-value">' . $value . '</div>'
            . $hiddenInputs
            . '</div>';
    }

    /** Rótulos das opções escolhidas (`[valor => rótulo]`), na ordem da escolha. */
    public static function optionText(array $options, $selected): string
    {
        // Lista de objetos (`[['value' => …, 'label' => …]]`, a forma do radio) vira mapa.
        if (isset($options[0]) && is_array($options[0]) && array_key_exists('value', $options[0])) {
            $options = array_column($options, 'label', 'value');
        }
        $keys = is_array($selected) ? $selected : MadForm::selectionKeys($selected);
        $out = [];
        foreach ($keys as $k) {
            $k = (string) $k;
            if ($k === '') {
                continue;
            }
            $label = $options[$k] ?? null;
            if (is_array($label)) {
                $label = $label['label'] ?? $label['text'] ?? null;
            }
            $out[] = $label !== null ? strip_tags((string) $label) : $k;
        }

        return implode(', ', $out);
    }

    /** `[valor => rótulo]` das `<option>` de um slot (select montado com opções no Blade). */
    public static function slotOptions(string $slot): array
    {
        $out = [];
        if (preg_match_all('#<option\b([^>]*)>(.*?)</option>#si', $slot, $m, PREG_SET_ORDER)) {
            foreach ($m as $o) {
                $val = preg_match('#\bvalue\s*=\s*(["\'])(.*?)\1#si', $o[1], $v)
                    ? html_entity_decode($v[2], ENT_QUOTES)
                    : trim(html_entity_decode(strip_tags($o[2]), ENT_QUOTES));
                $out[$val] = trim(html_entity_decode(strip_tags($o[2]), ENT_QUOTES));
            }
        }

        return $out;
    }

    /** Número cru ("1830.5") ou já formatado ("1.830,50") com prefixo e separadores. */
    public static function money(string $raw, string $prefix = 'R$', int $decimals = 2, string $dec = ',', string $thou = '.', string $suffix = ''): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        $num = is_numeric($raw) ? (float) $raw : (float) str_replace([$thou, $dec], ['', '.'], $raw);

        return trim(($prefix !== '' ? $prefix . ' ' : '') . number_format($num, $decimals, $dec, $thou) . ($suffix !== '' ? ' ' . $suffix : ''));
    }

    public static function truthy($v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if (is_array($v)) {
            return $v !== [];
        }

        return ! in_array(strtolower(trim((string) $v)), ['', '0', 'false', 'f', 'n', 'no', 'nao', 'não', 'off', 'null'], true);
    }

    private static function hiddenInputs(string $name, $raw): string
    {
        if ($name === '') {
            return '';
        }
        if (is_array($raw)) {
            $out = '';
            foreach ($raw as $v) {
                if (is_scalar($v)) {
                    $out .= '<input type="hidden" name="' . e($name) . '[]" value="' . e((string) $v) . '">';
                }
            }

            return $out;
        }
        if (is_bool($raw)) {
            $raw = $raw ? '1' : '';
        }

        return '<input type="hidden" name="' . e($name) . '" value="' . e(is_scalar($raw) ? (string) $raw : '') . '">';
    }
}
