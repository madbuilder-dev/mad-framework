<?php

namespace Mad\Support;

/**
 * Estouro de memória (`Allowed memory size … exhausted`) com rastro (fw#438).
 *
 * O fatal de memória chegava ao relatório do Laravel com 32 KB de folga, e o
 * caminho do relatório estourava de novo: nada no log, nada no stderr do
 * container, só o 500 do acesso. Aqui ficam as peças que o provider liga:
 * reconhecer o fatal, dar folga ao relatório e escrever a causa no stderr
 * antes de qualquer outra coisa, e a frase que a tela mostra.
 */
final class MadOutOfMemory
{
    /** Folga que o relatório ganha sobre o limite que estourou. */
    private const EXTRA_BYTES = 64 * 1024 * 1024;

    /** Abaixo disto de folga, o caminho caro (padrões das páginas do site) é pulado. */
    private const TIGHT_BYTES = 4 * 1024 * 1024;

    private static bool $trailed = false;

    public static function is(\Throwable $e): bool
    {
        return str_contains($e->getMessage(), 'Allowed memory size');
    }

    /**
     * Sobe o limite e escreve a causa no stderr — uma vez por requisição,
     * antes do log do Laravel (que pode não sair).
     */
    public static function trail(\Throwable $e): void
    {
        if (self::$trailed) {
            return;
        }
        self::$trailed = true;

        $limit = self::limitBytes();
        if ($limit > 0) {
            @ini_set('memory_limit', (string) ($limit + self::EXTRA_BYTES));
        }

        $route = '';
        try {
            $request = app()->bound('request') ? app('request') : null;
            $route   = $request ? $request->method() . ' ' . $request->path() : '';
        } catch (\Throwable) {
            $route = '';
        }
        $line = sprintf(
            "[mad] estouro de memória: %s em %s:%d; rota %s; pico %d MB\n",
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $route !== '' ? $route : '-',
            (int) round(memory_get_peak_usage(true) / 1048576)
        );
        $out = @fopen('php://stderr', 'ab');
        if ($out !== false) {
            @fwrite($out, $line);
            @fclose($out);
        }
    }

    /** A memória está no fim (perto do limite configurado)? */
    public static function tight(): bool
    {
        $limit = self::limitBytes();

        return $limit > 0 && memory_get_usage() > $limit - self::TIGHT_BYTES;
    }

    /** O que a tela mostra no lugar do "Erro do servidor" sem detalhe. */
    public static function userMessage(): string
    {
        $fallback = 'A tela tentou carregar dados demais de uma vez. Filtre a lista ou peça menos linhas por página.';
        try {
            $text = function_exists('mad_t') ? (string) mad_t('mad.error.out_of_memory') : '';
        } catch (\Throwable) {
            $text = '';
        }

        return $text !== '' && $text !== 'mad.error.out_of_memory' ? $text : $fallback;
    }

    /** memory_limit em bytes; 0 = sem limite (-1) ou ilegível. */
    private static function limitBytes(): int
    {
        $raw = trim((string) ini_get('memory_limit'));
        if ($raw === '' || $raw === '-1') {
            return 0;
        }
        $unit  = strtolower(substr($raw, -1));
        $value = (int) $raw;

        return match ($unit) {
            'g'     => $value * 1024 * 1024 * 1024,
            'm'     => $value * 1024 * 1024,
            'k'     => $value * 1024,
            default => $value,
        };
    }

    /** @internal testes */
    public static function reset(): void
    {
        self::$trailed = false;
    }
}
