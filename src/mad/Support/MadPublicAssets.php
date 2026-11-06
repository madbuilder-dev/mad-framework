<?php

namespace Mad\Support;

/**
 * Cópias servidas dos assets do framework (o CSS/JS que o navegador baixa de
 * public/) em dia com o pacote instalado.
 *
 * O card "Mad Framework" da Central de Comando atualiza só o vendor/. As
 * cópias servidas (mad-ui.css, mad-ui.js, lib/mad/*.js…) só eram refeitas pelo
 * composer (post-autoload-dump → scripts/sync-mad-assets.php). Num app em
 * servidor próprio atualizado pelo card, o `?fwver=` subia e o arquivo servido
 * continuava o da instalação: correção visual nenhuma chegava ao navegador
 * (fórum #242, 5.273.1). O código do card é do app e não muda com o update, por
 * isso a correção mora no pacote: no primeiro request web com versão nova, o
 * grupo 'package' do asset-map é recopiado.
 *
 * O 'served_copies' fica de fora: a fonte dele é do app (app/lib/**), que o
 * card não toca, e segue a cargo do `composer mad:sync`.
 */
final class MadPublicAssets
{
    /**
     * Mapa do grupo 'package' em caminhos absolutos (fonte => destino). As
     * chaves do asset-map são relativas a um app com path repo
     * ("packages/mad-framework/assets/…"); a fonte é lida da raiz real do pacote.
     *
     * @return array<string, string>
     */
    public static function packageMap(string $basePath, string $packageRoot): array
    {
        $basePath    = rtrim($basePath, '/');
        $packageRoot = rtrim($packageRoot, '/');
        $mapFile     = $packageRoot . '/assets/asset-map.php';
        if (! is_file($mapFile)) {
            return [];
        }
        $map = require $mapFile;

        $out = [];
        foreach (($map['package'] ?? []) as $srcRel => $dstRel) {
            $src = str_starts_with($srcRel, 'packages/mad-framework/')
                ? $packageRoot . '/' . substr($srcRel, strlen('packages/mad-framework/'))
                : $basePath . '/' . $srcRel;
            $out[$src] = $basePath . '/' . $dstRel;
        }

        return $out;
    }

    /**
     * Recopia as cópias servidas quando a versão do pacote mudou desde a última
     * vez. Com a versão já marcada, custa a leitura de um arquivo de 10 bytes.
     * Só copia o que difere; escrita atômica (tmp + rename) porque o servidor
     * web pode estar entregando o arquivo no mesmo instante.
     *
     * A marca é gravada mesmo com falha de escrita (public/ sem permissão):
     * sem ela, todo request voltaria a comparar os arquivos. Quem chama registra
     * o aviso com o comando manual.
     *
     * @return array{synced: bool, copied: list<string>, failed: list<string>}
     */
    public static function ensureFresh(string $basePath, string $packageRoot, string $version, string $stampFile): array
    {
        $result = ['synced' => false, 'copied' => [], 'failed' => []];
        if ($version === '0.0.0-unknown' || preg_match('/^\d+\.\d+\.\d+/', $version) !== 1) {
            return $result; // pacote sem VERSION (MadFramework::version()): nada para comparar
        }
        if (is_file($stampFile) && trim((string) @file_get_contents($stampFile)) === $version) {
            return $result;
        }

        $stampDir = dirname($stampFile);
        if (! is_dir($stampDir) && ! @mkdir($stampDir, 0775, true) && ! is_dir($stampDir)) {
            return $result; // sem onde marcar: não compara a cada request
        }
        // Um request por vez: os que chegam juntos seguem com o arquivo atual.
        $lock = @fopen($stampFile . '.lock', 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock !== false) {
                fclose($lock);
            }

            return $result;
        }

        try {
            $base = rtrim($basePath, '/') . '/';
            foreach (self::packageMap($basePath, $packageRoot) as $src => $dst) {
                if (! is_file($src) || self::same($src, $dst)) {
                    continue;
                }
                $rel = str_starts_with($dst, $base) ? substr($dst, strlen($base)) : $dst;
                $dir = dirname($dst);
                if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
                    $result['failed'][] = $rel;
                    continue;
                }
                $tmp = $dst . '.madtmp-' . bin2hex(random_bytes(4));
                if (@copy($src, $tmp) && @rename($tmp, $dst)) {
                    $result['copied'][] = $rel;
                } else {
                    @unlink($tmp);
                    $result['failed'][] = $rel;
                }
            }

            $tmpStamp = $stampFile . '.tmp-' . bin2hex(random_bytes(4));
            if (@file_put_contents($tmpStamp, $version . "\n") !== false) {
                @rename($tmpStamp, $stampFile);
            }
            @unlink($tmpStamp);
            $result['synced'] = true;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $result;
    }

    private static function same(string $src, string $dst): bool
    {
        return is_file($dst)
            && filesize($src) === filesize($dst)
            && hash_file('xxh128', $src) === hash_file('xxh128', $dst);
    }
}
