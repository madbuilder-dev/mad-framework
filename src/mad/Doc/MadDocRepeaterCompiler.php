<?php

namespace Mad\Doc;

/**
 * MadDocRepeaterCompiler — compiles <mad-doc-repeater ...>…</mad-doc-repeater>
 * to pure Blade/PHP BEFORE Blade processes the template.
 *
 * Called by MadBlade::compileString() in the same stage MadGridCompiler
 * runs, so the repeater tag never reaches Blade's component system.
 *
 * Why not a regular <x-component>?
 *   Blade components render their `{{ $slot }}` once — they can't iterate
 *   the slot with a per-row `$record` binding. A pre-compile pass sidesteps
 *   that limitation cleanly.
 *
 * Syntax
 * ──────
 *   <mad-doc-repeater
 *       :rows="$record->itens"
 *       :aggregates="[
 *           'total_qtd' => ['op' => 'sum', 'field' => 'quantidade', 'format' => 'money'],
 *       ]">
 *       <mad-doc-text>Qtd: {{ $record->quantidade }}</mad-doc-text>
 *   </mad-doc-repeater>
 *
 * Emits
 * ─────
 *   @php $__mdr_0_rows = $record->itens ?? []; @endphp
 *   @php $__mdr_0_agg_total_qtd = 0.0; @endphp
 *   @php $__mdr_0_parent = $record ?? null; @endphp
 *   @foreach($__mdr_0_rows as $__mdr_0_row)
 *       @php $record = $__mdr_0_row; @endphp
 *       @php $__mdr_0_agg_total_qtd += (float) ($__mdr_0_row->quantidade ?? 0); @endphp
 *       …body…
 *   @endforeach
 *   @php $record = $__mdr_0_parent; @endphp
 *   @php $totals = $totals ?? new \ArrayObject();
 *        $totals['total_qtd'] = \Mad\Doc\MadDocRuntime::applyFormat($__mdr_0_agg_total_qtd, 'money'); @endphp
 *
 * The `$record` alias is saved + restored around the loop so code outside
 * the repeater keeps the master record in scope — matches what the
 * hand-written Blade did before.
 *
 * Supported aggregate ops: sum | count | avg | min | max.
 * `field` is optional for `count` (counts rows regardless).
 * `format` feeds MadDocRuntime::applyFormat — text | money | number |
 * integer | date | datetime | boolean. Missing → `money` (sum/avg/min/max)
 * or `integer` (count).
 */
class MadDocRepeaterCompiler
{
    /** Counter for scoped var names — avoids clashes on nested repeaters. */
    private static int $scope = 0;

