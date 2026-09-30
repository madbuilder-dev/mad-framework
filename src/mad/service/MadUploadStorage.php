<?php

namespace Mad\Service;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * MadUploadStorage — ponto único de escrita/leitura dos uploads persistidos
 * (campos de arquivo do MadForm storage="disk", documentos do GED, anexos de
 * chat, modelos de importação), SEMPRE via API de Filesystem do Laravel
 * (facade Storage / Flysystem).
 *
 * O disco vem de config('mad.uploads.disk') (env MAD_UPLOAD_DISK). Vazio ⇒
 * default LOCAL_DISK ('mad_uploads', declarado em config/filesystems.php com
 * root em storage/app/mad — tudo dentro de storage/, convenção Laravel).
 * Qualquer outro disco (s3, MinIO, ...) ⇒ mesmo código, chaves relativas
 * idênticas no banco ("uploads/x.pdf", "files/ged/1/v1_doc.pdf").
 *
 * Serve SEMPRE streamando pelo app (FilesystemAdapter::response) — bucket
 * privado, rotas autenticadas/assinadas; nunca URL direta de storage.
 *
 * ATENÇÃO ('throw' => false): os discos engolem erros do driver retornando
 * false. put()/putContent() LANÇAM RuntimeException nesse caso — senão o path
 * seria gravado no banco sem objeto atrás (falha silenciosa).
 */
final class MadUploadStorage
{
    /**
     * Disco local default — declarado em config/filesystems.php com root em
     * storage/app/mad (tudo dentro de storage/, convenção Laravel).
     */
    public const LOCAL_DISK = 'mad_uploads';

    /** Nome de disco validado (memoizado). */
    private static ?string $diskName = null;

    private static bool $resolved = false;

    /**
     * Nome do disco configurado (env MAD_UPLOAD_DISK), validado — ou
     * LOCAL_DISK quando não configurado / sem container bootado.
     */
    public static function diskName(): string
    {
        if (self::$resolved) {
            return self::$diskName ?? self::LOCAL_DISK;
        }

        $name = null;
        try {
            if (function_exists('app') && app()->bound('config')) {
                $name = config('mad.uploads.disk') ?: null;
            }
        } catch (\Throwable) {
            $name = null; // sem container — disco local default
        }

        if (function_exists('app') && app()->bound('config')) {
            $name = self::projectDisk($name);
        }

        if ($name !== null && $name !== self::LOCAL_DISK) {
            self::validateDisk((string) $name);
        }

        self::$diskName = $name !== null ? (string) $name : self::LOCAL_DISK;
        self::$resolved = true;

        return self::$diskName;
    }

    /** Project-managed S3 is separate from AWS credentials used by mail/queues. */
    private static function projectDisk(?string $name): ?string
    {
        $s3 = (array) config('mad.uploads.s3', []);
        $file = config('mad.uploads.settings_file');
        // Teste Online owns its .env; structural sync overlays a private JSON file.
        // Read outside config cache so changes take effect on the next request.
        if (is_string($file) && is_file($file)) {
            $vars = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            $name = $vars['MAD_UPLOAD_DISK'] ?? null;
            if (!in_array($name, [self::LOCAL_DISK, 'mad_s3'], true)) {
                throw new \RuntimeException('Configuração de armazenamento inválida.');
            }
            $s3 = [
                'driver' => 's3',
                'key' => $vars['MAD_UPLOAD_S3_KEY'] ?? null,
                'secret' => $vars['MAD_UPLOAD_S3_SECRET'] ?? null,
                'region' => $vars['MAD_UPLOAD_S3_REGION'] ?? null,
                'bucket' => $vars['MAD_UPLOAD_S3_BUCKET'] ?? null,
                'endpoint' => ($vars['MAD_UPLOAD_S3_ENDPOINT'] ?? '') ?: null,
                'use_path_style_endpoint' => ($vars['MAD_UPLOAD_S3_PATH_STYLE'] ?? 'false') === 'true',
                'visibility' => 'private', 'throw' => true, 'report' => false,
            ];
        }
        if ($name === 'mad_s3') {
            if (empty($s3['bucket']) || empty($s3['region']) || empty($s3['key']) || empty($s3['secret'])) {
                throw new \RuntimeException('Configure bucket, região e credenciais nas propriedades do projeto.');
            }
            config(['filesystems.disks.mad_s3' => $s3]);
            Storage::forgetDisk('mad_s3');
            self::validateDisk('mad_s3');
            // Bucket policies govern access; do not send legacy object ACLs.
            Storage::disk('mad_s3')->getClient()->getHandlerList()->appendInit(\Aws\Middleware::mapCommand(function ($command) {
                unset($command['ACL']);
                return $command;
            }), 'mad-upload-no-acl');
        }

        return $name;
    }

