<?php

declare(strict_types=1);

namespace Mad\Chart;

use Mad\Database\QuerySource;
use Mad\Form\ModelOptionsLoader;
use Mad\Grid\GridRenderHelpers;

/**
 * MadMap — monta os pontos do <mad-map> (marcadores com cor, popover e clique).
 *
 * Só dados: o Blade (`components.map`) emite a configuração e o
 * `lib/mad/mad-map.js` desenha com o Leaflet servido pelo pacote.
 *
 *   MadMap::layer([
 *       'model' => 'Unidade', 'latField' => 'latitude', 'lngField' => 'longitude',
 *       'titleField' => '{codigo} - {nome}', 'popoverContent' => '{cidade}/{uf}',
 *       'colorField' => 'situacao', 'colorMap' => ['ativa' => 'success', 'obra' => '#f59e0b'],
 *   ]);
 *
 * Fontes (uma delas):
 *   - `model` (+ `filters`, `unitField`): Eloquent com os escopos globais do
 *     model (unidade, empresa, exclusão lógica). Dentro de um painel
 *     (MadDashboard), a consulta sai do painel: período, unidade e os filtros
 *     de `<mad-dash-filters>` valem como nos outros widgets;
 *   - `query`: Eloquent/Query Builder pronto;
 *   - `data`: array do PHP com as mesmas chaves de lat/lng.
 *
 * Linha sem coordenada (nula, vazia, fora da faixa ou 0,0) fica fora do mapa,
 * sem erro.
 */
final class MadMap
{
    /** Teto de camadas por mapa (a principal conta). */
    public const MAX_LAYERS = 5;

    /** Teto de pontos por camada: o payload vai inteiro para o navegador. */
    public const DEFAULT_LIMIT = 2000;

    public const TONES = ['success', 'info', 'warning', 'danger', 'primary', 'secondary', 'orange', 'neutral'];

    /** @var list<array<string,mixed>> camadas de <mad-map-layer> esperando o mapa que as contém */
    private static array $pending = [];

    private static int $seq = 0;

    // ── camadas filhas ───────────────────────────────────────────────────

    /**
     * Guarda a camada de um `<mad-map-layer>`. O Blade renderiza o conteúdo
     * do `<mad-map>` antes da view do próprio mapa, então a camada chega aqui
     * primeiro e o mapa a recolhe com {@see takeLayers()}.
     */
    public static function pushLayer(array $layer): void
    {
        self::$pending[] = $layer;
    }

    /** @return list<array<string,mixed>> */
    public static function takeLayers(): array
    {
        $out = self::$pending;
        self::$pending = [];

        return $out;
    }

    // ── dados ────────────────────────────────────────────────────────────

    /**
     * Camada a partir das props da tag (`<mad-map>` ou `<mad-map-layer>`):
     * pontos, legenda, clique e ícone, prontos para o JSON do navegador.
     *
     * @param array<string,mixed> $p props já em camelCase
     * @return array<string,mixed>
     */
    public static function fromProps(array $p, mixed $host = null): array
    {
        $click = self::click((string) ($p['clickTarget'] ?? ''), (string) ($p['clickAction'] ?? ''));
        $keyField = trim((string) ($p['keyField'] ?? ''));
        if ($keyField === '' && ($click['explicit'] ?? false)) {
            $keyField = $click['param'];
        }
        $model = $p['model'] ?? '';
        $model = is_string($model) ? trim($model) : '';

        $data = self::layer([
            'model' => $model, 'database' => $p['database'] ?? '', 'query' => $p['query'] ?? null,
            'data' => $p['data'] ?? null, 'filters' => $p['filters'] ?? [], 'unitField' => $p['unitField'] ?? '',
            'host' => $host, 'latField' => $p['latField'] ?? '', 'lngField' => $p['lngField'] ?? '',
            'keyField' => $keyField, 'titleField' => $p['titleField'] ?? '',
            'popoverContent' => $p['popoverContent'] ?? '', 'colorField' => $p['colorField'] ?? '',
            'colorMap' => $p['colorMap'] ?? null, 'color' => $p['color'] ?? '', 'limit' => $p['limit'] ?? null,
        ]);

        $label = trim((string) ($p['label'] ?? ''));
        if ($label === '' && $model !== '') {
            $label = class_basename($model);
        }
        $icon = strtolower(trim((string) ($p['icon'] ?? '')));

        return $data + [
            'label'  => $label,
            'icon'   => preg_match('/^(tabler:)?[a-z0-9-]{1,40}$/', $icon) ? $icon : '',
            'color'  => self::color((string) ($p['color'] ?? '')),
            'hidden' => self::bool($p['hidden'] ?? null, false),
            'click'  => $click,
        ];
    }

