<?php

namespace Mad\Support;

/**
 * ⓘ ao lado do rótulo do campo (`hint-icon`).
 *
 * A ajuda do campo (`hint`) aparece embaixo do controle e ocupa uma linha. Com
 * `hint-icon` ela vai para um ⓘ depois do rótulo, como já acontece no cabeçalho
 * das colunas do `<mad-field-list>`, e abre no hover, no foco e no toque.
 *
 *   <mad-input-field label="CPF" hint="Só números" hint-icon />        → ⓘ "Só números", nada embaixo
 *   <mad-input-field label="CPF" hint="Só números" hint-icon="Do titular" /> → ⓘ "Do titular", hint embaixo
 *
 * O ⓘ é um `<button type="button">` dentro do `<label>`: conteúdo interativo não
 * repassa o clique ao controle (o toque no celular abre a dica, não o teclado).
 * O texto vai em `data-mad-title`, que o tooltip global do mad-ui.js desenha
 * fora de qualquer `overflow:hidden`, e no `aria-label`. Nada de texto dentro do
 * botão: o `textContent` do rótulo continua sendo só o nome do campo (a mensagem
 * "O campo X é obrigatório" é montada a partir dele).
 */
final class FieldHint
{
    /**
     * Separa o texto do ⓘ e o que continua embaixo do campo.
     *
     * @return array{0: string, 1: string} [texto do ⓘ, hint que fica embaixo]
     */
    public static function split(mixed $hintIcon, mixed $hint, mixed $label = null): array
    {
        $hint = is_scalar($hint) ? (string) $hint : '';

        // Campo sem rótulo não tem onde pôr o ⓘ: a ajuda fica embaixo.
        if ($label !== null && trim(strip_tags(is_scalar($label) ? (string) $label : '')) === '') {
            return ['', $hint];
        }
        if ($hintIcon === null || $hintIcon === false || $hintIcon === '' || $hintIcon === 'false' || $hintIcon === '0' || $hintIcon === 0) {
            return ['', $hint];
        }
        if ($hintIcon === true || $hintIcon === 'true' || $hintIcon === '1' || $hintIcon === 1 || $hintIcon === 'hint-icon') {
            return [trim($hint), ''];
        }

        return [is_scalar($hintIcon) ? trim((string) $hintIcon) : '', $hint];
    }

    /** O ⓘ (vazio quando não há texto). */
    public static function icon(string $tip): string
    {
        if ($tip === '') {
            return '';
        }
        // O tooltip global aceita HTML no texto (como o `hint` embaixo, que sai
        // com {!! !!}); o aria-label leva só o texto.
        $attr  = htmlspecialchars($tip, ENT_QUOTES, 'UTF-8');
        $aria  = htmlspecialchars(trim(html_entity_decode(strip_tags($tip), ENT_QUOTES, 'UTF-8')), ENT_QUOTES, 'UTF-8');

        return '<button type="button" class="mad-label-hint" data-mad-title="' . $attr . '" aria-label="' . $aria . '">'
            . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg></button>';
    }
}
