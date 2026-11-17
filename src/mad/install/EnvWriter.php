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
 * - Escrita atômica (temp + rename) pra nunca deixar um .env truncado. Sem
 *   escrita na pasta (servidor web dono só do arquivo .env, fórum/issue
 *   builder#683), grava no próprio arquivo, com trava.
 * - Permissão: .env novo 0640; o que já existia mantém dono e grupo como
 *   estavam, mas perde a leitura de "outros" (o .zip chegava 0644 — APP_KEY e
 *   senha do banco legíveis por qualquer conta do servidor).
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
        // já existia mantém dono e grupo como estavam (quem o criou sabe quem
        // precisa ler) e perde só o "outros" (builder#683).
        $mode = is_file($path) ? (@fileperms($path) & 0770) : 0640;
        $mode = ($mode ?: 0640) | 0600;

        // Atômico: temporário na mesma pasta + rename. `@`: o handler de erro
        // do Laravel transforma o aviso do PHP em exceção, e aqui a falha tem
        // plano B.
        $tmp = $path . '.tmp' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $contents, LOCK_EX) !== false) {
            @chmod($tmp, $mode);
            if (@rename($tmp, $path)) {
                return;
            }
            @unlink($tmp);
        }

        // Plano B (builder#683): a pasta do projeto não aceita arquivo novo
        // (dona do root, só o .env do servidor web — como mandava o LEIA-ME
        // antigo). Grava no próprio arquivo, com trava; o chmod só vale quando
        // o PHP é o dono (senão o sistema recusa e a permissão fica como está).
        if (is_file($path) && is_writable($path)) {
            if (@file_put_contents($path, $contents, LOCK_EX) === false) {
                throw self::notWritable($path);
            }
            @chmod($path, $mode);

            return;
        }

        throw self::notWritable($path);
    }

    /**
     * O instalador consegue gravar o `.env`? Arquivo gravável, ou pasta onde
     * dá para criar (o .env novo ou o temporário da troca atômica). O
     * instalador confere ANTES de mexer no banco: se a gravação falhasse só no
     * fim, as migrations já teriam rodado e o /install não abriria de novo.
     */
    public static function writable(?string $path = null): bool
    {
        $path = self::target($path ?? base_path('.env'));
        if (is_file($path) && is_writable($path)) {
            return true;
        }

        return is_writable(dirname($path));
    }

    /** Exceção com o que fazer, no idioma do app (o caminho é do próprio servidor). */
    public static function notWritable(string $path): \RuntimeException
    {
        $user = function_exists('posix_geteuid') && function_exists('posix_getpwuid')
            ? (string) (posix_getpwuid(posix_geteuid())['name'] ?? '')
            : '';

        return new \RuntimeException(__('install.env_not_writable', [
            'path' => $path,
            'dir'  => dirname($path),
            'user' => $user !== '' ? $user : 'www-data',
        ]));
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
