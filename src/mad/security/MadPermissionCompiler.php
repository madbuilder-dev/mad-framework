<?php

namespace Mad\Security;

/**
 * MadPermissionCompiler — liga o botão "Salvar" à ação do formulário que o
 * contém, na hora da COMPILAÇÃO.
 *
 * Um botão de envio não diz o que faz:
 *
 *     <mad-form submit="onSave">
 *         …campos…
 *         <mad-btn type="submit" label="Salvar" />
 *     </mad-form>
 *
 * Quem sabe a ação é o `<mad-form>`, e no render é tarde demais: o pipeline MAD
 * renderiza o SLOT (os campos e o botão) ANTES do template do formulário, então
 * o botão nunca enxerga o `submit` do pai. Sem esta passagem, "Salvar" era o
 * único botão da tela que não tinha como perguntar se o perfil permite incluir
 * ou editar.
 *
 * A solução é descer o `submit` para os botões de envio do bloco, como
 * `perm-action`, antes de qualquer outro compilador tocar nas tags. Daí em
 * diante é um atributo comum: o `parseParams` o entrega como `$permAction` e o
 * `components/btn.blade.php` decide.
 *
 * Regras de segurança da passagem:
 *   • só `<mad-btn type="submit">` DENTRO de um `<mad-form submit="…">`;
 *   • `perm-action` escrito à mão nunca é sobrescrito;
 *   • `submit` dinâmico (`:submit="$x"`) é ignorado — nome de método tem que
 *     ser literal para virar chave de permissão;
 *   • qualquer outro texto sai byte a byte igual.
 */
final class MadPermissionCompiler
{
    /**
     * Bloco de atributos de uma tag, ciente de aspas.
     *
     * `[^>]*` NÃO serve: valor de atributo tem `>` dentro (chain de campo
     * `{cidade->estado->nome}`, expressão `$a > 0`) e a leitura truncaria no
     * meio da tag — falha silenciosa, sem log.
     */
    private const ATTRS = '(?:[^>"\']|"[^"]*"|\'[^\']*\')*?';

    /** Nome de método aceitável como chave de permissão. */
    private const METHOD = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    public static function compile(string $value): string
    {
        if (strpos($value, '<mad-form') === false || strpos($value, '<mad-btn') === false) {
            return $value;
        }

        $abertura = '/<mad-form\b(' . self::ATTRS . ')>/s';
        $saida    = '';
        $pos      = 0;

        while (preg_match($abertura, $value, $m, PREG_OFFSET_CAPTURE, $pos)) {
            $inicioTag = (int) $m[0][1];
            $fimTag    = $inicioTag + strlen($m[0][0]);
            $atributos = (string) $m[1][0];

            $fechamento = strpos($value, '</mad-form>', $fimTag);
            if ($fechamento === false) {
                break; // formulário sem fechamento — nada a delimitar
            }

            $corpo  = substr($value, $fimTag, $fechamento - $fimTag);
            $acao   = self::submitOf($atributos);
            $saida .= substr($value, $pos, $fimTag - $pos)
                    . ($acao === null ? $corpo : self::injectInto($corpo, $acao));

            $pos = $fechamento;
        }

        return $saida . substr($value, $pos);
    }

    /** O `submit="onX"` literal do formulário, ou null. */
    private static function submitOf(string $atributos): ?string
    {
        if (!preg_match('/(?:^|\s)submit\s*=\s*(["\'])(.*?)\1/s', $atributos, $m)) {
            return null;
        }

        $acao = trim($m[2]);

        return preg_match(self::METHOD, $acao) === 1 ? $acao : null;
    }

    /** Escreve `perm-action="…"` nos botões de envio do corpo do formulário. */
    private static function injectInto(string $corpo, string $acao): string
    {
        if (strpos($corpo, '<mad-btn') === false) {
            return $corpo;
        }

        return preg_replace_callback(
            '/<mad-btn\b(' . self::ATTRS . ')(\/?)>/s',
            static function (array $m) use ($acao): string {
                $atributos = $m[1];

                $ehEnvio = preg_match('/(?:^|\s)type\s*=\s*(["\'])submit\1/i', $atributos) === 1;
                $jaTem   = preg_match('/(?:^|\s)perm-action\s*=/i', $atributos) === 1;

                if (!$ehEnvio || $jaTem) {
                    return $m[0];
                }

                return '<mad-btn perm-action="' . $acao . '"' . $atributos . $m[2] . '>';
            },
            $corpo
        ) ?? $corpo;
    }
}
