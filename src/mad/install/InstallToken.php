<?php

declare(strict_types=1);

namespace Mad\Install;

/**
 * InstallToken — gate timing-safe do instalador web (mad-install).
 *
 * O token prova posse do servidor: ou vem do env `INSTALL_TOKEN`, ou é gerado e
 * gravado em `storage/app/install-token.txt` (0600) — só quem tem acesso ao
 * filesystem do servidor consegue lê-lo. O operador cola o valor no wizard;
 * a comparação é constant-time (`hash_equals`). Independente do banco, então
 * funciona no clone fresco (pré-migração).
 */
final class InstallToken
{
    private static function path(): string
    {
        return storage_path('app/install-token.txt');
    }

    /**
     * Garante que existe um token e devolve o valor (env tem precedência).
     */
    public static function ensure(): string
    {
        $env = (string) env('INSTALL_TOKEN', '');
        if ($env !== '') {
            return $env;
        }

        $path = self::path();
        if (is_file($path)) {
            $tok = trim((string) file_get_contents($path));
            if ($tok !== '') {
                return $tok;
            }
        }

        $tok = bin2hex(random_bytes(16));

        // storage/app pode não existir num clone fresco — cria antes de gravar.
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        // Falha ALTA: storage não-gravável precisa estourar. Antes o @ engolia a
        // escrita em silêncio — ensure() devolvia o token só em memória e o wizard
        // apontava pra um arquivo que não existe, travando o operador sem erro.
        // (o @ fica só pra suprimir o warning nativo; o sinal é a exceção.)
        if (@file_put_contents($path, $tok . "\n", LOCK_EX) === false) {
            throw new \RuntimeException(
                "InstallToken: falha ao gravar {$path}. Storage não gravável — "
                . 'ajuste permissões (ex.: chmod -R ug+w storage) ou defina '
                . 'INSTALL_TOKEN no .env.'
            );
        }
        @chmod($path, 0600); // best-effort: hardening, não crítico se falhar

        return $tok;
    }

    public static function verify(string $given): bool
    {
        $given = trim($given);
        if ($given === '') {
            return false;
        }

        return hash_equals(self::ensure(), $given);
    }

    /**
     * Remove o token do disco (chamado ao concluir a instalação).
     */
    public static function forget(): void
    {
        $path = self::path();
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
