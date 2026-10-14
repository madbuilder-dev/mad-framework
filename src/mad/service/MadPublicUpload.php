<?php

namespace Mad\Service;

/**
 * MadPublicUpload — arquivo PÚBLICO de upload (mad-framework#244).
 *
 * Um campo de arquivo com `public` (`<mad-image-field public>`, também
 * file/avatar/multi-file/signature) grava em
 *
 *     _public/<pasta>/<hmac>/<nome>
 *
 * e o arquivo passa a ter endereço fixo, sem login: `/arquivos/<caminho>`
 * ({@see url()}, helper `mad_public_url()`), para página do site, e-mail e
 * WhatsApp.
 *
 * O `<hmac>` (24 hex, HMAC-SHA256 do caminho SEM ele, com a chave do app) é o
 * que prova que o arquivo foi gravado por um campo público. A rota pública só
 * serve caminho sob `_public/` cujo HMAC confere: nenhum arquivo que já
 * existia — nem um gravado em `_public/` por outro caminho — vira público. O
 * `folder=` dos campos não escreve em `_public` ({@see \Mad\Form\MadUploadPath}).
 * Ele fica num segmento próprio (e não no nome) para o nome do arquivo seguir
 * igual: quem mostra o rótulo tira só o prefixo aleatório de sempre.
 *
 * Chave: `mad.uploads.public_key` (env `MAD_UPLOAD_PUBLIC_KEY`) ou, sem ela, o
 * `APP_KEY`. Trocar o `APP_KEY` invalida os endereços públicos já gravados —
 * para mantê-los, ponha a chave ANTIGA em `MAD_UPLOAD_PUBLIC_KEY`.
 */
final class MadPublicUpload
{
    public const PREFIX = '_public';

    /** Tipos servidos pela rota pública, sempre inline, com o MIME desta lista. */
    public const MIME = [
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'pdf'  => 'application/pdf',
    ];

    private const MAC_LEN = 24;

    /** Quantos bytes do início bastam para reconhecer os tipos de MIME. */
    public const SNIFF_BYTES = 16;

    /** Caminho público de um arquivo novo: `_public/<pasta>/<hmac>/<nome>`. */
    public static function path(string $folder, string $fileName): string
    {
        $base = self::PREFIX . '/' . trim($folder, '/');

        return $base . '/' . self::mac($base . '/' . $fileName) . '/' . $fileName;
    }

    /** O caminho foi gravado por um campo público (HMAC confere)? */
    public static function isPublicPath(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\') || str_contains($path, '..')) {
            return false;
        }
        $segs = explode('/', $path);
        $n    = count($segs);
        if ($n < 4 || $segs[0] !== self::PREFIX || in_array('', $segs, true)) {
            return false;
        }
        $mac = $segs[$n - 2];
        if (strlen($mac) !== self::MAC_LEN || !ctype_xdigit($mac)) {
            return false;
        }
        unset($segs[$n - 2]);

        try {
            return hash_equals(self::mac(implode('/', $segs)), $mac);
        } catch (\Throwable) {
            return false;
        }
    }

    /** O tipo do arquivo é servido pela rota pública? (MIME ou null) */
    public static function mimeFor(string $path): ?string
    {
        return self::MIME[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? null;
    }

    /**
     * O tipo pelo CONTEÚDO (assinatura dos primeiros bytes): um dos tipos de
     * MIME, ou null. É o que decide se o arquivo pode ser público e com que
     * tipo é servido — a extensão quem escolhe é quem envia, e um `fake.png`
     * com HTML dentro não pode virar página no domínio do app.
     */
    public static function sniff(string $head): ?string
    {
        return match (true) {
            str_starts_with($head, "\x89PNG\r\n\x1a\n")                                  => 'image/png',
            str_starts_with($head, "\xFF\xD8\xFF")                                         => 'image/jpeg',
            str_starts_with($head, 'GIF87a'), str_starts_with($head, 'GIF89a')             => 'image/gif',
            str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP'               => 'image/webp',
            str_starts_with($head, '%PDF-')                                                => 'application/pdf',
            default                                                                        => null,
        };
    }

    /** O tipo pelo conteúdo de um arquivo local (o temporário do envio). */
    public static function sniffFile(string $localPath): ?string
    {
        if ($localPath === '' || !is_file($localPath)) {
            return null;
        }
        $head = @file_get_contents($localPath, false, null, 0, self::SNIFF_BYTES);

        return is_string($head) ? self::sniff($head) : null;
    }

    /**
     * O arquivo enviado pode ter endereço público? Extensão E conteúdo na
     * lista (png/jpg/gif/webp/pdf) — os dois, senão o arquivo é recusado.
     */
    public static function acceptsUpload(string $fileName, string $localPath): bool
    {
        return self::mimeFor($fileName) !== null && self::sniffFile($localPath) !== null;
    }

    /** Endereço público (`/arquivos/<caminho>`), absoluto por padrão (e-mail, WhatsApp). */
    public static function url(string $path, bool $absolute = true): string
    {
        try {
            if (function_exists('app') && app()->bound('url')) {
                return route('mad.public-file', ['path' => $path], $absolute);
            }
        } catch (\Throwable) {
            // sem rota (render avulso) — cai no literal
        }

        return '/arquivos/' . implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    private static function mac(string $path): string
    {
        return substr(hash_hmac('sha256', 'mad-public-upload|' . $path, self::key()), 0, self::MAC_LEN);
    }

    private static function key(): string
    {
        $key = '';
        if (function_exists('config')) {
            try {
                $key = (string) (config('mad.uploads.public_key') ?: config('app.key') ?: '');
            } catch (\Throwable) {
                $key = '';
            }
        }
        if ($key === '') {
            // Sem chave nenhuma, nada é público (o HMAC não teria segredo).
            throw new \RuntimeException('Arquivo público de upload: o app não tem APP_KEY.');
        }

        return $key;
    }
}