    /** Há um disco CUSTOM configurado (MAD_UPLOAD_DISK ≠ local default)? */
    public static function usesCustomDisk(): bool
    {
        return self::diskName() !== self::LOCAL_DISK;
    }

    /** Limpa a memoização (testes trocam config('mad.uploads.disk') em runtime). */
    public static function flush(): void
    {
        self::$diskName = null;
        self::$resolved = false;
    }

    /**
     * Persiste um arquivo (tmp de upload ou arquivo comum) no destino relativo
     * do disco de uploads, via writeStream (memória constante).
     *
     * Lança RuntimeException em falha de escrita (discos têm 'throw' => false —
     * erro de credencial S3 viria como false e gravaria path sem objeto). O tmp
     * só é consumido (@unlink) quando é upload HTTP real — paridade com
     * move_uploaded_file; fixtures de teste sobrevivem.
     */
    public static function put(string $tmpPath, string $relPath): bool
    {
        if ($tmpPath === '' || !is_file($tmpPath)) {
            return false;
        }

        $stream = @fopen($tmpPath, 'rb');
        if ($stream === false) {
            throw new \RuntimeException("Não foi possível ler o arquivo de upload: {$tmpPath}");
        }

        $fromRealUpload = is_uploaded_file($tmpPath);

        try {
            $ok = self::disk()->writeStream($relPath, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($ok === false) {
            throw new \RuntimeException(
                "Falha ao gravar '{$relPath}' no disco de uploads '" . self::diskName() . "' — verifique credenciais/bucket."
            );
        }

        if ($fromRealUpload) {
            @unlink($tmpPath); // consome o tmp, como move_uploaded_file faria
        }

        return true;
    }

    /** Grava conteúdo gerado em memória (ex.: CSV modelo). Lança em falha. */
    public static function putContent(string $relPath, string $contents): void
    {
        if (self::disk()->put($relPath, $contents) === false) {
            throw new \RuntimeException(
                "Falha ao gravar '{$relPath}' no disco de uploads '" . self::diskName() . "' — verifique credenciais/bucket."
            );
        }
    }

    /** Objeto existe no disco de uploads? */
    public static function exists(string $relPath): bool
    {
        return self::disk()->exists($relPath);
    }

    /** Remove o arquivo do disco de uploads (idempotente; false engolido). */
    public static function delete(string $relPath): void
    {
        if ($relPath === '') {
            return;
        }

        self::disk()->delete($relPath);
    }

    /** Remove um diretório/prefixo inteiro (prune/trash do GED). */
    public static function deleteDirectory(string $relDir): void
    {
        if ($relDir === '') {
            return;
        }

        self::disk()->deleteDirectory($relDir);
    }

    /**
     * StreamedResponse do objeto no disco de uploads (readStream/fpassthru —
     * memória constante; nunca get()).
     *
     * Passe SEMPRE Content-Type explícito nos $headers (derivado da extensão
     * pelo caller — nunca confiar em metadata remota) e Content-Length quando
     * conhecido (evita um HEAD extra no S3).
     */
    public static function response(
        string $relPath,
        ?string $name = null,
        array $headers = [],
        string $disposition = 'inline'
    ): StreamedResponse {
        return self::disk()->response($relPath, $name, $headers, $disposition);
    }

    private static function disk(): FilesystemAdapter
    {
        return Storage::disk(self::diskName());
    }

    /** Falha cedo e com mensagem clara em misconfiguração — na 1ª chamada. */
    private static function validateDisk(string $name): void
    {
        $disks = (array) config('filesystems.disks', []);
        if (array_key_exists($name, $disks)) {
            $driver = (string) ($disks[$name]['driver'] ?? '');
            if ($driver === 's3' && !class_exists(\League\Flysystem\AwsS3V3\AwsS3V3Adapter::class)) {
                throw new \RuntimeException(
                    "O disco de uploads '{$name}' usa driver s3, mas o adapter não está instalado. "
                    . 'Rode: composer require league/flysystem-aws-s3-v3'
                );
            }

            return;
        }

        // Sem entrada em filesystems.disks — ainda pode ser um disco injetado
        // em runtime no manager (Storage::fake em testes). Se nem o manager
        // resolve, falha claro.
        try {
            Storage::disk($name);
        } catch (\Throwable) {
            throw new \RuntimeException(
                "MAD_UPLOAD_DISK aponta para disco inexistente em config/filesystems.php: '{$name}'."
            );
        }
    }
}
