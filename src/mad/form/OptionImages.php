<?php

namespace Mad\Form;

use Illuminate\Database\Eloquent\Model;

/**
 * OptionImages — imagem pequena ao lado do texto de cada opção dos campos de
 * lista (fórum #275: o logo do banco numa combo de bancos).
 *
 *  - Campos de banco (`<mad-dbcombo-field>`, `<mad-dbunique-search-field>`,
 *    `<mad-dbmulti-search-field>`): prop `image-column`, a coluna do model com
 *    a imagem de cada registro.
 *  - Lista fixa (`<mad-select-field>`): prop `:images` (valor do item => imagem)
 *    ou a chave `image` na lista de objetos.
 *
 * O valor da coluna pode ser o arquivo gravado por um campo de Upload/Imagem
 * (privado ou com `public`), um arquivo do app em `public/` ou um endereço
 * http(s). Ele vira um ENDEREÇO SEGURO para o `<img>` — {@see url()} — e vai na
 * `<option>` em `data-mad-img`. Nas recargas (cascata, busca) a coluna viaja no
 * token cifrado e a resposta leva só o endereço já pronto.
 *
 * Opção sem imagem fica só com o texto. Sem a prop nada muda: nenhum atributo
 * novo, nenhuma consulta nova.
 */
final class OptionImages
{
    /** Atributo da `<option>` com o endereço da imagem. */
    public const ATTR = 'data-mad-img';

    /** Imagem embutida (`data:`) aceita até este tamanho — logo, não foto. */
    private const DATA_MAX = 200_000;

    /** Endereço maior que isto não é de imagem de lista. */
    private const URL_MAX = 2048;

    /** Extensões aceitas num caminho de arquivo. */
    private const EXT = '/\.(png|jpe?g|gif|webp|avif|bmp|ico)$/i';

    /** `data:` aceito: só estes tipos de imagem (SVG fica de fora: pode levar script). */
    private const DATA_URI = '#^data:image/(png|jpeg|jpg|gif|webp);base64,[A-Za-z0-9+/=\s]+$#';

