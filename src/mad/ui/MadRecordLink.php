<?php

namespace Mad\Ui;

use Mad\Component\MadRenderContext;
use Mad\Form\MadForm;
use Mad\Form\MadRecordPath;

/**
 * Link externo de `<mad-btn>` e `<mad-dropdown-item>`: `{campo}` vem do registro
 * da tela, codificado para URL, e `target="_blank"` abre em nova aba.
 *
 * O servidor preenche o `href` com o registro carregado. O `mad.js` preenche de
 * novo na hora do clique com o que está digitado nos campos do formulário
 * (`Mad._fillRecordLink`), com a mesma regra de codificação.
 *
 * Fora de uma tela com formulário não há registro: o texto fica como escrito.
 */
final class MadRecordLink
{
    /** `{campo}` ou `{relacao->campo}`, a mesma forma de grid, kanban e calendário. */
    private const TOKEN = '/\{([A-Za-z_]\w*(?:->[A-Za-z_]\w*)*)\}/';

    private const HTML_TARGETS = ['_blank', '_self', '_parent', '_top'];

    /** O valor é destino de link do HTML, e não o nome de uma tela? */
    public static function isHtmlTarget(string $value): bool
    {
        return in_array($value, self::HTML_TARGETS, true);
    }

    /**
     * Troca cada `{campo}` pelo valor codificado.
     *
     * No começo do link o valor pode ser a própria URL (`href="{site}"`), então
     * http(s), mailto, tel e caminho do próprio site passam inteiros. Qualquer
     * outro começo, como `javascript:`, sai codificado e não executa.
     *
     * @param array<string, string> $values
     */
    public static function fill(string $href, array $values): string
    {
        return (string) preg_replace_callback(self::TOKEN, static function (array $m) use ($values): string {
            $value = (string) ($values[$m[1][0]] ?? '');
            if ($m[0][1] === 0 && preg_match('#^(?:https?://|mailto:|tel:|/(?!/))#i', $value)) {
                return $value;
            }

            return rawurlencode($value);
        }, $href, -1, $count, PREG_OFFSET_CAPTURE);
    }

    /**
     * O `href` pronto e os atributos extras da tag `<a>`, já escapados.
     *
     * @return array{href: string, attrs: string}
     */
    public static function render(string $href, string $target, string $callerAttrs): array
    {
        $attrs = '';
        if (self::isHtmlTarget($target) && !self::hasAttr($callerAttrs, 'target')) {
            $attrs .= ' target="' . $target . '"';
            if ($target === '_blank' && !self::hasAttr($callerAttrs, 'rel')) {
                $attrs .= ' rel="noopener noreferrer"';
            }
        }

        $form = preg_match(self::TOKEN, $href) === 1 ? MadRenderContext::getForm() : null;
        if ($form === null) {
            return ['href' => $href, 'attrs' => $attrs];
        }

        $values = self::recordValues($href, $form);
        $json   = (string) json_encode($values, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $attrs .= ' data-mad-href-tpl="' . htmlspecialchars($href, ENT_QUOTES) . '"'
                . ' data-mad-href-vals="' . htmlspecialchars($json, ENT_QUOTES) . '"';

        return ['href' => self::fill($href, $values), 'attrs' => $attrs];
    }

    /** @return array<string, string> Valor de cada `{campo}`; vazio quando não há. */
    private static function recordValues(string $href, MadForm $form): array
    {
        preg_match_all(self::TOKEN, $href, $m);
        $values = [];
        foreach (array_unique($m[1]) as $name) {
            $values[$name] = self::valueOf($name, $form);
        }

        return $values;
    }

    private static function valueOf(string $name, MadForm $form): string
    {
        try {
            $value = $form->fields[$name] ?? null;
            $record = $form->getSourceRecord();
            if ($value === null && $record !== null) {
                $value = MadRecordPath::resolvePath($record, $name);
            }

            return is_scalar($value) ? (string) $value : '';
        } catch (\Throwable) {
            return '';
        }
    }

    private static function hasAttr(string $attrs, string $name): bool
    {
        return preg_match('/(^|\s)' . preg_quote($name, '/') . '\s*=/i', $attrs) === 1;
    }
}
