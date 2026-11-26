<?php

namespace Mad\Support;

/**
 * FieldAutocomplete — o `autocomplete` dos campos de digitação e do formulário
 * (fórum #436).
 *
 * - `autocomplete="…"` escrito no campo (`off`, `on`, `name`, `email`,
 *   `postal-code`, `new-password`…) chega ao `<input>`. Antes o
 *   `<mad-input-field autocomplete="off">` era engolido sem aviso e só o
 *   `attrs='autocomplete="off"'` funcionava. `false` = `off`; `true` (atributo
 *   nu) = `on`.
 * - Opção do projeto "Desligar sugestões do navegador nos formulários"
 *   (`mad.autocomplete_off.enabled`, MAD_AUTOCOMPLETE_OFF): o `<form>` do
 *   `<mad-form>` e cada campo de digitação sem valor próprio saem com
 *   `autocomplete="off"`. Campo de senha fica como está (o `<mad-password-field>`
 *   segue com `current-password`/`new-password`): o gerenciador de senhas do
 *   login continua funcionando.
 * - `autocomplete` já escrito em `attrs` vence (nada é duplicado).
 * - Sem a opção e sem o atributo, nada muda: o HTML sai como antes.
 */
final class FieldAutocomplete
{
    /** Tokens do HTML: `off`, `email`, `section-x shipping street-address`, `cc-number`… */
    private const TOKEN = '/^[A-Za-z0-9][A-Za-z0-9 _-]{0,79}$/';

    /**
     * ` autocomplete="…"` (com espaço na frente) para o `<input>`/`<textarea>`
     * de um campo; '' = nada a escrever.
     *
     * @param mixed  $prop     o `autocomplete` do campo (string, bool ou null)
     * @param string $attrs    o `attrs` cru do campo (vence, se já tiver autocomplete)
     * @param bool   $password campo de senha: a opção do projeto não mexe nele
     */
    public static function attr(mixed $prop, string $attrs = '', bool $password = false): string
    {
        if (self::inAttrs($attrs)) {
            return '';
        }
        $value = self::normalize($prop);
        if ($value === '' && !$password && self::globalOff()) {
            $value = 'off';
        }
        return $value === '' ? '' : ' autocomplete="' . htmlspecialchars($value, ENT_QUOTES) . '"';
    }

    /** ` autocomplete="off"` para o `<form>` do `<mad-form>`, com a opção do projeto ligada. */
    public static function formAttr(string $attrs = ''): string
    {
        return !self::inAttrs($attrs) && self::globalOff() ? ' autocomplete="off"' : '';
    }

    /** Valor escrito no campo → token; vazio/inválido = '' (o campo não decide). */
    public static function normalize(mixed $prop): string
    {
        if ($prop === false) {
            return 'off';
        }
        if ($prop === true) {
            return 'on';
        }
        if (!is_string($prop) && !is_numeric($prop)) {
            return '';
        }
        $value = trim((string) $prop);
        $lower = strtolower($value);
        if ($lower === 'false') {
            return 'off';
        }
        if ($lower === 'true') {
            return 'on';
        }
        return preg_match(self::TOKEN, $value) === 1 ? $value : '';
    }

    /** O `attrs` do campo já escreve `autocomplete=`? */
    public static function inAttrs(string $attrs): bool
    {
        return preg_match('/(?:^|\s)autocomplete\s*=/i', $attrs) === 1;
    }

    /** `mad.autocomplete_off.enabled` (MAD_AUTOCOMPLETE_OFF). */
    public static function globalOff(): bool
    {
        try {
            return filter_var(config('mad.autocomplete_off.enabled', false), FILTER_VALIDATE_BOOL);
        } catch (\Throwable) {
            return false;
        }
    }
}
