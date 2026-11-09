<?php

namespace Mad\Service;

/**
 * MadImageOptimizer — reduz a imagem enviada ANTES de ela ir para o disco
 * (fórum #269): dimensão máxima e qualidade declaradas no campo
 * (`image-max-dimension` e `image-quality`). A imagem original grande não é
 * guardada: o que vai para o disco é o resultado.
 *
 * Só mexe no que pode mexer sem estragar:
 *  - JPEG, PNG e WebP estáticos. GIF, SVG, BMP, WebP/PNG animados e JPEG CMYK
 *    seguem como chegaram;
 *  - nunca amplia: imagem menor que a dimensão máxima não muda de tamanho;
 *  - mantém a proporção; `image-max-dimension` vale para o MAIOR lado, já com
 *    a orientação da câmera (EXIF) aplicada;
 *  - a orientação EXIF (foto de celular de lado) é aplicada nos pixels, porque
 *    o arquivo regravado perde o EXIF;
 *  - PNG continua sem perda (a qualidade não vale para ele; só a dimensão) e
 *    mantém a transparência;
 *  - sem `image-max-dimension`, só recomprime se o resultado ficar MENOR que o
 *    original — recomprimir não pode engordar o arquivo;
 *  - foto grande demais para a memória do PHP não derruba o envio: segue como
 *    chegou e o motivo vai para o log.
 *
 * O perfil de cor (ICC) e os metadados (EXIF, GPS) saem junto com a regravação.
 */
final class MadImageOptimizer
{
    /** Qualidade usada quando só a dimensão foi declarada. */
    public const DEFAULT_QUALITY = 85;

    /** Teto da dimensão máxima (px): acima disso o valor é cortado aqui. */
    public const MAX_DIMENSION = 20000;

    /** Folga de memória além do que as imagens ocupam (buffers do GD/libjpeg). */
    private const MEMORY_SLACK = 8 * 1024 * 1024;

    /**
     * As opções declaradas no campo, ou null quando não há nada a fazer.
     *
     * @param array<string,mixed> $props props do campo (`imageMaxDimension`, `imageQuality`)
     * @return array{maxDimension:int,quality:int}|null
     */
    public static function options(array $props): ?array
    {
        $dimension = self::number($props['imageMaxDimension'] ?? null, self::MAX_DIMENSION);
        $quality   = self::number($props['imageQuality'] ?? null, 100);

        return ($dimension === 0 && $quality === 0) ? null : ['maxDimension' => $dimension, 'quality' => $quality];
    }

    /**
     * Otimiza o arquivo. `path` é o arquivo a gravar: um temporário novo
     * quando `changed` (quem pediu apaga depois), ou o próprio `$tmpPath`.
     *
     * @param array{maxDimension:int,quality:int} $opts
     * @return array{path:string,size:int,changed:bool}
     */
    public static function optimize(string $tmpPath, array $opts): array
    {
        $unchanged = ['path' => $tmpPath, 'size' => is_file($tmpPath) ? (int) filesize($tmpPath) : 0, 'changed' => false];

        if (! is_file($tmpPath) || ! function_exists('imagecreatetruecolor')) {
            return $unchanged;
        }

        $info = @getimagesize($tmpPath);
        if ($info === false) {
            return $unchanged;                       // não é imagem de raster (SVG, PDF, lixo)
        }
        $type = (int) $info[2];
        if (! in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)
            || ($type === IMAGETYPE_JPEG && (int) ($info['channels'] ?? 3) > 3)
            || self::isAnimated($tmpPath, $type)) {
            return $unchanged;
        }

        $width  = (int) $info[0];
        $height = (int) $info[1];
        if ($width < 1 || $height < 1) {
            return $unchanged;
        }

        $orientation = $type === IMAGETYPE_JPEG ? self::jpegOrientation($tmpPath) : 1;
        [$shownW, $shownH] = $orientation >= 5 ? [$height, $width] : [$width, $height];

        $limit  = $opts['maxDimension'];
        $resize = $limit > 0 && max($shownW, $shownH) > $limit;
        $lossy  = $type !== IMAGETYPE_PNG && $opts['quality'] > 0;
        if (! $resize && ! $lossy) {
            return $unchanged;                       // nada a reduzir
        }

        $newW = $shownW;
        $newH = $shownH;
        if ($resize) {
            $scale = $limit / max($shownW, $shownH);
            $newW  = max(1, (int) round($shownW * $scale));
            $newH  = max(1, (int) round($shownH * $scale));
        }

