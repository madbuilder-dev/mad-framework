<?php

namespace Mad\Doc;

/**
 * MadDocTableCompiler — compiles `<mad-doc-data-table>…</mad-doc-data-table>`
 * and its `<mad-doc-data-table-column …/>` children into a call to the
 * underlying `<x-doc-data-table>` Blade component.
 *
 * Why a pre-compiler?
 *   Two reasons:
 *     1. `<mad-doc-data-table-column>` isn't a real component — it's a
 *        config tag. Turning it into a "real" component would render each
 *        column individually; we need them aggregated into a `:columns`
 *        array for the parent.
 *     2. Row sourcing supports three mutually-exclusive modes that we want
 *        the *component* author to be blind to:
 *          a) `:rows="$record->itens"` — escape hatch for pre-loaded data
 *          b) `model="Cls" :filter="$filter"` — the canonical mad-framework
 *             pattern; compiler emits `MadDocRuntime::queryRecords()` (Eloquent)
 *          c) `model="Cls"` alone — fetch all
 *
 * Runs in `MadBlade::compileString()` right after the repeater compiler,
 * before the `<mad-> → <x->` prefix replacement. The tag never reaches
 * Blade's component system.
 *
 * Syntax
 * ──────
 *   <mad-doc-data-table
 *       model="ItemNota"
 *       :filter="$filter"
 *       :totals="$totals"
 *       group-by="categoria"
 *       :zebra="true" :borders="true" :compact="false"
 *       header-bg="#f3f4f6" :cell-padding="6" :font-size="10">
 *       <mad-doc-data-table-column label="Produto" field="produto->nome" align="left" width="40%" />
 *       <mad-doc-data-table-column label="Qtd"     field="quantidade"   align="right"
 *                                  formatter="integer" total="sum" />
 *       <mad-doc-data-table-column label="Subtotal"
 *                                  formula="{quantidade}*{valor_unit}" field-name="subtotal"
 *                                  align="right" formatter="money"
 *                                  total="sum" total-name="valor_total" />
 *   </mad-doc-data-table>
 *
 * Emits
 * ─────
 *   @php $__mdt_0_rows = \Mad\Doc\MadDocRuntime::queryRecords('ItemNota', ($filter)); @endphp
 *   <x-doc-data-table
 *       :rows="$__mdt_0_rows"
 *       :columns="[ [ 'label' => 'Produto', 'field' => 'produto->nome', … ], … ]"
 *       :totals="$totals"
 *       group-by="categoria"
 *       :zebra="true" :borders="true" :compact="false"
 *       header-bg="#f3f4f6" :cell-padding="6" :font-size="10" />
 *
 * Column attribute → PHP key mapping:
 *   label        →  label
 *   field        →  field
 *   formula      →  formula
 *   field-name   →  field_name
 *   attrs        →  attrs            (comma-separated string → array)
 *   separator    →  separator
 *   align        →  align
 *   width        →  width
 *   formatter    →  format
 *   total        →  total
 *   total-name   →  total_name
 */
class MadDocTableCompiler
{
    /** Counter for scoped var names — avoids clashes on multiple tables. */
    private static int $scope = 0;

