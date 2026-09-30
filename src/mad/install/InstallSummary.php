<?php

declare(strict_types=1);

namespace Mad\Install;

/**
 * InstallSummary — resumo durável da instalação web (mad-install, issue #66).
 *
 * Persiste um JSON curto (nome do app, banco, login/email do admin + passos) ao
 * concluir a instalação, ANTES do selo. Alimenta a landing idempotente
 * `GET /install/done`: assim um refresh/retorno pós-install ainda mostra a
 * confirmação em vez de cair direto no login sem feedback (problema 2 da issue).
 *
 * NUNCA guarda segredo (sem senha). Caminho overridável por config
 * (`mad.install.summary_path`) — os testes apontam pra um arquivo temporário.
 * Escrita atômica (temp + rename) pra nunca deixar um JSON truncado.
 */
final class InstallSummary
{
    public static function path(): string
    {
        $override = config('mad.install.summary_path');

        return $override ? (string) $override : storage_path('mad-install-done.json');
    }

    /** @param array<string,mixed> $summary */
    public static function save(array $summary): void
    {
        $path = self::path();
        $json = (string) json_encode(
            $summary,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        $tmp = $path . '.tmp' . bin2hex(random_bytes(4));
        file_put_contents($tmp, $json, LOCK_EX);
        @chmod($tmp, 0644);
        rename($tmp, $path);
    }

    /** @return array<string,mixed>|null */
    public static function read(): ?array
    {
        $path = self::path();
        if (!is_file($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }

    public static function exists(): bool
    {
        return is_file(self::path());
    }

    public static function forget(): void
    {
        $path = self::path();
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