    public static function compile(string $template): string
    {
        if (strpos($template, '<mad-doc-repeater') === false) return $template;

        // Attributes: sequence of `name`, `name="value"` or `:name="value"`.
        // Value is anything inside matching double OR single quotes — lets `>`
        // appear inside quoted values like `:rows="$record->itens"` without
        // prematurely closing the tag.
        $attr = '(?:\s+(?::?[a-zA-Z_][\w:.-]*(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'))?))*\s*';

        // Iterative bottom-up: match innermost first so nested repeaters compile
        // correctly (outer ones see inner ones already lowered to PHP/Blade).
        $prev = '';
        $max = 20;
        while ($template !== $prev && $max-- > 0) {
            $prev = $template;
            $template = preg_replace_callback(
                // Body must not contain another <mad-doc-repeater — the iteration
                // handles nesting by peeling the innermost match each round.
                '/<mad-doc-repeater(' . $attr . ')>((?:(?!<mad-doc-repeater\b|<\/mad-doc-repeater>)[\s\S])*)<\/mad-doc-repeater>/s',
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
        $pfx   = "__mdr_{$scope}";

        // Rows sourcing — :rows wins, else model[+filter], else empty.
        [$sourcePrelude, $rowsExpr] = self::buildRowsExpression($attrs, $pfx);

        $aggsExpr = $attrs['aggregates'] ?? null;
        $emptyHtml = $attrs['empty'] ?? null; // optional :empty="'<p>vazio</p>'"

        // Decode aggregates at compile time if it's a literal PHP array string
        // (common case — the MadBuilder emitter writes them as literal arrays).
        // If it's a dynamic expression, fall back to runtime eval via eval'd
        // closure. For v1 we require a literal.
        $aggMap = self::parseAggregatesLiteral($aggsExpr);

        $out  = "@php \${$pfx}_parent = \$record ?? null; @endphp\n";
        if ($sourcePrelude !== '') $out .= $sourcePrelude;
        $out .= "@php \${$pfx}_rows = ({$rowsExpr}) ?: []; @endphp\n";

        // Init accumulators
        foreach ($aggMap as $name => $spec) {
            $init = $spec['op'] === 'count' ? '0' : '0.0';
            $out .= "@php \${$pfx}_agg_" . self::safeName($name) . " = {$init}; @endphp\n";
        }
        // min/max need "seen any row yet?" flag
        foreach ($aggMap as $name => $spec) {
            if (in_array($spec['op'], ['min', 'max'], true)) {
                $out .= "@php \${$pfx}_seen_" . self::safeName($name) . " = false; @endphp\n";
            }
        }
        // avg needs a count
        foreach ($aggMap as $name => $spec) {
            if ($spec['op'] === 'avg') {
                $out .= "@php \${$pfx}_count_" . self::safeName($name) . " = 0; @endphp\n";
            }
        }

        $out .= "@forelse(\${$pfx}_rows as \${$pfx}_row)\n";
        $out .= "@php \$record = \${$pfx}_row; @endphp\n";

        // Accumulate
        foreach ($aggMap as $name => $spec) {
            $safe = self::safeName($name);
            $var  = "\${$pfx}_agg_{$safe}";
            $op   = $spec['op'];
            $fld  = $spec['field'] ?? '';
            $acc  = $fld !== '' ? "(float) (\${$pfx}_row->{$fld} ?? 0)" : '0';

            switch ($op) {
                case 'sum':
                case 'avg':
                    $out .= "@php {$var} += {$acc}; @endphp\n";
                    if ($op === 'avg') {
                        $out .= "@php \${$pfx}_count_{$safe}++; @endphp\n";
                    }
                    break;
                case 'count':
                    $out .= "@php {$var}++; @endphp\n";
                    break;
                case 'min':
                    $out .= "@php if (! \${$pfx}_seen_{$safe} || {$acc} < {$var}) { {$var} = {$acc}; \${$pfx}_seen_{$safe} = true; } @endphp\n";
                    break;
                case 'max':
                    $out .= "@php if (! \${$pfx}_seen_{$safe} || {$acc} > {$var}) { {$var} = {$acc}; \${$pfx}_seen_{$safe} = true; } @endphp\n";
                    break;
            }
        }

        // Body (row template) — chips inside it reference `$record->...`, which
        // we just aliased to the current row.
        $out .= $body;

        $out .= "\n@empty\n";
        if ($emptyHtml !== null) {
            // `:empty` expression evaluated at runtime (Blade will echo it)
            $out .= "{!! {$emptyHtml} !!}\n";
        }
        $out .= "@endforelse\n";

        // Restore parent $record
        $out .= "@php \$record = \${$pfx}_parent; @endphp\n";

        // Finalize avg (divide by count), then publish aggregates into $totals
        foreach ($aggMap as $name => $spec) {
            $safe = self::safeName($name);
            $var  = "\${$pfx}_agg_{$safe}";
            $op   = $spec['op'];
            $fmt  = $spec['format'] ?? ($op === 'count' ? 'integer' : 'money');

            if ($op === 'avg') {
                $out .= "@php if (\${$pfx}_count_{$safe} > 0) { {$var} /= \${$pfx}_count_{$safe}; } @endphp\n";
            }
            $fmtLit = var_export($fmt, true);
            $out .= "@php \$totals = \$totals ?? new \\ArrayObject(); "
                  . "\$totals[" . var_export($name, true) . "] = \\Mad\\Doc\\MadDocRuntime::applyFormat({$var}, {$fmtLit}); "
                  . "\$totals[" . var_export($name . '_raw', true) . "] = {$var}; @endphp\n";
        }

        return $out;
    }

    /**
     * Decide how to build the rows-array expression based on attribute presence.
     * Precedence: explicit :rows → model[+filter] → empty list.
     *
     * @return array{0:string,1:string} [prelude @php block, rows expression]
     */
    private static function buildRowsExpression(array $attrs, string $pfx): array
    {
        // (a) :rows="…" — the escape hatch for pre-loaded collections.
        if (isset($attrs['rows']) && ($attrs['__bound__']['rows'] ?? false)) {
            return ['', (string) $attrs['rows']];
        }
        // (b) model [+ filter] — canonical pattern, compiler emits the
        //     MadDocRuntime::queryRecords() (Eloquent) call.
        if (isset($attrs['model'])) {
            $model = (string) $attrs['model'];
            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*$/', $model)) {
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
            $prelude = "@php \${$pfx}_source = \\Mad\\Doc\\MadDocRuntime::queryRecords({$modelExpr}, ({$filterExpr})); @endphp\n";
            return [$prelude, "\${$pfx}_source"];
        }
        // (c) nothing declared — empty list.
        return ['', '[]'];
    }

    /**
     * Parse the tag attributes.
     *
     * Supports:
     *   :name="PHP expression"          → stored as the raw expression string
     *   name="static string"           → stored with the quotes stripped
     *
     * Returns a map keyed by kebab-case or camelCase attribute name. Values
     * are the raw string we need (PHP expression for bound attrs, literal
     * text for static ones).
     */
    private static function parseAttrs(string $attrBlob): array
    {
        $out = [];
        $pos = 0;
        $len = strlen($attrBlob);

        while ($pos < $len) {
            if (preg_match('/\G\s+/', $attrBlob, $m, 0, $pos)) {
                $pos += strlen($m[0]);
                continue;
            }
            // :name="…" (bind) or name="…" (static). Value tolerates newlines
            // and single/double-quoted.
            if (preg_match(
                '/\G(:?)([a-zA-Z_][a-zA-Z0-9_:-]*)\s*=\s*(["\'])((?:(?!\3)[\s\S])*?)\3/s',
                $attrBlob, $m, 0, $pos,
            )) {
                $pos += strlen($m[0]);
                $bound = $m[1] === ':';
                $name  = $m[2];
                $val   = $m[4];
                $out[$name] = $bound ? $val : $val;
                $out['__bound__'][$name] = $bound;
                continue;
            }
            // boolean-ish attribute (no value)
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
     * Best-effort parse of an `:aggregates="[...]"` PHP array literal.
     * The MadBuilder emitter always writes a literal, so this is safe in
     * practice. Returns map of name → ['op' => ..., 'field' => ?, 'format' => ?].
     */
    private static function parseAggregatesLiteral(?string $expr): array
    {
        if ($expr === null || trim($expr) === '') return [];
        // Very defensive eval — we require the expression to be a pure array
        // literal of scalars + nested arrays (no function calls, no variables).
        if (! preg_match('/^\s*\[[\s\S]*\]\s*$/', $expr)) return [];
        if (preg_match('/[A-Za-z_]\(|::|\\$[a-zA-Z_]/', $expr)) {
            // Contains function call / scope resolution / PHP variable → not a literal.
            return [];
        }
        $arr = null;
        // Wrap in try/catch to swallow parse errors quietly — the compiler
        // emits nothing for the aggregates in that case.
        try {
            $arr = eval('return ' . $expr . ';');
        } catch (\Throwable $e) {
            return [];
        }
        if (! is_array($arr)) return [];

        $out = [];
        foreach ($arr as $name => $spec) {
            if (! is_string($name) || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) continue;
            if (! is_array($spec)) continue;
            $op = strtolower((string) ($spec['op'] ?? 'sum'));
            if (! in_array($op, ['sum', 'count', 'avg', 'min', 'max'], true)) continue;
            $field = $spec['field'] ?? null;
            if ($field !== null && ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:->[A-Za-z_][A-Za-z0-9_]*)*$/', (string) $field)) {
                continue;
            }
            $out[$name] = [
                'op' => $op,
                'field' => $field,
                'format' => isset($spec['format']) ? (string) $spec['format'] : null,
            ];
        }
        return $out;
    }

    private static function safeName(string $s): string
    {
        return preg_replace('/[^A-Za-z0-9_]/', '_', $s) ?? '_';
    }
}
