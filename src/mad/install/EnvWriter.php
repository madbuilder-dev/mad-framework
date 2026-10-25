<?php

declare(strict_types=1);

namespace Mad\Install;

/**
 * EnvWriter — upsert de pares KEY=value no arquivo .env, preservando
 * comentários, ordem e linhas alheias. Usado pelo instalador web (mad-install)
 * para persistir a conexão de banco escolhida no wizard.
 *
 * - Seed a partir de .env.example quando o .env ainda não existe (clone fresco).
 * - Reescreve a linha existente (mesmo se comentada: `# KEY=...`) ou anexa no fim.
 * - Escrita atômica (temp + rename) pra nunca deixar um .env truncado.
 * - Permissão: .env novo 0640; o que já existia mantém a que tinha.
 */
final class EnvWriter
{
    /**
     * @param array<string,scalar|null> $pairs
     */
    public static function upsert(array $pairs, ?string $path = null): void
    {
        $path ??= base_path('.env');
        // Deploy com releases (`releases/<n>/.env` → `shared/.env`, fw#444): o
        // rename sobre o LINK trocava o link por um arquivo comum dentro do
        // release, e o `shared/.env` nunca recebia a mudança — sumia no próximo
        // deploy. Grava no alvo do link; o temp + rename continua atômico, no
        // diretório do arquivo de verdade.
        $path = self::target($path);

        if (is_file($path)) {
            $contents = (string) file_get_contents($path);
        } else {
            $example  = base_path('.env.example');
            $contents = is_file($example) ? (string) file_get_contents($example) : '';
        }

        foreach ($pairs as $key => $value) {
            $line    = $key . '=' . self::quote((string) $value);
            $pattern = '/^[ \t]*#?[ \t]*' . preg_quote($key, '/') . '[ \t]*=.*$/m';

            if (preg_match($pattern, $contents)) {
                // Callback evita interpretação de $1/\1 na string de substituição.
                $contents = (string) preg_replace_callback($pattern, static fn () => $line, $contents, 1);
            } else {
                $contents = rtrim($contents, "\n") . "\n" . $line . "\n";
            }
        }

        // Permissão: .env novo nasce 0640 (dono lê e grava, o grupo — onde
        // costumam estar o servidor web e o usuário do deploy — só lê, os
        // outros nada: o arquivo tem a APP_KEY e as senhas do banco). .env que
        // já existia mantém a permissão que tinha: quem o criou sabe quem
        // precisa ler.
        $mode = is_file($path) ? (@fileperms($path) & 0777) : 0640;
        if (! $mode) {
            $mode = 0640;
        }

        $tmp = $path . '.tmp' . bin2hex(random_bytes(4));
        file_put_contents($tmp, $contents, LOCK_EX);
        @chmod($tmp, $mode);
        rename($tmp, $path);
    }

    /** Alvo final do `.env` quando ele é link simbólico (mesmo link quebrado). */
    private static function target(string $path): string
    {
        for ($hops = 0; $hops < 10 && is_link($path); $hops++) {
            $to = (string) readlink($path);
            if ($to === '') {
                break;
            }
            $path = str_starts_with($to, '/') ? $to : dirname($path) . '/' . $to;
        }

        return $path;
    }

    private static function quote(string $value): string
    {
        if ($value === '') {
            return '';
        }
        if (preg_match('/[\s#"\'=]/', $value)) {
            return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
        }
        return $value;
    }
}
