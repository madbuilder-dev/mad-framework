<?php
namespace Mad\Grid;

/**
 * MadGridPdfBranding — config global de header/footer dos PDFs exportados
 * pelas grades (MadGridExporter::pdf).
 *
 * Lê `app/config/pdf-export.json`, emitido pelo MadBuilder a partir das
 * propriedades do projeto. Shape:
 *
 *   { "version": 1,
 *     "header": { "mode": "inherit|custom|none", "html": "...", "heightMm": 28 },
 *     "footer": { "mode": "inherit|custom|none", "html": "...", "heightMm": 14, "showPagination": true },
 *     "images": { "large": "data:image/png;base64,...", "small": "data:..." },
 *     "placeholders": { "CNPJ": "12.345.678/0001-90" },
 *     "orientation": "portrait|landscape",
 *     "palette": { "headerRule": "#e3e8ef" } }
 *
 * Arquivo ausente/ inválido == tudo `inherit` (default vitrine do exporter).
 * Cache em memória por processo, invalidado por mtime (Octane-safe).
 */
final class MadGridPdfBranding
{
    private static ?array $cache = null;
    private static ?string $cacheKey = null;

    /** Config bruta decodificada (ou [] quando não há arquivo/JSON válido). */
    public static function config(): array
    {
        $path = self::path();
        if (! is_file($path)) {
            self::$cache = [];
            self::$cacheKey = $path . ':absent';
            return [];
        }

        $key = $path . ':' . (string) @filemtime($path);
        if (self::$cache !== null && self::$cacheKey === $key) {
            return self::$cache;
        }

        $raw  = @file_get_contents($path);
        $data = $raw === false ? null : json_decode($raw, true);

        self::$cache    = is_array($data) ? $data : [];
        self::$cacheKey = $key;

        return self::$cache;
    }

    /**
     * Banda global normalizada.
     *
     * @param 'header'|'footer' $which
     * @return array{mode:string, html:string, heightMm:int, showPagination:bool}
     */
    public static function band(string $which): array
    {
        $raw = self::config()[$which] ?? [];
        if (! is_array($raw)) $raw = [];

        $mode = $raw['mode'] ?? 'inherit';
        if (! in_array($mode, ['inherit', 'custom', 'none'], true)) {
            $mode = 'inherit';
        }

        $defaultH = $which === 'header' ? 28 : 14;
        $maxH     = $which === 'header' ? 60 : 40;

        return [
            'mode'           => $mode,
            'html'           => (string) ($raw['html'] ?? ''),
            'heightMm'       => max(8, min($maxH, (int) ($raw['heightMm'] ?? $defaultH))),
            'gapMm'          => max(0, min(15, is_numeric($raw['gapMm'] ?? null) ? (int) $raw['gapMm'] : 4)),
            'showPagination' => (bool) ($raw['showPagination'] ?? true),
        ];
    }

    /**
     * Paleta do PDF (linhas de quebra/total e a linha do cabeçalho padrão):
     * o default do exporter com o override do projeto aplicado por cima.
     *
     * Mesmo molde do band() — lê a chave `"palette"` do pdf-export.json,
     * aceita só as chaves conhecidas e só valores que PAREÇAM cor CSS: o
     * valor é interpolado num <style> do Dompdf, e uma string arbitrária ali
     * quebraria a folha inteira em silêncio.
     *
     * @return array<string,string>
     */
    public static function palette(): array
    {
        return self::sanitizePalette(self::config()['palette'] ?? [], MadGridExporter::PDF_PALETTE);
    }

    /**
     * Aplica `$raw` por cima de `$base` aceitando só as chaves de `$base` e só
     * valores que pareçam cor CSS (#abc / #aabbcc / #aabbccdd / rgb(...) /
     * rgba(...) / nome CSS, inclusive `none`/`transparent`). Mesma regra para
     * a paleta do projeto (pdf-export.json) e a da página (exportPdfBands()).
     *
     * @param array<string,string> $base
     * @return array<string,string>
     */
    public static function sanitizePalette(mixed $raw, array $base): array
    {
        if (! is_array($raw)) {
            return $base;
        }

        $out = $base;
        foreach (array_keys($base) as $key) {
            $v = $raw[$key] ?? null;
            if (! is_string($v)) continue;
            $v = trim($v);
            if (preg_match('/^(#[0-9a-fA-F]{3,8}|rgba?\([0-9,.\s%]+\)|[a-zA-Z]{3,20})$/', $v)) {
                $out[$key] = $v;
            }
        }

        return $out;
    }

    /**
     * Orientação do papel do projeto (`"orientation"` do pdf-export.json).
     * null = não configurado (o exporter usa paisagem).
     *
     * @return 'portrait'|'landscape'|null
     */
    public static function orientation(): ?string
    {
        return self::normalizeOrientation(self::config()['orientation'] ?? null);
    }

    /** 'portrait'|'landscape' válidos; qualquer outra coisa vira null. */
    public static function normalizeOrientation(mixed $raw): ?string
    {
        return in_array($raw, ['portrait', 'landscape'], true) ? $raw : null;
    }

    /**
     * Data-URI do logo do projeto ('' quando não configurado).
     *
     * @param 'large'|'small' $type
     */
    public static function image(string $type): string
    {
        $img = self::config()['images'][$type] ?? '';
        return (is_string($img) && str_starts_with($img, 'data:image/')) ? $img : '';
    }

    /**
     * Placeholders fixos do projeto — chave `"placeholders"` do pdf-export.json
     * (`{"CNPJ": "12.345…", "ENDERECO": "…"}`), digitados no MadBuilder sem
     * código. Passam pelo normalize() da MadGridPdfPlaceholders: chave vira
     * `{CNPJ}`, inválidas/estruturais caem fora, valor vira string.
     *
     * @return array<string,string>
     */
    public static function placeholders(): array
    {
        $raw = self::config()['placeholders'] ?? [];

        return is_array($raw) ? MadGridPdfPlaceholders::normalize($raw) : [];
    }

    /** Limpa o cache (testes / republish). */
    public static function reset(): void
    {
        self::$cache    = null;
        self::$cacheKey = null;
    }

    private static function path(): string
    {
        if (function_exists('base_path')) {
            try {
                return base_path() . '/app/config/pdf-export.json';
            } catch (\Throwable) {
                // app não bootado (CLI avulso) — cai no fallback
            }
        }

        return (defined('PATH') ? PATH : getcwd()) . '/app/config/pdf-export.json';
    }
}
