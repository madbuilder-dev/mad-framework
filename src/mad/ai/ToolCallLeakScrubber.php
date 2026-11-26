<?php

namespace Mad\Ai;

/**
 * ToolCallLeakScrubber — filtro de stream para a chamada de ferramenta que o
 * modelo escreve como TEXTO em vez do bloco nativo (tool_use).
 *
 * Recebe cada text_delta de uma rodada e devolve só o que pode ir à tela. Do
 * primeiro sinal de chamada em diante nada sai: é scaffolding, nunca prosa, e
 * o que já foi pintado no chat não volta. Três formas vistas em produção:
 *
 *  - o dialeto XML do MiniMax (`<invoke name="…">`, `<tool_call>`,
 *    `]<]minimax[>`), também partido entre dois deltas (`<inv` + `oke name=`);
 *  - o nome de uma ferramenta da rodada seguido dos argumentos
 *    (`suggest_next{"options":[…]}`);
 *  - os argumentos crus no fim da linha, sem nome (`…são os|Próximos
 *    passos]]}`, NavLab 1931, 26/11/2026): trecho que abre com `|` ou `{` e
 *    fecha com `]…}`.
 *
 * Para o marcador partido e para a terceira forma, a cauda que ainda pode ser
 * começo de chamada fica retida até o próximo delta (ou até a quebra de linha,
 * ou o fim da rodada) desempatar. Espelho do `toolCallLeak.ts` do agente do
 * MadBuilder, mais a forma sem nome.
 */
final class ToolCallLeakScrubber
{
    /** Dialeto XML de tool call que o MiniMax vaza como texto. */
    private const MARKERS = ['<tool_call>', '<invoke name=', '<invoke  name=', ']<]minimax[>', '<function_calls>', '<invoke'];

    /** Fim de linha com cara de argumentos fechando (`]]}`, `"}]}`, `}]}`). */
    private const CLOSING_ARGS_RE = '/\][\]\}"\s]*\}\s*$/';

    private string $pending = '';

    private string $leaked = '';

    private bool $cut = false;

    /** Texto já liberado na rodada (para saber se está dentro de bloco de código). */
    private string $released = '';

    /** @var list<string> nomes de ferramenta com `_` (palavra solta não vira marcador) */
    private array $names = [];

    private ?string $namesRe = null;

    /** @param list<string> $toolNames ferramentas da rodada */
    public function __construct(array $toolNames = [])
    {
        foreach ($toolNames as $name) {
            if (is_string($name) && preg_match('/^[A-Za-z][A-Za-z0-9]*_[A-Za-z0-9_]+$/', $name) === 1) {
                $this->names[] = $name;
            }
        }
        if ($this->names !== []) {
            $alt = implode('|', array_map(fn ($n) => preg_quote($n, '/'), $this->names));
            $this->namesRe = '/(?<![\p{L}\p{N}_])(?:' . $alt . ')\s*[\{\(\|]/u';
        }
    }

    /** O pedaço do delta seguro para exibir (pode ser vazio). */
    public function push(string $delta): string
    {
        if ($this->cut) {
            $this->leaked .= $delta;

            return '';
        }
        $buf           = $this->pending . $delta;
        $this->pending = '';

        $at = $this->markerAt($buf);
        if ($at !== null) {
            return $this->cutAt($buf, $at);
        }

        // Linhas completas: a que fecha com argumentos é chamada vazada.
        $out = '';
        while (($nl = strpos($buf, "\n")) !== false) {
            $line = substr($buf, 0, $nl + 1);
            $at   = $this->closingArgsAt(rtrim($line, "\r\n"));
            if ($at !== null) {
                return $out . $this->cutAt($buf, $at);
            }
            $out .= $this->release($line);
            $buf  = substr($buf, $nl + 1);
        }

        $hold          = $this->holdFrom($buf);
        $this->pending = substr($buf, $hold);

        return $out . $this->release(substr($buf, 0, $hold));
    }

    /** Fim da rodada: solta a cauda retida, a não ser que ela seja a chamada. */
    public function flush(): string
    {
        if ($this->cut) {
            return '';
        }
        $buf           = $this->pending;
        $this->pending = '';
        $at            = $this->closingArgsAt($buf);
        if ($at !== null) {
            return $this->cutAt($buf, $at);
        }

        return $this->release($buf);
    }

    public function leaked(): bool
    {
        return $this->cut;
    }

    public function leakedText(): string
    {
        return $this->leaked;
    }

    private function release(string $text): string
    {
        $this->released .= $text;

        return $text;
    }

    private function cutAt(string $buf, int $at): string
    {
        $this->cut     = true;
        $this->leaked  = substr($buf, $at);
        $this->pending = '';

        return $this->release(substr($buf, 0, $at));
    }

    private function inCode(): bool
    {
        return substr_count($this->released, '```') % 2 === 1;
    }

    /** Primeiro marcador explícito (XML ou nome de ferramenta + argumentos). */
    private function markerAt(string $buf): ?int
    {
        $best = null;
        foreach (self::MARKERS as $marker) {
            $pos = strpos($buf, $marker);
            if ($pos !== false && ($best === null || $pos < $best)) {
                $best = $pos;
            }
        }
        if ($this->namesRe !== null && preg_match($this->namesRe, $buf, $m, PREG_OFFSET_CAPTURE) === 1) {
            $pos = (int) $m[0][1];
            if ($best === null || $pos < $best) {
                $best = $pos;
            }
        }

        return $best;
    }

    /** Onde começa a chamada numa linha que fecha com argumentos (null = prosa). */
    private function closingArgsAt(string $line): ?int
    {
        if ($this->inCode() || preg_match(self::CLOSING_ARGS_RE, $line) !== 1) {
            return null;
        }

        return $this->openerAt($line);
    }

    /** Primeiro `|` ou `{` da linha (fora de bloco de código). */
    private function openerAt(string $line): ?int
    {
        $pipe  = strpos($line, '|');
        $brace = strpos($line, '{');
        $at    = $pipe === false ? $brace : ($brace === false ? $pipe : min($pipe, $brace));

        return $at === false ? null : $at;
    }

    /**
     * Quanto da linha corrente (sem quebra) pode ser liberado: tudo antes do
     * primeiro `|`/`{` (só a quebra de linha ou o fim da rodada dizem se é
     * tabela/prosa ou argumentos) e antes de uma cauda que pode ser começo de
     * marcador ou de nome de ferramenta.
     */
    private function holdFrom(string $buf): int
    {
        $hold = strlen($buf);
        if (! $this->inCode()) {
            $opener = $this->openerAt($buf);
            if ($opener !== null) {
                $hold = $opener;
            }
        }

        $max = min(max(array_map('strlen', self::MARKERS)) - 1, strlen($buf));
        for ($n = $max; $n > 0; $n--) {
            $tail = substr($buf, -$n);
            foreach (self::MARKERS as $marker) {
                if (str_starts_with($marker, $tail)) {
                    return min($hold, strlen($buf) - $n);
                }
            }
        }

        if ($this->names !== [] && preg_match('/(?<![\p{L}\p{N}_])([\p{L}\p{N}_]+)(\s*)$/u', $buf, $m, PREG_OFFSET_CAPTURE) === 1) {
            $word = $m[1][0];
            foreach ($this->names as $name) {
                if ($m[2][0] === '' ? str_starts_with($name, $word) : $name === $word) {
                    return min($hold, (int) $m[0][1]);
                }
            }
        }

        return $hold;
    }
}