        $need = $width * $height * 4 * ($orientation > 1 ? 2 : 1) + $newW * $newH * 4 + self::MEMORY_SLACK;
        $restore = self::reserveMemory($need);
        if ($restore === false) {
            error_log('[MadImageOptimizer] imagem de ' . $width . 'x' . $height . ' grande demais para a memória do PHP: gravada sem otimizar.');

            return $unchanged;
        }

        $out = null;
        try {
            $image = match ($type) {
                IMAGETYPE_JPEG => @imagecreatefromjpeg($tmpPath),
                IMAGETYPE_PNG  => @imagecreatefrompng($tmpPath),
                default        => @imagecreatefromwebp($tmpPath),
            };
            if ($image === false) {
                return $unchanged;
            }
            if (! imageistruecolor($image)) {
                imagepalettetotruecolor($image);
            }

            $image = self::applyOrientation($image, $orientation);
            if ($resize) {
                $image = self::scale($image, $newW, $newH, $type !== IMAGETYPE_JPEG);
            }
            if ($image === false) {
                return $unchanged;
            }

            $out = (string) tempnam(sys_get_temp_dir(), 'madimg');
            $quality = $opts['quality'] > 0 ? $opts['quality'] : self::DEFAULT_QUALITY;
            $ok = match ($type) {
                IMAGETYPE_JPEG => imagejpeg($image, $out, $quality),
                IMAGETYPE_WEBP => imagewebp($image, $out, $quality),
                default        => (function () use ($image, $out): bool {
                    imagesavealpha($image, true);

                    return imagepng($image, $out, 9);
                })(),
            };
            $image = null;

            clearstatcache(true, $out);
            $size = $ok ? (int) filesize($out) : 0;
            // Sem reduzir a dimensão, recomprimir só vale se o arquivo emagreceu
            // (e a foto de lado, com EXIF, continua como estava).
            if ($size <= 0 || (! $resize && $size >= $unchanged['size'])) {
                @unlink($out);

                return $unchanged;
            }

            return ['path' => $out, 'size' => $size, 'changed' => true];
        } catch (\Throwable $e) {
            if (is_string($out)) {
                @unlink($out);
            }
            error_log('[MadImageOptimizer] ' . $e->getMessage());

            return $unchanged;
        } finally {
            if (is_string($restore)) {
                @ini_set('memory_limit', $restore);
            }
        }
    }

    /** Inteiro de 1 a `$max` (cortando o que passa); 0 quando vazio, não numérico ou menor que 1. */
    private static function number(mixed $value, int $max): int
    {
        if (is_string($value)) {
            $value = trim($value);
        }
        if ($value === null || $value === '' || is_bool($value) || ! is_numeric($value)) {
            return 0;
        }
        $n = (int) round((float) $value);

        return $n < 1 ? 0 : min($n, $max);
    }

    /**
     * Imagem animada (PNG com `acTL`, WebP com a marca de animação): o GD lê só
     * o primeiro quadro e regravar mataria a animação. Exposta para teste.
     */
    public static function isAnimated(string $file, int $type): bool
    {
        $fh = @fopen($file, 'rb');
        if ($fh === false) {
            return false;
        }

        try {
            if ($type === IMAGETYPE_WEBP) {
                $head = (string) fread($fh, 32);

                return strlen($head) >= 21 && substr($head, 12, 4) === 'VP8X' && (ord($head[20]) & 0x02) === 0x02;
            }
            if ($type === IMAGETYPE_PNG) {
                fseek($fh, 8);
                while (! feof($fh)) {
                    $header = (string) fread($fh, 8);
                    if (strlen($header) < 8) {
                        break;
                    }
                    $name = substr($header, 4, 4);
                    if ($name === 'acTL') {
                        return true;
                    }
                    if ($name === 'IDAT' || $name === 'IEND') {
                        break;
                    }
                    $length = unpack('N', substr($header, 0, 4));
                    fseek($fh, ((int) ($length[1] ?? 0)) + 4, SEEK_CUR);
                }
            }

            return false;
        } finally {
            fclose($fh);
        }
    }

    /** Orientação EXIF (1 a 8) de um JPEG; 1 quando não tem, ou não dá para ler. */
    public static function jpegOrientation(string $file): int
    {
        $data = @file_get_contents($file, false, null, 0, 65536);
        if (! is_string($data) || ! str_starts_with($data, "\xFF\xD8")) {
            return 1;
        }

        $pos = 2;
        $len = strlen($data);
        while ($pos + 4 <= $len && $data[$pos] === "\xFF") {
            $marker = ord($data[$pos + 1]);
            if ($marker === 0xD8 || $marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                $pos += 2;
                continue;
            }
            if ($marker === 0xDA) {
                break;                               // começa a imagem: o EXIF já passou
            }
            $segment = unpack('n', substr($data, $pos + 2, 2));
            $size    = (int) ($segment[1] ?? 0);
            if ($marker === 0xE1 && substr($data, $pos + 4, 6) === "Exif\0\0") {
                return self::tiffOrientation(substr($data, $pos + 10, max(0, $size - 8)));
            }
            if ($size < 2) {
                break;
            }
            $pos += 2 + $size;
        }

        return 1;
    }

    private static function tiffOrientation(string $tiff): int
    {
        $order = substr($tiff, 0, 2);
        if (strlen($tiff) < 8 || ($order !== 'II' && $order !== 'MM')) {
            return 1;
        }
        $little = $order === 'II';
        $u16 = static function (int $offset) use ($tiff, $little): int {
            $v = strlen($tiff) >= $offset + 2 ? unpack($little ? 'v' : 'n', substr($tiff, $offset, 2)) : false;

            return $v === false ? 0 : (int) $v[1];
        };
        $u32 = static function (int $offset) use ($tiff, $little): int {
            $v = strlen($tiff) >= $offset + 4 ? unpack($little ? 'V' : 'N', substr($tiff, $offset, 4)) : false;

            return $v === false ? 0 : (int) $v[1];
        };

        $ifd = $u32(4);
        $count = $u16($ifd);
        for ($i = 0; $i < $count && $ifd + 2 + ($i + 1) * 12 <= strlen($tiff); $i++) {
            $entry = $ifd + 2 + $i * 12;
            if ($u16($entry) === 0x0112) {
                $value = $u16($entry + 8);

                return ($value >= 1 && $value <= 8) ? $value : 1;
            }
        }

        return 1;
    }

    /**
     * Gira/espelha os pixels para a orientação EXIF `$orientation` ficar de pé.
     * Exposta para teste.
     */
    public static function applyOrientation(\GdImage $image, int $orientation): \GdImage
    {
        $result = match ($orientation) {
            2 => self::flipped($image, IMG_FLIP_HORIZONTAL),
            3 => imagerotate($image, 180, 0),
            4 => self::flipped($image, IMG_FLIP_VERTICAL),
            5 => imagerotate(self::flipped($image, IMG_FLIP_VERTICAL), 270, 0),
            6 => imagerotate($image, 270, 0),
            7 => imagerotate(self::flipped($image, IMG_FLIP_VERTICAL), 90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        return $result instanceof \GdImage ? $result : $image;
    }

    private static function flipped(\GdImage $image, int $mode): \GdImage
    {
        imageflip($image, $mode);

        return $image;
    }

    /** Reamostra para `$w` x `$h`, com a transparência quando o formato tem. */
    private static function scale(\GdImage $src, int $w, int $h, bool $alpha): \GdImage|false
    {
        $dst = imagecreatetruecolor($w, $h);
        if ($dst === false) {
            return false;
        }
        if ($alpha) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, (int) imagecolorallocatealpha($dst, 0, 0, 0, 127));
        }
        if (! imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, imagesx($src), imagesy($src))) {
            return false;
        }

        return $dst;
    }

    /**
     * Garante memória para `$need` bytes: sobe o limite do PHP por conta da
     * operação, quando precisa e pode. Devolve true (já cabia), o limite
     * anterior em texto (subiu; quem chamou restaura) ou false (não cabe).
     */
    private static function reserveMemory(int $need): string|bool
    {
        $current = (string) ini_get('memory_limit');
        $limit   = self::bytes($current);
        if ($limit < 0) {
            return true;                             // sem limite
        }
        $used = memory_get_usage(true);
        if ($need <= $limit - $used) {
            return true;
        }

        $wanted = $used + $need + self::MEMORY_SLACK;
        if (@ini_set('memory_limit', (string) $wanted) === false) {
            return false;
        }

        return self::bytes((string) ini_get('memory_limit')) >= $wanted ? $current : false;
    }

    /** `128M`, `2G`, `512K`, `-1`, `134217728` → bytes (-1 = sem limite). */
    private static function bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }
        $unit = strtolower(substr($value, -1));
        $n    = (int) $value;

        return match ($unit) {
            'g'     => $n * 1024 * 1024 * 1024,
            'm'     => $n * 1024 * 1024,
            'k'     => $n * 1024,
            default => $n,
        };
    }
}
