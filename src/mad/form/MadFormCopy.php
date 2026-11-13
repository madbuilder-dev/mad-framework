<?php

namespace Mad\Form;

use Illuminate\Database\Eloquent\Model;

/**
 * A cópia que a ação "Duplicar selecionados" com `open-form` leva ao
 * formulário de cadastro (fórum #303): o registro já copiado (sem a chave,
 * com `reset` e `mark` aplicados), ainda NÃO gravado.
 *
 * A listagem guarda a cópia na sessão e abre o formulário com um código de
 * uso único ({@see PARAM}). Depois do `mount()`, o formulário pega a cópia
 * ({@see MadComponent::show()}) e se preenche como um cadastro novo: nada é
 * gravado até o Salvar, e Cancelar só descarta.
 *
 * O código vale para o formulário escolhido, uma vez e por pouco tempo; o
 * conteúdo nunca passa pelo navegador.
 */
final class MadFormCopy
{
    /** Parâmetro que leva o código da cópia ao formulário. */
    public const PARAM = 'mad_copy';

    /** Chave da sessão. */
    private const SESSION = 'mad.form_copy';

    /** Cópias guardadas ao mesmo tempo (as mais antigas saem). */
    private const KEEP = 5;

    /** Validade da cópia, em segundos. */
    private const TTL = 1800;

    /**
     * Guarda a cópia para `$formClass` e devolve o código de uso único.
     */
    public static function stash(string $formClass, Model $copy): string
    {
        $token = bin2hex(random_bytes(16));
        $all   = self::all();
        $all[$token] = [
            'form'  => ltrim($formClass, '\\'),
            'model' => $copy::class,
            'attrs' => $copy->getAttributes(),
            'at'    => time(),
        ];
        $all = array_slice($all, -self::KEEP, null, true);
        session()->put(self::SESSION, $all);

        return $token;
    }

    /**
     * Tira a cópia da sessão (uso único) e devolve o registro novo, sem
     * chave e sem gravar — ou null quando o código não existe, venceu ou é
     * de outro formulário.
     */
    public static function pull(string $token, string $formClass): ?Model
    {
        if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
            return null;
        }
        $all   = self::all();
        $entry = $all[$token] ?? null;
        unset($all[$token]);
        session()->put(self::SESSION, $all);

        if (!is_array($entry)
            || ($entry['form'] ?? '') !== ltrim($formClass, '\\')
            || time() - (int) ($entry['at'] ?? 0) > self::TTL) {
            return null;
        }
        $class = (string) ($entry['model'] ?? '');
        if (!class_exists($class) || !is_subclass_of($class, Model::class)) {
            return null;
        }

        /** @var Model $copy */
        $copy = new $class();
        $copy->setRawAttributes(is_array($entry['attrs'] ?? null) ? $entry['attrs'] : []);
        $copy->exists = false;
        // Uma chave que tenha sobrado nunca vai ao formulário: com ela, o
        // Salvar acharia o registro de origem.
        $copy->offsetUnset($copy->getKeyName());

        return $copy;
    }

    /** @return array<string, array<string, mixed>> */
    private static function all(): array
    {
        $all = session(self::SESSION);

        return is_array($all) ? $all : [];
    }
}