    public static function bool(mixed $v, bool $default): bool
    {
        if ($v === null || $v === '') {
            return $default;
        }
        if (is_bool($v)) {
            return $v;
        }

        return !in_array(strtolower(trim((string) $v)), ['false', '0', 'no', 'off', 'nao', 'não'], true);
    }

    /**
     * Uma camada pronta para o JS.
     *
     * @param array<string,mixed> $c
     * @return array{points: list<array<string,mixed>>, legend: list<array{label:string,color:string}>,
     *               skipped: int, truncated: bool, error: ?string, sql: ?string, binds: array}
     */
    public static function layer(array $c): array
    {
        $latField = trim((string) ($c['latField'] ?? '')) ?: 'latitude';
        $lngField = trim((string) ($c['lngField'] ?? '')) ?: 'longitude';
        $keyField = trim((string) ($c['keyField'] ?? ''));
        $title = (string) ($c['titleField'] ?? '');
        $popover = (string) ($c['popoverContent'] ?? '');
        $colorField = trim((string) ($c['colorField'] ?? ''));
        $colorMap = self::colorMap($c['colorMap'] ?? null);
        $default = self::color((string) ($c['color'] ?? '')) ?: 'var(--mad-map-pin, #2563eb)';
        $limit = max(1, min(20000, (int) ($c['limit'] ?? self::DEFAULT_LIMIT)));

        $error = null;
        $sql = null;
        $binds = [];
        $rows = [];
        try {
            if (isset($c['data']) && is_array($c['data'])) {
                $rows = array_slice(array_values($c['data']), 0, $limit + 1);
            } else {
                [$rows, $sql, $binds, $pk] = self::fromQuery($c, $latField, $lngField, $limit);
                if ($keyField === '') {
                    $keyField = $pk;
                }
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            $rows = [];
        }
        if ($keyField === '') {
            $keyField = 'id';
        }

        $truncated = count($rows) > $limit;
        if ($truncated) {
            $rows = array_slice($rows, 0, $limit);
        }

        $points = [];
        $skipped = 0;
        foreach ($rows as $row) {
            $lat = self::coord(self::value($row, $latField), 90.0);
            $lng = self::coord(self::value($row, $lngField), 180.0);
            if ($lat === null || $lng === null || ($lat === 0.0 && $lng === 0.0)) {
                $skipped++;
                continue;
            }
            $arr = is_array($row) ? $row : [];
            $key = self::value($row, $keyField);
            $colorValue = $colorField !== '' ? self::value($row, $colorField) : null;
            $points[] = [
                'lat'     => $lat,
                'lng'     => $lng,
                'id'      => is_scalar($key) ? (string) $key : '',
                'title'   => $title !== '' ? self::text($title, $row, $arr) : '',
                'popover' => $popover !== '' ? self::text($popover, $row, $arr) : '',
                'color'   => $colorField !== '' ? ($colorMap[self::mapKey($colorValue)]['color'] ?? $default) : $default,
            ];
        }

        $legend = [];
        foreach ($colorMap as $entry) {
            $legend[] = ['label' => $entry['label'], 'color' => $entry['color']];
        }

        return [
            'points' => $points, 'legend' => $legend, 'skipped' => $skipped, 'truncated' => $truncated,
            'error' => $error, 'sql' => $sql, 'binds' => $binds,
        ];
    }

    /**
     * Centro e zoom iniciais. `auto` (padrão) deixa o JS enquadrar os pontos;
     * "lat,lng" fixa o centro.
     *
     * @return array{0: ?array{0: float, 1: float}, 1: int}
     */
    public static function view(mixed $center, mixed $zoom): array
    {
        $z = is_numeric($zoom) ? max(1, min(19, (int) $zoom)) : 0;
        $pair = null;
        if (is_array($center) && count($center) === 2) {
            $center = implode(',', array_values($center));
        }
        $center = trim((string) ($center ?? ''));
        if ($center !== '' && strtolower($center) !== 'auto' && preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*[,;]\s*(-?\d+(?:\.\d+)?)\s*$/', $center, $m)) {
            $lat = self::coord($m[1], 90.0);
            $lng = self::coord($m[2], 180.0);
            if ($lat !== null && $lng !== null) {
                $pair = [$lat, $lng];
            }
        }

        return [$pair, $z];
    }

    /**
     * `:color-map` normalizado: valor do campo => cor e rótulo da legenda.
     * Aceita `['ativa' => 'success']`, `['ativa' => '#16a34a']` ou
     * `['ativa' => ['color' => 'success', 'label' => 'Ativa']]`.
     *
     * @return array<string, array{label: string, color: string}>
     */
    public static function colorMap(mixed $map): array
    {
        if (is_string($map) && trim($map) !== '') {
            $decoded = json_decode($map, true);
            $map = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($map)) {
            return [];
        }
        $out = [];
        foreach ($map as $value => $spec) {
            $label = (string) $value;
            $color = '';
            if (is_array($spec)) {
                $label = (string) ($spec['label'] ?? $label);
                $color = (string) ($spec['color'] ?? ($spec['tone'] ?? ''));
            } elseif (is_scalar($spec)) {
                $color = (string) $spec;
            }
            $css = self::color($color);
            if ($css === '') {
                continue;
            }
            $out[self::mapKey($value)] = ['label' => $label, 'color' => $css];
        }

        return $out;
    }

    /**
     * Tom do tema (`success`, `danger`…) → token CSS; cor literal passa se for
     * #hex, rgb/hsl/oklch ou var(--token). O resto vira '' (o valor vai para
     * um style no navegador).
     */
    public static function color(string $color): string
    {
        $color = trim($color);
        if ($color === '') {
            return '';
        }
        $tone = strtolower($color);
        if (in_array($tone, self::TONES, true)) {
            return 'var(--mad-map-' . $tone . ')';
        }
        if (preg_match('/^(#[0-9a-f]{3,8}|(rgb|hsl|oklch)a?\([^;{}<>"\']*\)|var\(--[a-z0-9-]+\))$/i', $color)) {
            return $color;
        }

        return '';
    }

    /**
     * Clique no marcador. O parâmetro sai do `({campo})` do alvo (padrão `id`)
     * e o valor enviado é o desse campo na linha.
     *
     *   click-target="UnidadeForm::onEdit({id})" → Mad.go(UnidadeForm, onEdit, {id})
     *   click-action="abrir({codigo})"           → MadWire.call(tela, abrir, [codigo])
     *
     * `explicit` = o campo veio escrito no alvo; sem ele o valor é a chave
     * primária do model.
     *
     * @return array{param: string, explicit: bool, action?: string, target?: string, method?: string, url?: string}|null
     */
    public static function click(string $target, string $action): ?array
    {
        $target = trim($target);
        $action = trim($action);
        $src = $action !== '' ? $action : $target;
        if ($src === '') {
            return null;
        }
        $param = 'id';
        $explicit = false;
        if (preg_match('/\(\s*\{?\s*([A-Za-z_][A-Za-z0-9_]*)\s*\}?\s*\)\s*$/', $src, $m)) {
            $param = $m[1];
            $explicit = true;
        }
        if ($action !== '') {
            $method = trim((string) preg_replace('/\(.*\)$/s', '', $action));

            return $method !== '' ? ['param' => $param, 'explicit' => $explicit, 'action' => $method] : null;
        }
        $nav = \Mad\Ui\MadAction::navTarget($target, 'onEdit', [$param]);

        return $nav ? ['param' => $param, 'explicit' => $explicit, 'target' => $nav['class'], 'method' => $nav['method'], 'url' => $nav['url']] : null;
    }

    public static function domId(string $seed): string
    {
        return 'mad-map-' . substr(md5($seed), 0, 8) . '-' . (++self::$seq);
    }

    // ── fontes ───────────────────────────────────────────────────────────

    /** @return array{0: array, 1: ?string, 2: array, 3: string} */
    private static function fromQuery(array $c, string $latField, string $lngField, int $limit): array
    {
        $lat = self::column($latField, 'lat-field');
        $lng = self::column($lngField, 'lng-field');

        $source = $c['query'] ?? null;
        if (QuerySource::isQuery($source)) {
            $q = clone $source;
        } else {
            $model = trim((string) ($c['model'] ?? ''));
            if ($model === '') {
                throw new \InvalidArgumentException('<mad-map>: informe model, :query ou :data.');
            }
            $cls = class_exists($model) ? $model : ModelOptionsLoader::resolveModelClass($model);
            $host = $c['host'] ?? null;
            $q = null;
            if ($host instanceof \Mad\Dashboard\MadDashboard) {
                try {
                    $q = $host->baseQuery($model);
                } catch (\Throwable) {
                    $q = null;
                }
            }
            $q ??= $cls::query();
            $db = trim((string) ($c['database'] ?? ''));
            if ($db !== '' && $q instanceof \Illuminate\Database\Eloquent\Builder && $host === null) {
                $q = (new $cls())->setConnection($db)->newQuery();
            }
        }

        $base = $q instanceof \Illuminate\Database\Eloquent\Builder ? $q->getQuery() : $q;
        if (!empty($c['filters']) && is_array($c['filters'])) {
            QuerySource::applyArrayFilters($base, $c['filters']);
        }

        $unitField = trim((string) ($c['unitField'] ?? ''));
        if ($unitField !== '') {
            $unit = session('userunitid');
            if ($unit !== null && $unit !== '') {
                $base->where(self::column($unitField, 'unit-field'), $unit);
            }
        }

        $base->whereNotNull($lat)->whereNotNull($lng);
        $q->limit($limit + 1);

        $pk = $q instanceof \Illuminate\Database\Eloquent\Builder ? (string) $q->getModel()->getKeyName() : 'id';
        $sql = $base->toSql();
        $binds = $base->getBindings();
        $rows = $q->get();
        $rows = $rows instanceof \Illuminate\Support\Collection ? $rows->all() : (array) $rows;

        return [$rows, $sql, $binds, $pk];
    }

    private static function column(string $col, string $attr): string
    {
        $col = trim($col);
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $col)) {
            throw new \InvalidArgumentException("<mad-map>: {$attr}=\"{$col}\" precisa ser o nome de uma coluna.");
        }

        return $col;
    }

    private static function value(mixed $row, string $field): mixed
    {
        if ($field === '') {
            return null;
        }
        if (is_array($row)) {
            return $row[$field] ?? null;
        }
        if (is_object($row)) {
            try {
                return $row->{$field} ?? null;
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private static function coord(mixed $v, float $max): ?float
    {
        if (is_string($v)) {
            $v = str_replace(',', '.', trim($v));
        }
        if ($v === null || $v === '' || !is_numeric($v)) {
            return null;
        }
        $f = (float) $v;
        if (!is_finite($f) || abs($f) > $max) {
            return null;
        }

        return round($f, 7);
    }

    private static function text(string $pattern, mixed $row, array $arr): string
    {
        if (!str_contains($pattern, '{')) {
            $pattern = '{' . $pattern . '}';
        }
        $item = is_array($row) ? (object) $row : $row;

        return trim(GridRenderHelpers::resolveTemplate($pattern, $item, $arr));
    }

    private static function mapKey(mixed $v): string
    {
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        if ($v instanceof \BackedEnum) {
            return (string) $v->value;
        }

        return is_scalar($v) ? (string) $v : '';
    }
}