    /** Coluna válida da prop `image-column` ('' = sem imagem). */
    public static function column(mixed $column): string
    {
        $column = is_scalar($column) ? trim((string) $column) : '';

        return preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $column) === 1 ? $column : '';
    }

    /**
     * Endereço seguro para o `<img>` de uma opção, ou null (sem imagem).
     *
     *  - `https://…` / `http://…`: como está (o `<img>` só desenha imagem).
     *  - `data:image/png|jpeg|gif|webp;base64,…`: como está, até 200 KB.
     *  - Arquivo de campo com `public`: o endereço público dele.
     *  - Arquivo de campo de Upload/Imagem (privado): endereço assinado da rota de
     *    download, que só abre para quem está logado e enxerga `$owner` (o
     *    registro da opção) — o mesmo do `<mad-image-field>`.
     *  - Arquivo do app em `public/` (`app/images/bancos/itau.png` ou
     *    `/images/itau.png`): como está.
     *
     * Qualquer outro esquema (`javascript:`, `vbscript:`, `file:`, `data:` que
     * não é imagem), `//host`, `..` e caminho sem extensão de imagem: null.
     */
    public static function url(mixed $value, ?object $owner = null): ?string
    {
        $v = is_scalar($value) ? trim((string) $value) : '';
        if ($v === '' || preg_match('/[\x00-\x1F\x7F]/', $v)) {
            return null;
        }

        if (stripos($v, 'data:') === 0) {
            return strlen($v) <= self::DATA_MAX && preg_match(self::DATA_URI, $v) === 1 ? $v : null;
        }
        if (strlen($v) > self::URL_MAX || preg_match('/[\s"\'<>`\\\\]/', $v)) {
            return null;
        }
        if (preg_match('#^https?://[^/?\#]+#i', $v)) {
            return filter_var($v, FILTER_VALIDATE_URL) !== false ? $v : null;
        }
        // Outro esquema qualquer (javascript:, vbscript:, file:, c:\…) ou //host.
        if (preg_match('/^[A-Za-z][A-Za-z0-9+.\-]*:/', $v) || str_starts_with($v, '//')) {
            return null;
        }

        $path = (string) (parse_url($v, PHP_URL_PATH) ?? '');
        if ($path === '' || preg_match('#(^|/)\.\.(/|$)#', $path) || preg_match(self::EXT, $path) !== 1) {
            return null;
        }

        if ($v[0] === '/') {
            return $v; // arquivo do site, mesmo endereço
        }
        try {
            if (\Mad\Service\MadPublicUpload::isPublicPath($v)) {
                return mad_download_url($v);
            }
            if (function_exists('mad_upload_is_file') && mad_upload_is_file($v)) {
                return mad_download_url($v, null, $owner instanceof Model ? $owner : null);
            }
        } catch (\Throwable $e) {
            return null;
        }
        // Arquivo do app em public/ (endereço relativo ao <base href>).
        if (function_exists('public_path') && is_file(public_path($path))) {
            return $v;
        }

        return null;
    }

    /** A imagem do registro de uma opção (`image-column`), ou null. */
    public static function fromRecord(string $column, object $record): ?string
    {
        if ($column === '') {
            return null;
        }
        try {
            $value = $record->{$column} ?? null;
        } catch (\Throwable $e) {
            $value = null;
        }
        if ($value === null && $record instanceof Model) {
            $value = $record->getAttributes()[$column] ?? null;
        }

        return self::url($value, $record);
    }

    /**
     * Coletor para o {@see ModelOptionsLoader::itemsFromQuery()}: enche
     * `$images` (chave da opção => endereço). Sem coluna, null — a consulta roda
     * como sempre rodou.
     *
     * @param array<string, string> $images (saída)
     */
    public static function collector(string $column, array &$images): ?\Closure
    {
        if ($column === '') {
            return null;
        }

        return static function (mixed $key, object $record) use ($column, &$images): void {
            if (is_scalar($key) && ($url = self::fromRecord($column, $record)) !== null) {
                $images[(string) $key] = $url;
            }
        };
    }

    /**
     * Junta coletores (destaque + imagem) num só, para o 7º parâmetro do
     * `itemsFromQuery`. Todos null: null.
     */
    public static function each(?callable ...$fns): ?\Closure
    {
        $fns = array_values(array_filter($fns));
        if ($fns === []) {
            return null;
        }
        if (count($fns) === 1) {
            return \Closure::fromCallable($fns[0]);
        }

        return static function (mixed $key, object $record) use ($fns): void {
            foreach ($fns as $fn) {
                $fn($key, $record);
            }
        };
    }

    /**
     * Imagens por valor de uma lista fixa (`<mad-select-field>`): prop `:images`
     * (array valor => imagem) e, sem ela para o item, a chave `image` (ou `img`)
     * da lista de objetos.
     *
     * @param  array<array-key, mixed> $items  itens CRUS (antes do MadItems::normalize)
     * @return array<string, string>
     */
    public static function staticImages(mixed $images, array $items = []): array
    {
        $map = [];
        if (array_is_list($items)) {
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $key = $item['value'] ?? $item['id'] ?? $item['key'] ?? null;
                $img = $item['image'] ?? $item['img'] ?? null;
                if (is_scalar($key) && $img !== null) {
                    $map[(string) $key] = $img;
                }
            }
        }
        if (is_array($images)) {
            foreach ($images as $k => $img) {
                $map[(string) $k] = $img;
            }
        }

        $out = [];
        foreach ($map as $k => $img) {
            if (($url = self::url($img)) !== null) {
                $out[(string) $k] = $url;
            }
        }

        return $out;
    }

    /** ` data-mad-img="…"` (com o espaço na frente, escapado), ou '' sem imagem. */
    public static function attr(?string $url): string
    {
        return $url === null || $url === ''
            ? ''
            : ' ' . self::ATTR . '="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"';
    }
}
