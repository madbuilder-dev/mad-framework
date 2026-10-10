<?php

namespace Mad\Support;

/**
 * OverlayConfirmClose — marcas do `confirm-close` de `<mad-drawer>` e
 * `<mad-modal>` (e da tela com `$wrapper = DRAWER/MODAL` e
 * `protected static bool $confirmDiscard = true`).
 *
 * O overlay ganha `data-mad-confirm-close="1"`: Esc, X e clique fora passam
 * pelo `MadOverlayEsc.dismiss()` do mad.js, que pergunta antes de fechar
 * quando há alteração não salva. Os textos da pergunta vêm do idioma do app
 * (`lang/<idioma>/form.php`); um app com o `lang/` anterior a estas chaves
 * não manda o atributo e vale o texto padrão (pt-BR) do próprio mad.js.
 */
final class OverlayConfirmClose
{
    /** atributo do overlay => chave de tradução */
    private const TEXTS = [
        'data-mad-confirm-close-msg' => 'form.discard_confirm',
        'data-mad-confirm-close-yes' => 'form.discard_yes',
        'data-mad-confirm-close-no'  => 'form.discard_no',
    ];

    /** Atributos (com espaço na frente) para colar na tag do overlay. */
    public static function attrs(): string
    {
        $out = ' data-mad-confirm-close="1"';
        foreach (self::TEXTS as $attr => $key) {
            $text = __($key);
            if (is_string($text) && $text !== '' && $text !== $key) {
                $out .= ' ' . $attr . '="' . e($text) . '"';
            }
        }
        return $out;
    }
}