    public static function compile(string $template): string
    {
        if (strpos($template, '<mad-doc-data-table') === false) return $template;

        // Attribute sub-pattern: tolerates newlines and `>` inside quoted values
        // (e.g. :rows="$record->itens"), matches name | :name | @name | name="..." | name='...'.
        $attr = '(?:\s+(?::?[a-zA-Z_][\w:.-]*(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'))?))*\s*';

        $prev = '';
        $max = 20;
        while ($template !== $prev && $max-- > 0) {
            $prev = $template;
            $template = preg_replace_callback(
                // Body may contain <mad-doc-data-table-column …/> (OK, that's a
                // different tag) but NOT another `<mad-doc-data-table ` / `>`
                // — that would be a nested outer table. The whitespace/`>`
                // alternation on the lookahead is important: `\b` alone would
                // also trip on `<mad-doc-data-table-column` (since `-` is
                // non-word and `e` is word, `\b` matches there).
                '/<mad-doc-data-table(' . $attr . ')>'
                . '((?:(?!<mad-doc-data-table[\s>]|<\/mad-doc-data-table>)[\s\S])*)'
                . '<\/mad-doc-data-table>/s',
                [static::class, 'compileBlock'],
                $template,
            ) ?? $template;
        }

        return $template;
    }

    private static function compileBlock(array $m): string
    {
        $attrs = self::parseAttrs((string) ($m[1] ?? ''));
        $body  = (string) ($m[2] ?? '');

        $scope = self::$scope++;
        $pfx   = "__mdt_{$scope}";

        // Extract column children from the body (in order). Anything that
        // isn't a <mad-doc-data-table-column …/> is dropped — this is a
        // pure container for column specs.
        $columns = self::extractColumns($body);
        $columnsLiteral = self::phpArrayLiteral($columns);

        // Build the rows-sourcing prologue + determine the :rows expr.
        [$prologue, $rowsExpr] = self::buildRowsExpression($attrs, $pfx);

        // Passthrough attributes that go straight to <x-doc-data-table>.
        $passthrough = self::passthroughAttrs($attrs);

        // Stitch final output.
        $out = '';
        if ($prologue !== '') $out .= $prologue;

        $out .= '<x-doc-data-table' . "\n"
              . '    :rows="' . $rowsExpr . '"' . "\n"
              . '    :columns="' . str_replace('"', '\\"', $columnsLiteral) . '"' . "\n"
              . $passthrough
              . ' />' . "\n";

        return $out;
    }

    /**
     * Decide how to build the rows array based on attribute presence.
     *
     * @return array{0:string,1:string} [prelude @php block, rows expression]
     */
    private static function buildRowsExpression(array $attrs, string $pfx): array
    {
        // (a) explicit :rows="…" wins
        if (isset($attrs['rows']) && ($attrs['__bound__']['rows'] ?? false)) {
            return ['', (string) $attrs['rows']];
        }

        // (b) model [+ filter] → MadDocRuntime::queryRecords (Eloquent)
        if (isset($attrs['model'])) {
            $model = (string) $attrs['model'];
            // Validate model name — must be a legal PHP class identifier so
            // the emitted code is safe (no code injection).
            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*$/', $model)) {
                // Malformed — emit an empty rows expression so the Blade
                // keeps rendering but the table is empty.
                return ['', '[]'];
            }
            $modelExpr = var_export($model, true);

            $filterExpr = 'null';
            // `:criteria` é o nome ANTIGO de `:filter` (jun/2026). Documento
            // publicado antes da troca ainda o traz — ignorá-lo listava TODOS
            // os registros do model, sem erro. `:filter` vence se vierem os dois.
            foreach (['filter', 'criteria'] as $key) {
                if (isset($attrs[$key]) && ($attrs['__bound__'][$key] ?? false)) {
                    $filterExpr = (string) $attrs[$key];
                    break;
                }
            }

            $prelude = "@php \${$pfx}_rows = \\Mad\\Doc\\MadDocRuntime::queryRecords({$modelExpr}, ({$filterExpr})); @endphp\n";
            return [$prelude, "\${$pfx}_rows"];
        }

        // (c) no source — empty placeholder.
        return ['', '[]'];
    }

    /**
     * Extract `<mad-doc-data-table-column …/>` children, returning an array
     * of column spec arrays in source order. Children can be self-closing
     * (`/>`) or paired (`<…>…</…>`); we only use the attributes either way.
     */
    private static function extractColumns(string $body): array
    {
        $out = [];
        $attr = '(?:\s+(?::?[a-zA-Z_][\w:.-]*(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'))?))*\s*';
        $pattern = '/<mad-doc-data-table-column(' . $attr . ')(?:\/>|>[\s\S]*?<\/mad-doc-data-table-column>)/s';

        if (! preg_match_all($pattern, $body, $ms)) return [];
        foreach ($ms[1] as $attrBlob) {
            $out[] = self::columnAttrsToSpec(self::parseAttrs($attrBlob));
        }
        return $out;
    }

    /**
     * Turn the parsed attribute map into the `:columns`-spec shape that
     * `doc-data-table.blade.php` expects. Kebab-case → snake_case mapping,
     * `attrs="a, b, c"` → array, dropped empties.
     */
    private static function columnAttrsToSpec(array $a): array
    {
        $spec = [];
        foreach (['label', 'field', 'formula', 'align', 'width'] as $k) {
            if (isset($a[$k]) && (string) $a[$k] !== '') $spec[$k] = (string) $a[$k];
        }
        // kebab → snake for compound keys
        if (isset($a['field-name']) && (string) $a['field-name'] !== '') {
            $spec['field_name'] = (string) $a['field-name'];
        }
        if (isset($a['total-name']) && (string) $a['total-name'] !== '') {
            $spec['total_name'] = (string) $a['total-name'];
        }
        // formatter → format (component expects 'format')
        if (isset($a['formatter']) && (string) $a['formatter'] !== '') {
            $spec['format'] = (string) $a['formatter'];
        } elseif (isset($a['format']) && (string) $a['format'] !== '') {
            $spec['format'] = (string) $a['format'];
        }
        if (isset($a['total']) && (string) $a['total'] !== '') {
            $spec['total'] = (string) $a['total'];
        }
        // Free-form mask (e.g. `mask="{produto->nome} - {qtd}"`) passes straight
        // through; the runtime tokenizes and interpolates per-row.
        if (isset($a['mask']) && (string) $a['mask'] !== '') {
            $spec['mask'] = (string) $a['mask'];
        }
        // attrs="produto->codigo, produto->nome" → ['produto->codigo', 'produto->nome']
        if (isset($a['attrs']) && (string) $a['attrs'] !== '') {
            $pieces = array_map('trim', explode(',', (string) $a['attrs']));
            $pieces = array_values(array_filter($pieces, fn ($p) => $p !== ''));
            if (count($pieces) === 1) {
                // Single attr → treat as `field` for symmetry
                if (! isset($spec['field'])) $spec['field'] = $pieces[0];
            } else {
                $spec['attrs'] = $pieces;
            }
        }
        if (isset($a['separator']) && (string) $a['separator'] !== '') {
            $spec['separator'] = (string) $a['separator'];
        }
        return $spec;
    }

    /**
     * Emit the passthrough attributes (style, group-by, totals) that carry
     * straight to `<x-doc-data-table>`. The compiler doesn't touch their
     * semantics — just preserves bound (`:key="…"`) vs static (`key="…"`)
     * form so Blade compiles each correctly.
     */
    private static function passthroughAttrs(array $attrs): string
    {
        // Attributes we consume internally (rows sourcing + children).
        $consumed = ['rows', 'model', 'filter', 'criteria', '__bound__'];

        $parts = [];
        foreach ($attrs as $name => $val) {
            if ($name === '__bound__') continue;
            if (in_array($name, $consumed, true)) continue;
            $bound = $attrs['__bound__'][$name] ?? false;
            $quoted = '"' . str_replace('"', '\\"', (string) $val) . '"';
            $parts[] = ($bound ? ':' : '') . $name . '=' . $quoted;
        }
        return $parts ? '    ' . implode(' ', $parts) . "\n" : '';
    }

    /**
     * Parse the attribute blob into `[name => value, '__bound__' => [name => bool]]`.
     * Accepts `name`, `name="val"`, `:name="expr"`, `name='val'`.
     * Bound attribs (`:name="…"`) store the raw PHP expression; static ones
     * store the stripped literal.
     */
    private static function parseAttrs(string $attrBlob): array
    {
        $out = ['__bound__' => []];
        $pos = 0;
        $len = strlen($attrBlob);

        while ($pos < $len) {
            if (preg_match('/\G\s+/', $attrBlob, $m, 0, $pos)) {
                $pos += strlen($m[0]);
                continue;
            }
            // (:?)name (\s*=\s* "…" | '…')?
            if (preg_match(
                '/\G(:?)([a-zA-Z_][a-zA-Z0-9_:-]*)\s*=\s*(["\'])((?:(?!\3)[\s\S])*?)\3/s',
                $attrBlob, $m, 0, $pos,
            )) {
                $pos += strlen($m[0]);
                $bound = $m[1] === ':';
                $name  = $m[2];
                $out[$name] = $m[4];
                $out['__bound__'][$name] = $bound;
                continue;
            }
            if (preg_match('/\G([a-zA-Z_][a-zA-Z0-9_:-]*)/', $attrBlob, $m, 0, $pos)) {
                $pos += strlen($m[0]);
                $out[$m[1]] = 'true';
                $out['__bound__'][$m[1]] = true;
                continue;
            }
            $pos++;
        }

        return $out;
    }

    /**
     * Compact PHP array literal (short-syntax, single-quoted strings, no
     * indentation). Reused across data-table compiler + the builder's own
     * emitter have the same expectations so output stays identical across
     * both sides.
     */
    private static function phpArrayLiteral(array $value): string
    {
        return self::phpEncode($value);
    }

    private static function phpEncode(mixed $v): string
    {
        if ($v === null) return 'NULL';
        if (is_bool($v)) return $v ? 'true' : 'false';
        if (is_int($v) || is_float($v)) return (string) $v;
        if (is_string($v)) return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $v) . "'";
        if (is_array($v)) {
            $parts = [];
            $isList = array_keys($v) === range(0, count($v) - 1);
            foreach ($v as $k => $vv) {
                $parts[] = $isList
                    ? self::phpEncode($vv)
                    : self::phpEncode((string) $k) . ' => ' . self::phpEncode($vv);
            }
            return '[ ' . implode(', ', $parts) . ' ]';
        }
        return 'NULL';
    }
}
