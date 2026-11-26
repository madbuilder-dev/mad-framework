<?php

namespace Mad\Ai;

/**
 * ScriptDrift — o modelo troca de alfabeto NO MEIO de uma resposta em
 * português e emenda chinês/japonês/coreano ("…para 换取 o resultado…",
 * NavLab 1931, 26/11/2026, MiniMax-M3 pelo Coding Plan). Vício de modelo
 * treinado majoritariamente em chinês: a frase parseia e o usuário recebe uma
 * palavra que não consegue ler.
 *
 * CJK só é legítimo quando o USUÁRIO trouxe esse idioma para a conversa —
 * escreveu nele ou pediu o idioma por nome (`userBroughtCjk`). Fora disso, o
 * filtro de stream (`push`) tira cada trecho CJK do texto antes de ir à tela e
 * ao histórico. Espelho do `scriptDrift.ts` do agente do MadBuilder.
 */
final class ScriptDrift
{
    /**
     * Ideogramas (Han + extensão A + compatibilidade), kana e hangul. Pontuação
     * CJK sozinha (「」、。) não acende: ela aparece colada em texto latino.
     */
    private const CJK = '\x{3040}-\x{30ff}\x{3400}-\x{4dbf}\x{4e00}-\x{9fff}\x{ac00}-\x{d7af}\x{f900}-\x{faff}';

    /** Um trecho: do 1º ideograma em diante, enquanto for CJK ou pontuação CJK/fullwidth. */
    private const RUN = '[' . self::CJK . '][' . self::CJK . '\x{3000}-\x{303f}\x{ff00}-\x{ffef}]*';

    /** O usuário pediu o idioma por nome (`ja-JP` só com região: `ja` solto é o "já" sem acento). */
    private const LANGUAGE_RE =
        '/chin[eê]s|chinesa|mandarim|canton[eê]s|japon[eê]s|japonesa|coreano|coreana|chinese|mandarin|cantonese|japanese|korean|chino|japon[eé]s|\bzh(?:[-_](?:cn|tw|hk|hans|hant))?\b|\bja[-_]jp\b|\bko[-_]kr\b/iu';

    private bool $removedSinceEmit = false;

    /** Começo do texto conta como depois de espaço (o trecho inicial não deixa espaço na frente). */
    private bool $emittedEndsInSpace = true;

    private int $removed = 0;

    private string $sample = '';

    public static function hasCjk(string $text): bool
    {
        return preg_match('/[' . self::CJK . ']/u', $text) === 1;
    }

    /** O usuário trouxe chinês/japonês/coreano para a conversa — escrevendo nele ou pedindo por nome. */
    public static function userBroughtCjk(string $userText): bool
    {
        return self::hasCjk($userText) || preg_match(self::LANGUAGE_RE, $userText) === 1;
    }

    /**
     * Delta sem os trechos CJK. O espaço antes do trecho sai junto, e o espaço
     * que sobraria dobrado na emenda com o delta seguinte também ("para " +
     * "换取" + " o" → "para o").
     */
    public function push(string $delta): string
    {
        if ($delta === '') {
            return '';
        }
        $out = (string) preg_replace_callback(
            '/\h*' . self::RUN . '(?:\h*' . self::RUN . ')*/u',
            function (array $m): string {
                $this->removed++;
                if ($this->sample === '') {
                    $this->sample = mb_substr(trim($m[0]), 0, 24);
                }

                return '';
            },
            $delta,
            -1,
            $count,
        );
        if ($count > 0 && $out === '') {
            $this->removedSinceEmit = true;

            return '';
        }
        $leading = $count > 0 && preg_match('/^\h*' . self::RUN . '/u', $delta) === 1;
        if (($this->removedSinceEmit || $leading) && $this->emittedEndsInSpace) {
            $out = ltrim($out, " \t");
        }
        if ($out !== '') {
            $this->removedSinceEmit   = $count > 0 && preg_match('/' . self::RUN . '\h*$/u', $delta) === 1;
            $this->emittedEndsInSpace = preg_match('/\h$/u', $out) === 1;
        }

        return $out;
    }

    /** Quantos trechos CJK saíram do texto (0 = nenhum). */
    public function removed(): int
    {
        return $this->removed;
    }

    /** O primeiro trecho removido, curto, para o log. */
    public function sample(): string
    {
        return $this->sample;
    }
}
