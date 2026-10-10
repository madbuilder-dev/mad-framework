<?php

namespace Mad\Doc;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mad\Service\MadUploadStorage;

/**
 * Converte o valor de uma coluna de imagem no `src` que o Dompdf imprime
 * (`<mad-doc-dynamic-image>`, `mad_upload_data_uri()`).
 *
 * O Dompdf do documento roda com `isRemoteEnabled` desligado (SSRF) e com
 * `chroot` em `storage/app`; ele só imprimia data URI. Os outros valores que um
 * app grava numa coluna de imagem saíam como o texto alternativo (fw#106):
 *
 *  - chave do disco de uploads (`uploads/x.png`, campo Upload/Imagem com
 *    "Armazenar caminho do arquivo na coluna") — lida do disco do app
 *    (`MadUploadStorage::diskName()`, local ou S3), fora do `chroot`;
 *  - base64 SEM o prefixo `data:` (campo com gravação no banco) — ganha o mime;
 *  - URL `http(s)://` — o APP busca, com as travas de SSRF: só http/https,
 *    nenhum endereço de rede interna (loopback, privado, link-local,
 *    reservado — o IP checado é o usado na conexão), sem seguir
 *    redirecionamento, tempo e tamanho limitados. Host de intranet liberado
 *    por `config('mad.doc.image_private_hosts')`; `mad.doc.remote_images=false`
 *    desliga a busca.
 *
 * Em todos os casos só passa imagem RASTER reconhecida pelos bytes (PNG, JPEG,
 * GIF, WebP) até `mad.doc.image_max_bytes` (padrão 5 MB). Qualquer outra coisa
 * devolve '' (o componente mostra o texto alternativo) — exceto a URL que o app
 * não buscou, que segue crua para o Dompdf como antes. Data URI e caminho
 * absoluto também seguem como antes (o `chroot` do Dompdf é quem barra o
 * caminho). Nunca lança.
 */
class MadDocImage
{
    private const MIMES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    private const DEFAULT_MAX_BYTES = 5 * 1024 * 1024;

    /**
     * URL → [expira, data URI ('' = recusada)]. Um documento que repete a mesma
     * URL (logo numa lista) busca uma vez; curto e limitado para o worker longo
     * não guardar imagem velha nem crescer.
     *
     * @var array<string, array{0: float, 1: string}>
     */
    private static array $remoteMemo = [];

    private const MEMO_TTL = 60.0;
    private const MEMO_MAX = 32;

    public static function src(mixed $value): string
    {
        try {
            $raw = self::firstValue($value);
            if ($raw === '') {
                return '';
            }

            if (str_starts_with($raw, 'data:')) {
                return $raw;
            }
            if (preg_match('#^https?://#i', $raw)) {
                // Não buscada (bloqueada, fora do ar, não é imagem): vai ao
                // Dompdf como antes — com isRemoteEnabled desligado (padrão) sai
                // o texto alternativo; quem ligou à mão mantém o que tinha.
                return self::fromUrl($raw) ?: $raw;
            }
            if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $raw) && ! preg_match('#^[a-zA-Z]:[/\\\\]#', $raw)) {
                return ''; // file:, ftp:, php:, phar:… nunca
            }
            if ($raw[0] === '/' || preg_match('#^[a-zA-Z]:[/\\\\]#', $raw)) {
                return $raw; // caminho absoluto: o chroot do Dompdf decide
            }
            if (($b64 = self::fromBareBase64($raw)) !== '') {
                return $b64;
            }

