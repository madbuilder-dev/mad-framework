<?php

namespace Mad\Support;

/**
 * MadMediaFormatter — transformadores de célula para arquivo/imagem/avatar.
 *
 * Tokens (escolhidos no "Transformador" da coluna no builder, grupo Mídia):
 *   file-avatar   miniatura CIRCULAR (1 arquivo) — clica → lightbox
 *   file-thumb    miniatura quadrada arredondada — clica → lightbox
 *   file-link     ícone + nome do arquivo como link de download
 *   file-gallery  strip de até 3 miniaturas + "+N" — clica → lightbox navegável
 *
 * Funciona com QUALQUER forma que o MadForm persiste arquivo:
 *   - storage="disk": path relativo na coluna → URL assinada (mad_download_url)
 *   - storage="db"  : base64 cru na coluna → data URI (mime sniffado dos bytes)
 *   - data URI / URL http(s) já prontos → passam direto
 *   - multi-file mode="comma": CSV de paths → vira galeria naturalmente
 *   - JSON array de paths → idem
 *
 * O HTML sai RAW no grid (GridColumn::renderValue tem branch dedicado antes
 * do escape) — todo valor dinâmico aqui é escapado com htmlspecialchars.
 * O clique é resolvido pelo lightbox do runtime (mad-ui.js, delegação em
 * [data-mad-lightbox]); item não-imagem cai em link de download.
 */
class MadMediaFormatter
{
    public const TOKENS = ['file-avatar', 'file-thumb', 'file-link', 'file-gallery'];

    /** Extensões renderizáveis inline como <img> (espelha SAFE_INLINE_MIME do download). */
    private const IMAGE_EXT = ['png', 'jpg', 'jpeg', 'gif', 'webp'];

    /** Quantas miniaturas a galeria mostra antes do "+N". */
    private const GALLERY_VISIBLE = 3;

    public static function isMediaToken(string $token): bool
    {
        return in_array(strtolower(trim($token)), self::TOKENS, true);
    }

    /**
     * O valor e um ARQUIVO EMBUTIDO na propria coluna (data URI ou base64
     * cru)? Publico porque o GridColumn precisa da mesma resposta como rede
     * de protecao: sem ela, uma coluna de imagem sem transformador imprimia a
     * data URI inteira dentro do `<td>` e arrebentava o layout da listagem.
     */
    public static function looksLikeInlineFile(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }
        $s = trim($value);

