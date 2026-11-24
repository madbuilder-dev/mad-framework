<?php

namespace Mad\Support;

/**
 * FormConfirmLeave — aviso ao sair de uma tela com alteração não salva (fw#632).
 *
 * Opt-in, em três lugares:
 *   - `confirm-leave` no `<mad-form>` (o próprio formulário);
 *   - `protected static bool $confirmLeave = true;` na tela (a raiz do
 *     componente: todos os campos da tela);
 *   - `MAD_CONFIRM_LEAVE=true` (`mad.confirm_leave.enabled`): todo
 *     `<mad-form>` com `submit` do app; `confirm-leave="false"` tira um.
 *
 * O escopo ganha `data-mad-confirm-leave="1"` e o `MadLeaveGuard` do mad.js
 * pergunta, com o diálogo e os textos do `confirm-close` da cortina lateral
 * (`lang/<idioma>/form.php`), antes da navegação do app que troca a tela, no
 * voltar do navegador e — pelo aviso nativo — ao fechar ou recarregar a aba.
 * Sem nenhum dos três, nada muda.
 */
final class FormConfirmLeave
{
    /** atributo do escopo => chave de tradução (os mesmos textos do confirm-close) */
    private const TEXTS = [
        'data-mad-confirm-leave-msg' => 'form.discard_confirm',
        'data-mad-confirm-leave-yes' => 'form.discard_yes',
        'data-mad-confirm-leave-no'  => 'form.discard_no',
    ];

    /**
     * O `<mad-form>` pergunta? A prop vence (atributo nu, `"true"`, `:` com
     * bool); sem ela (ou vazia), vale a opção global — só para formulário que
     * grava (`submit`).
     */
    public static function forForm(mixed $prop, string $submit): bool
    {
        if (is_bool($prop)) {
            return $prop;
        }
        if ($prop !== null && $prop !== '') {
            return filter_var($prop, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;
        }
        return $submit !== '' && self::globalOn();
    }

    /** `mad.confirm_leave.enabled` (MAD_CONFIRM_LEAVE). */
    public static function globalOn(): bool
    {
        try {
            return filter_var(config('mad.confirm_leave.enabled', false), FILTER_VALIDATE_BOOL);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * A tela declara `protected static bool $confirmLeave = true;`? Lido por
     * reflexão, como o `$confirmDiscard`: tela que não declara, ou que tem uma
     * propriedade de instância com esse nome, fica como sempre.
     */
    public static function forScreen(object $component): bool
    {
        try {
            $prop = new \ReflectionProperty($component, 'confirmLeave');
        } catch (\ReflectionException) {
            return false;
        }
        if (!$prop->isStatic()) {
            return false;
        }
        $prop->setAccessible(true);
        return filter_var($prop->getValue(), FILTER_VALIDATE_BOOL);
    }

    /**
     * Atributos (com espaço na frente) para colar na tag do escopo. Um app com
     * o `lang/` anterior às chaves não manda o texto e vale o padrão (pt-BR)
     * do próprio mad.js.
     */
    public static function attrs(): string
    {
        $out = ' data-mad-confirm-leave="1"';
        foreach (self::TEXTS as $attr => $key) {
            $text = __($key);
            if (is_string($text) && $text !== '' && $text !== $key) {
                $out .= ' ' . $attr . '="' . e($text) . '"';
            }
        }
        return $out;
    }
}