            return self::fromUpload($raw);
        } catch (\Throwable) {
            return '';
        }
    }

    /** Chave do disco de uploads do app → data URI ('' se não for imagem legível). */
    public static function fromUpload(string $key): string
    {
        $key = ltrim(str_replace('\\', '/', trim($key)), '/');
        if ($key === '' || in_array('..', explode('/', $key), true)) {
            return '';
        }

        try {
            $disk = Storage::disk(MadUploadStorage::diskName());
            if (! $disk->exists($key) || (int) $disk->size($key) > self::maxBytes()) {
                return '';
            }

            return self::dataUri((string) $disk->get($key));
        } catch (\Throwable) {
            return '';
        }
    }

    /** Base64 puro (sem `data:`) cujos bytes são imagem → data URI; senão ''. */
    private static function fromBareBase64(string $raw): string
    {
        $clean = preg_replace('/\s+/', '', $raw);
        if ($clean === '' || strlen($clean) < 16 || preg_match('#^[A-Za-z0-9+/]+={0,2}$#', $clean) !== 1) {
            return '';
        }
        if (strlen($clean) * 3 / 4 > self::maxBytes()) {
            return '';
        }
        $bytes = base64_decode($clean, true);

        return $bytes === false ? '' : self::dataUri($bytes);
    }

    private static function fromUrl(string $url): string
    {
        $now = microtime(true);
        if (isset(self::$remoteMemo[$url]) && self::$remoteMemo[$url][0] > $now) {
            return self::$remoteMemo[$url][1];
        }

        $src = self::fetch($url);
        unset(self::$remoteMemo[$url]);
        if (count(self::$remoteMemo) >= self::MEMO_MAX) {
            array_shift(self::$remoteMemo);
        }
        self::$remoteMemo[$url] = [$now + self::MEMO_TTL, $src];

        return $src;
    }

    private static function fetch(string $url): string
    {
        if (! (bool) config('mad.doc.remote_images', true)) {
            return '';
        }

        $parts = parse_url($url);
        $host  = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        if ($host === '' || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) {
            return '';
        }
        $port = (int) ($parts['port'] ?? (strtolower($parts['scheme']) === 'https' ? 443 : 80));

        $ip = self::resolveAllowedIp($host);
        if ($ip === null) {
            return '';
        }

        $max  = self::maxBytes();
        $pin  = $host . ':' . $port . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip);
        $resp = Http::timeout(8)
            ->connectTimeout(3)
            ->withOptions([
                'allow_redirects' => false,
                'stream'          => true,
                // a conexão vai para o IP que foi checado (sem nova resolução de DNS)
                'curl'            => [CURLOPT_RESOLVE => [$pin]],
            ])
            ->get($url);

        if ($resp->status() !== 200) {
            return '';
        }
        $len = $resp->header('Content-Length');
        if ($len !== '' && (int) $len > $max) {
            return '';
        }

        $body  = $resp->toPsrResponse()->getBody();
        $bytes = '';
        while (! $body->eof()) {
            $bytes .= $body->read(65536);
            if (strlen($bytes) > $max) {
                return '';
            }
        }

        return self::dataUri($bytes);
    }

    /** IP público do host (ou de um host liberado de intranet); null = recusado. */
    private static function resolveAllowedIp(string $host): ?string
    {
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if ($ips === []) {
            return null;
        }

        $private = array_map('strtolower', (array) config('mad.doc.image_private_hosts', []));
        if (in_array($host, $private, true)) {
            return $ips[0];
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false) {
                return null; // um endereço interno basta para recusar
            }
        }

        return $ips[0];
    }

    private static function dataUri(string $bytes): string
    {
        if ($bytes === '' || strlen($bytes) > self::maxBytes()) {
            return '';
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        return in_array($mime, self::MIMES, true) ? 'data:' . $mime . ';base64,' . base64_encode($bytes) : '';
    }

    private static function firstValue(mixed $value): string
    {
        if (is_array($value)) {
            $value = reset($value);
        }
        if (! is_string($value)) {
            return is_scalar($value) ? trim((string) $value) : '';
        }
        $value = trim($value);

        // Upload múltiplo grava a lista em JSON: imprime a primeira imagem.
        if (str_starts_with($value, '[')) {
            $list = json_decode($value, true);
            if (is_array($list)) {
                return self::firstValue($list);
            }
        }

        return $value;
    }

    private static function maxBytes(): int
    {
        return max(1, (int) config('mad.doc.image_max_bytes', self::DEFAULT_MAX_BYTES));
    }

    /** Esvazia o memo das URLs (testes / workers longos). */
    public static function flush(): void
    {
        self::$remoteMemo = [];
    }
}
