<?php

namespace Mad\Mcp;

/**
 * McpManifestLoader
 *
 * Le o `mcp.config.json` (fonte primaria: arquivo no projeto gerado).
 * Hot-reload por versao: mantem cache em memoria por processo e recarrega
 * quando o mtime/versao do arquivo muda.
 *
 * Localizacao do arquivo (em ordem):
 *   1. config/mad.php  [mcp] manifest_path
 *   2. base_path()/mcp.config.json
 *   3. base_path()/app/config/mcp.config.json
 */
final class McpManifestLoader
{
    private static ?McpManifest $cache = null;
    private static ?string $cacheKey = null;

    /** Manifest fixo (testes): vence o arquivo até {@see flush()}. */
    private static ?McpManifest $override = null;

    public static function load(): McpManifest
    {
        if (self::$override !== null) {
            return self::$override;
        }

        $path = self::resolvePath();
        if ($path === null || ! is_file($path)) {
            throw new \RuntimeException('mcp.config.json nao encontrado. Esperado em ' . self::expectedHint());
        }

        $key = $path . ':' . (string) @filemtime($path);
        if (self::$cache !== null && self::$cacheKey === $key) {
            return self::$cache;
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Falha ao ler {$path}");
        }

        $data = json_decode($raw, true);
        if (! is_array($data)) {
            throw new \RuntimeException("mcp.config.json invalido (JSON): {$path}");
        }

        self::$cache    = McpManifest::fromArray($data);
        self::$cacheKey = $key;

        return self::$cache;
    }

    /** Limpa o cache (testes / republish) — e o manifest fixo de teste. */
    public static function flush(): void
    {
        self::$cache    = null;
        self::$cacheKey = null;
        self::$override = null;
    }

    /** Testes: usa este manifest no lugar do arquivo (null = volta ao arquivo). */
    public static function useManifest(?McpManifest $manifest): void
    {
        self::$override = $manifest;
    }

    private static function resolvePath(): ?string
    {
        $base = self::basePath();

        $ini = \Mad\Core\AppConfig::get();
        $configured = $ini['mcp']['manifest_path'] ?? null;
        if (! empty($configured)) {
            return self::absolutize((string) $configured, $base);
        }

        $candidates = [
            $base . '/mcp.config.json',
            $base . '/app/config/mcp.config.json',
        ];
        foreach ($candidates as $c) {
            if (is_file($c)) {
                return $c;
            }
        }

        return $candidates[0];
    }

    private static function absolutize(string $path, string $base): string
    {
        if ($path[0] === '/' || preg_match('#^[A-Za-z]:[\\\\/]#', $path)) {
            return $path;
        }

        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }

    private static function basePath(): string
    {
        if (function_exists('base_path')) {
            try {
                return base_path();
            } catch (\Throwable) {
                // app nao bootado (CLI avulso) — cai nos fallbacks
            }
        }

        return defined('PATH') ? PATH : getcwd();
    }

    private static function expectedHint(): string
    {
        return self::basePath() . '/mcp.config.json';
    }
}