        return str_starts_with($s, 'data:') || self::looksLikeBareBase64($s);
    }

    /** Entry point do GridColumn. Devolve HTML pronto (ou '' quando vazio). */
    public static function render(mixed $value, string $mode): string
    {
        $items = self::items($value);
        if (empty($items)) {
            return '';
        }

        return match (strtolower(trim($mode))) {
            'file-avatar'  => self::renderThumb($items[0], 'mad-cell-avatar'),
            'file-thumb'   => self::renderThumb($items[0], 'mad-cell-thumb'),
            'file-link'    => self::renderLinks($items),
            'file-gallery' => self::renderGallery($items),
            default        => '',
        };
    }

    /** Texto pro export PDF/CSV (Dompdf não roda o lightbox): nomes dos arquivos. */
    public static function renderPlain(mixed $value): string
    {
        $items = self::items($value);
        return implode(', ', array_map(fn ($i) => $i['name'], $items));
    }

    // ── Parsing do valor da coluna ─────────────────────────────────────────

    /**
     * Normaliza o valor da coluna numa lista de itens
     * `{url, downloadUrl, isImage, name}`. `url` vazio = sem como servir
     * (blob não-imagem sem endpoint) — vira chip de arquivo sem link.
     *
     * @return array<int, array{url:string, downloadUrl:string, isImage:bool, name:string}>
     */
    public static function items(mixed $value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        $raws = [];
        if (is_array($value)) {
            $raws = $value;
        } else {
            $str = trim((string) $value);
            if ($str === '') {
                return [];
            }
            if (str_starts_with($str, '[')) {
                $decoded = json_decode($str, true);
                $raws = is_array($decoded) ? $decoded : [$str];
            } elseif (str_starts_with($str, 'data:') || self::looksLikeBareBase64($str)) {
                // data URI tem vírgula no header; base64 cru é um blob único —
                // nenhum dos dois pode passar pelo split de CSV.
                $raws = [$str];
            } else {
                $raws = array_map('trim', explode(',', $str));
            }
        }

        $items = [];
        foreach ($raws as $raw) {
            if (!is_string($raw) || trim($raw) === '') {
                continue;
            }
            $item = self::resolveItem(trim($raw));
            if ($item !== null) {
                $items[] = $item;
            }
        }
        return $items;
    }

    /** @return array{url:string, downloadUrl:string, isImage:bool, name:string}|null */
    private static function resolveItem(string $raw): ?array
    {
        // Já é data URI (ex.: preview recém-salvo, ou coluna db com prefixo)
        if (str_starts_with($raw, 'data:')) {
            return [
                'url'         => $raw,
                'downloadUrl' => '',
                'isImage'     => str_starts_with($raw, 'data:image'),
                'name'        => 'imagem',
            ];
        }

        // Base64 cru (storage="db" grava sem o prefixo). Sniffa o mime dos
        // primeiros bytes; binário não-imagem vira chip sem link (o download
        // de blob tem rota própria com allowlist — não inventamos URL aqui).
        if (self::looksLikeBareBase64($raw)) {
            $mime = self::sniffBase64ImageMime($raw);
            if ($mime !== '') {
                return [
                    'url'         => 'data:' . $mime . ';base64,' . preg_replace('/\s+/', '', $raw),
                    'downloadUrl' => '',
                    'isImage'     => true,
                    'name'        => 'imagem',
                ];
            }
            return ['url' => '', 'downloadUrl' => '', 'isImage' => false, 'name' => 'arquivo'];
        }

        // URL absoluta
        if (preg_match('#^https?://#i', $raw)) {
            $ext = strtolower(pathinfo(parse_url($raw, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
            return [
                'url'         => $raw,
                'downloadUrl' => $raw,
                'isImage'     => in_array($ext, self::IMAGE_EXT, true),
                'name'        => basename(parse_url($raw, PHP_URL_PATH) ?: 'arquivo'),
            ];
        }

        // Path relativo do upload (storage="disk")
        $ext     = strtolower(pathinfo($raw, PATHINFO_EXTENSION));
        $isImage = in_array($ext, self::IMAGE_EXT, true);
        $name    = basename(str_replace('\\', '/', $raw));
        if (function_exists('mad_upload_is_file') && function_exists('mad_download_url') && mad_upload_is_file($raw)) {
            return [
                'url'         => mad_download_url($raw),
                // basename explícito força attachment na rota de download
                'downloadUrl' => mad_download_url($raw, $name),
                'isImage'     => $isImage,
                'name'        => $name,
            ];
        }

        // Path dangling (arquivo apagado / disco remoto) — chip com nome, sem link.
        return ['url' => '', 'downloadUrl' => '', 'isImage' => false, 'name' => $name];
    }

    private static function looksLikeBareBase64(string $s): bool
    {
        // Blob de arquivo real é grande; 256+ chars do alfabeto base64 sem '/'
        // no início (path tem '/', base64 pode ter mas path curto não passa
        // do teste de comprimento típico de blob).
        return strlen($s) > 256 && preg_match('#^[A-Za-z0-9+/=\r\n]+$#', $s) === 1;
    }

    /** Sniffa mime de imagem dos primeiros bytes do base64 ('' = não-imagem). */
    private static function sniffBase64ImageMime(string $b64): string
    {
        $head = base64_decode(substr(preg_replace('/\s+/', '', $b64), 0, 32), true);
        if ($head === false || $head === '') {
            return '';
        }
        if (str_starts_with($head, "\x89PNG")) return 'image/png';
        if (str_starts_with($head, "\xFF\xD8")) return 'image/jpeg';
        if (str_starts_with($head, 'GIF8')) return 'image/gif';
        if (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP') return 'image/webp';
        return '';
    }

    // ── Render ─────────────────────────────────────────────────────────────

    /** @param array{url:string, downloadUrl:string, isImage:bool, name:string} $item */
    private static function renderThumb(array $item, string $cls, string $group = ''): string
    {
        $name = htmlspecialchars($item['name'], ENT_QUOTES);

        if ($item['isImage'] && $item['url'] !== '') {
            $url   = htmlspecialchars($item['url'], ENT_QUOTES);
            $dl    = htmlspecialchars($item['downloadUrl'], ENT_QUOTES);
            $grp   = $group !== '' ? ' data-mad-lightbox-group="' . htmlspecialchars($group, ENT_QUOTES) . '"' : '';
            return '<span class="mad-cell-media ' . $cls . '" role="button" tabindex="0"'
                . ' data-mad-lightbox="' . $url . '" data-mad-lightbox-name="' . $name . '"'
                . ' data-mad-lightbox-dl="' . $dl . '"' . $grp . ' title="' . $name . '">'
                . '<img src="' . $url . '" loading="lazy" alt="' . $name . '">'
                . '</span>';
        }

        // Não-imagem: chip com ícone; com downloadUrl vira link, sem fica estático.
        $icon = self::fileIconSvg();
        if ($item['downloadUrl'] !== '') {
            $dl = htmlspecialchars($item['downloadUrl'], ENT_QUOTES);
            return '<a class="mad-cell-media ' . $cls . ' mad-cell-media-file" href="' . $dl . '"'
                . ' target="_blank" rel="noopener" title="' . $name . '">' . $icon . '</a>';
        }
        return '<span class="mad-cell-media ' . $cls . ' mad-cell-media-file" title="' . $name . '">' . $icon . '</span>';
    }

    /** @param array<int, array{url:string, downloadUrl:string, isImage:bool, name:string}> $items */
    private static function renderLinks(array $items): string
    {
        $out = [];
        foreach ($items as $item) {
            $name = htmlspecialchars($item['name'], ENT_QUOTES);
            $icon = $item['isImage'] ? self::imageIconSvg() : self::fileIconSvg();
            if ($item['downloadUrl'] !== '') {
                $dl    = htmlspecialchars($item['downloadUrl'], ENT_QUOTES);
                $out[] = '<a class="mad-cell-file-link" href="' . $dl . '" target="_blank" rel="noopener">'
                    . $icon . '<span>' . $name . '</span></a>';
            } else {
                $out[] = '<span class="mad-cell-file-link mad-cell-file-link-dead">'
                    . $icon . '<span>' . $name . '</span></span>';
            }
        }
        return implode(' ', $out);
    }

    /** @param array<int, array{url:string, downloadUrl:string, isImage:bool, name:string}> $items */
    private static function renderGallery(array $items): string
    {
        // Grupo único por célula: o lightbox navega entre os itens do grupo.
        $group   = 'g' . substr(md5(uniqid('', true)), 0, 8);
        $visible = array_slice($items, 0, self::GALLERY_VISIBLE);
        $rest    = count($items) - count($visible);

        $html = '<span class="mad-cell-gallery">';
        foreach ($visible as $item) {
            $html .= self::renderThumb($item, 'mad-cell-thumb', $group);
        }
        // Itens além dos visíveis entram no grupo do lightbox como âncoras
        // ocultas — o "+N" abre a galeria completa.
        foreach (array_slice($items, self::GALLERY_VISIBLE) as $item) {
            if ($item['isImage'] && $item['url'] !== '') {
                $url  = htmlspecialchars($item['url'], ENT_QUOTES);
                $name = htmlspecialchars($item['name'], ENT_QUOTES);
                $dl   = htmlspecialchars($item['downloadUrl'], ENT_QUOTES);
                $html .= '<span class="mad-cell-gallery-hidden" data-mad-lightbox="' . $url . '"'
                    . ' data-mad-lightbox-name="' . $name . '" data-mad-lightbox-dl="' . $dl . '"'
                    . ' data-mad-lightbox-group="' . $group . '"></span>';
            }
        }
        if ($rest > 0) {
            $html .= '<span class="mad-cell-gallery-more" data-mad-lightbox-open="' . $group . '">+' . $rest . '</span>';
        }
        return $html . '</span>';
    }

    private static function fileIconSvg(): string
    {
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/></svg>';
    }

    private static function imageIconSvg(): string
    {
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>';
    }
}
