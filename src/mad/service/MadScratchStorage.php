<?php

namespace Mad\Service;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;

/**
 * MadScratchStorage — arquivos de TRABALHO transientes do framework, via API
 * de Filesystem do Laravel (disco fixo 'mad_tmp', root storage/app/mad-tmp).
 *
 * Prefixos de chave:
 *   - mad_uploads/  → staging de $_FILES pelo MadComponentHandler (pré-persistência)
 *   - import/       → cópia de trabalho do CSV de importação de dados
 *   - output/       → exports da grade (CSV/XLSX/PDF), purgados por mad:grid:purge-exports
 *   - blob/         → scratch de edição do MadForm::loadBlob (BLOB do banco → arquivo)
 *
 * SEMPRE local (nunca segue MAD_UPLOAD_DISK): é scratch por-request/por-nó —
 * mandar staging pro S3 só adicionaria latência e lixo remoto. Uploads
 * PERSISTIDOS são responsabilidade do MadUploadStorage.
 */
final class MadScratchStorage
{
    public const DISK = 'mad_tmp';

    /**
     * Permissões do scratch: sem "outros", com o GRUPO (builder#682). O padrão
     * do Laravel para pasta privada é 0700 — a pasta ficava só do usuário do
     * servidor web e o backup do Deploy SSH (usuário de deploy, no grupo do
     * servidor web) parava em "storage/app/mad-tmp: Permission denied".
     */
    public const PERMISSIONS = [
        'file' => ['public' => 0664, 'private' => 0660],
        'dir'  => ['public' => 0775, 'private' => 0770],
    ];

    /** O disco de scratch — use direto para readStream/copy/files/etc. */
    public static function disk(): FilesystemAdapter
    {
        // App com o config/filesystems.php de antes da 5.325 (o disco sem
        // `permissions`): vale o mesmo, antes da 1ª resolução do disco.
        $key = 'filesystems.disks.' . self::DISK;
        if (is_array(config($key)) && config($key . '.permissions') === null) {
            config([$key . '.permissions' => self::PERMISSIONS]);
        }

        return Storage::disk(self::DISK);
    }

    /**
     * Move um upload de $_FILES pro scratch (staging). Consome o tmp do PHP
     * quando é upload HTTP real (paridade com move_uploaded_file).
     */
    public static function putUploaded(string $phpTmpPath, string $key): bool
    {
        if ($phpTmpPath === '' || !is_file($phpTmpPath)) {
            return false;
        }

        $stream = @fopen($phpTmpPath, 'rb');
        if ($stream === false) {
            return false;
        }

        $fromRealUpload = is_uploaded_file($phpTmpPath);

        try {
            $ok = self::disk()->writeStream($key, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($ok !== false && $fromRealUpload) {
            @unlink($phpTmpPath);
        }

        return $ok !== false;
    }

    /** Grava conteúdo gerado em memória. Lança em falha (throw=false no disco). */
    public static function putContent(string $key, string $contents): void
    {
        if (self::disk()->put($key, $contents) === false) {
            throw new \RuntimeException("Falha ao gravar scratch '{$key}' (disco " . self::DISK . ').');
        }
    }

    public static function exists(string $key): bool
    {
        return self::disk()->exists($key);
    }

    public static function delete(string $key): void
    {
        if ($key !== '') {
            self::disk()->delete($key);
        }
    }

    /**
     * Chave de scratch com forma válida sob um prefixo esperado (sem
     * traversal/null byte/backslash/segmento oculto). Validação string-level —
     * roda ANTES de qualquer acesso a disco.
     */
    public static function isValidKey(string $key, string $prefix): bool
    {
        if (!str_starts_with($key, rtrim($prefix, '/') . '/')) {
            return false;
        }
        if (str_contains($key, "\0") || str_contains($key, '\\') || str_contains($key, '..')) {
            return false;
        }
        foreach (explode('/', $key) as $segment) {
            if ($segment === '' || $segment[0] === '.') {
                return false;
            }
        }

        return true;
    }
}
