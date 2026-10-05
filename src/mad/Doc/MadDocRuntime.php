<?php

namespace Mad\Doc;

/**
 * MadDocRuntime — helpers de runtime dos componentes <mad-doc-*> (data-table,
 * variable-field). Resolve paths de atributos, avalia fórmulas aritméticas
 * simples sobre os campos da linha e formata valores no padrão pt-BR.
 *
 * Sem estado: tudo estático. Usado tanto pelo doc-data-table.blade (rows +
 * colunas computadas) quanto pelo doc-variable-field.blade (valor único).
 */
final class MadDocRuntime
{
    /**
     * Lê um valor de um registro por path com notação de ponto.
     *
     * Suporta arrays, objetos (Eloquent/stdClass), getters mágicos e relações
     * encadeadas: pull($pedido, 'produto.nome') desce por produto → nome.
     *
     * @param  mixed  $row   array|object
     * @param  string $path  ex: "valor_unit", "produto.nome"
     * @return mixed         valor cru (null se qualquer segmento faltar)
     */
    public static function pull(mixed $row, string $path): mixed
    {
        // O emitter do MadBuilder escreve paths de FK chain com `->`
        // (`produto->nome`) — mesma sintaxe dos acessores PHP — enquanto
        // este runtime navega com notação de ponto. Normaliza aqui pra
        // aceitar as duas formas (nomes de coluna nunca contêm `-`).
        $path = trim(strtr($path, ['->' => '.']));
        if ($path === '') {
            return null;
        }

        $value = $row;
        foreach (explode('.', $path) as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                return null;
            }

            if (is_array($value) || $value instanceof \ArrayAccess) {
                if (!isset($value[$segment])) {
                    return null;
                }
                $value = $value[$segment];
                continue;
            }

            if (is_object($value)) {
                // propriedade pública direta
                if (isset($value->$segment)) {
                    $value = $value->$segment;
                    continue;
                }
                // getter estilo getNome()
                $getter = 'get' . str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $segment)));
                if (method_exists($value, $getter)) {
                    $value = $value->$getter();
                    continue;
                }
                // __get mágico (Eloquent attributes/relations) — guarda contra erro
                try {
                    $resolved = $value->$segment;
                } catch (\Throwable $e) {
                    // Erro de relação/atributo (lazy-load, coluna faltando) não pode
                    // quebrar o documento — devolve null, mas loga pra não mascarar
                    // bug de model como "campo opcional vazio".
                    self::warn('MadDocRuntime: exceção ao ler atributo via __get, retornando null', [
                        'path'    => $path,
                        'segment' => $segment,
                        'class'   => self::classLabel($value),
                        'error'   => $e->getMessage(),
                    ]);
                    return null;
                }
                if ($resolved === null) {
                    return null;
                }
                $value = $resolved;
                continue;
            }

            // escalar no meio do path → não há como descer mais
            return null;
        }

        return $value;
    }

    /**
     * Avalia uma fórmula aritmética com tokens {campo} resolvidos contra a linha.
     *
     * Ex: "{quantidade}*{valor_unit}" → 3*10 → 30.0
     * Aceita + - * / ( ) e números. Tokens não-numéricos viram 0. Divisão por
     * zero retorna 0. Qualquer caractere fora da gramática segura → retorna 0
     * (nunca usa eval sobre input arbitrário).
     *
     * @param  array $rowArr  linha já normalizada em array (com computados publicados)
     */
    public static function evaluateFormula(string $formula, array $rowArr): float
    {
        // Substitui {token} pelo valor numérico do campo (dot-path no array).
        $expr = preg_replace_callback('/\{([^{}]+)\}/', function (array $m) use ($rowArr): string {
            $raw = self::pull($rowArr, trim($m[1]));
            $num = is_numeric($raw) ? (float) $raw : 0.0;
            // formato canônico, ponto decimal, sem notação científica
            return '(' . rtrim(rtrim(sprintf('%.10F', $num), '0'), '.') . ')';
        }, $formula);

        // Só permite dígitos, ponto, operadores, parênteses e espaço.
        if (!preg_match('/^[0-9.\s()+\-*\/]*$/', $expr)) {
            // Fórmula inválida (token não-numérico, operador desconhecido, typo).
            // Retorna 0.0 pra não estourar o PDF, mas loga — senão um erro de
            // template vira um total mudo indistinguível de um zero legítimo.
            self::warn('MadDocRuntime: fórmula inválida, retornando 0', [
                'formula'  => $formula,
                'expanded' => $expr,
            ]);
            return 0.0;
        }
        if (trim($expr) === '') {
            return 0.0;
        }

        try {
            return self::evalArithmetic($expr);
        } catch (\Throwable $e) {
            self::warn('MadDocRuntime: exceção avaliando fórmula, retornando 0', [
                'formula' => $formula,
                'error'   => $e->getMessage(),
            ]);
            return 0.0;
        }
    }

    /**
     * Valor impresso no meio de um parágrafo do documento, escapado, com o
     * hífen entre letras/dígitos trocado pelo hífen NÃO separável (U+2011).
     *
     * O PDF quebra a linha em hífen: "OS-2026-0001" no fim da linha virava
     * "OS-" / "2026-0001". `white-space: nowrap` no valor inteiro seria pior
     * (um nome longo nunca quebraria); assim o valor continua quebrando nos
     * espaços e só o código fica inteiro.
     */
    public static function inline(mixed $value): string
    {
        if (is_bool($value)) {
            $value = $value ? '1' : '';
        }
        $html = e(is_scalar($value) || $value instanceof \Stringable ? (string) $value : '');

        return (string) preg_replace('/(?<=[\p{L}\p{N}])-(?=[\p{L}\p{N}])/u', '&#8209;', $html);
    }

    /**
     * Compila os ecos `{{ … }}` de dentro de <mad-doc-text>/<mad-doc-heading>
     * para {@see inline()} — roda no MadBlade antes de a tag virar componente.
     * `{!! !!}` (HTML do autor), `{{-- --}}` e `@{{` ficam como estão.
     */
    public static function compileInlineEchoes(string $template): string
    {
        return (string) preg_replace_callback(
            '#(<mad-doc-(text|heading)\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>)(.*?)(</mad-doc-\2\s*>)#s',
            static function (array $m): string {
                $body = (string) preg_replace_callback(
                    '/(?<!@)\{\{(?!--)\s*(.+?)\s*\}\}/s',
                    static fn (array $e): string => '{!! \\Mad\\Doc\\MadDocRuntime::inline(' . $e[1] . ') !!}',
                    $m[3]
                );

                return $m[1] . $body . $m[4];
            },
            $template
        );
    }

    /**
     * Formata um valor cru conforme o tipo de coluna do documento.
     *
     * Built-ins delegam pro resolver ÚNICO compartilhado com o grid
     * (\Mad\Support\ValueFormatter — catálogo lockstep com `formatters.ts`).
     * Só o prefixo `custom:` fica aqui: ele resolve pra classe de transformer
     * POR CONTEXTO (DocumentTransformer no doc / GridTransformer no grid),
     * então não pertence ao resolver compartilhado.
     *
     *   custom:<slug> → \App\Transformer\DocumentTransformer::<camel(slug)>($value)
     *                   (fallback legado: \App\Transformer\<Studly>::apply)
     */
    public static function applyFormat(mixed $value, string $format): string
    {
        $format = trim($format) ?: 'text';

        // Transformer custom do projeto gerado: custom:status_color →
        // \App\Transformer\DocumentTransformer::statusColor($value). Falha → valor cru.
        if (str_starts_with($format, 'custom:')) {
            return self::applyCustomTransformer(substr($format, 7), $value);
        }

        return \Mad\Support\ValueFormatter::apply($value, $format);
    }

    private static function scalarString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Dispatch de transformer custom do app gerado:
     * `custom:status_color` → `\App\Transformer\DocumentTransformer::statusColor($value)`.
     * Fallback legado (layout antigo, um arquivo por transformer):
     * `\App\Transformer\StatusColor::apply($value)`.
     * Classe/método ausente ou exceção → valor cru (documento nunca quebra).
     */
    private static function applyCustomTransformer(string $slug, mixed $value): string
    {
        $slug = trim($slug);
        if ($slug === '' || ! preg_match('/^[A-Za-z0-9_\-]+$/', $slug)) {
            return self::scalarString($value);
        }
        $studly = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $slug)));
        $camel  = lcfirst($studly); // lockstep com Str::camel (slug é snake/kebab)
        try {
            // Layout novo: classe por contexto, transformer = método estático camelCase.
            $ctxCls = '\\App\\Transformer\\DocumentTransformer';
            if (class_exists($ctxCls) && method_exists($ctxCls, $camel)) {
                $out = $ctxCls::$camel($value);
                return is_scalar($out) ? (string) $out : self::scalarString($value);
            }
            // Layout legado: App\Transformer\<Studly>::apply (projetos gerados antigos).
            $cls = '\\App\\Transformer\\' . $studly;
            if (class_exists($cls) && method_exists($cls, 'apply')) {
                $out = $cls::apply($value);
                return is_scalar($out) ? (string) $out : self::scalarString($value);
            }
        } catch (\Throwable $e) {
            self::warn('MadDocRuntime: transformer custom falhou, usando valor cru', [
                'transformer' => $slug,
                'error'       => $e->getMessage(),
            ]);
        }
        return self::scalarString($value);
    }

    /**
     * Avaliador aritmético seguro (shunting-yard) — sem eval().
     * Entrada já validada contra a whitelist de caracteres.
     */
    private static function evalArithmetic(string $expr): float
    {
        $tokens  = self::tokenize($expr);
        $output  = []; // RPN
        $ops     = [];
        $prec     = ['+' => 1, '-' => 1, '*' => 2, '/' => 2];

        $prevType = null; // 'num' | 'op' | '(' | ')'  — p/ detectar menos unário
        foreach ($tokens as $tok) {
            if (is_float($tok)) {
                $output[] = $tok;
                $prevType = 'num';
                continue;
            }
            if ($tok === '(') {
                $ops[] = $tok;
                $prevType = '(';
                continue;
            }
            if ($tok === ')') {
                while ($ops && end($ops) !== '(') {
                    $output[] = array_pop($ops);
                }
                array_pop($ops); // remove '('
                $prevType = ')';
                continue;
            }
            // operador
            if (($tok === '-' || $tok === '+') && ($prevType === null || $prevType === 'op' || $prevType === '(')) {
                // menos/mais unário → empurra 0 antes
                $output[] = 0.0;
            }
            while ($ops && end($ops) !== '(' && $prec[end($ops)] >= $prec[$tok]) {
                $output[] = array_pop($ops);
            }
            $ops[] = $tok;
            $prevType = 'op';
        }
        while ($ops) {
            $output[] = array_pop($ops);
        }

        // avalia RPN
        $stack = [];
        foreach ($output as $tok) {
            if (is_float($tok)) {
                $stack[] = $tok;
                continue;
            }
            $b = array_pop($stack) ?? 0.0;
            $a = array_pop($stack) ?? 0.0;
            $stack[] = match ($tok) {
                '+' => $a + $b,
                '-' => $a - $b,
                '*' => $a * $b,
                '/' => $b == 0.0 ? 0.0 : $a / $b,
                default => 0.0,
            };
        }

        return (float) (array_pop($stack) ?? 0.0);
    }

    /** @return array<int, float|string> números (float) e operadores/parênteses (string) */
    private static function tokenize(string $expr): array
    {
        $tokens = [];
        $len    = strlen($expr);
        $i      = 0;
        while ($i < $len) {
            $ch = $expr[$i];
            if (ctype_space($ch)) {
                $i++;
                continue;
            }
            if (ctype_digit($ch) || $ch === '.') {
                $num = '';
                while ($i < $len && (ctype_digit($expr[$i]) || $expr[$i] === '.')) {
                    $num .= $expr[$i];
                    $i++;
                }
                $tokens[] = (float) $num;
                continue;
            }
            // operador ou parêntese
            $tokens[] = $ch;
            $i++;
        }
        return $tokens;
    }

    /**
     * Loga um warning sem acoplar a runtime ao host Laravel.
     *
     * Usa o helper logger() quando disponível (host Laravel) e vira no-op em
     * uso PHP puro — preserva o retorno do chamador (0.0/null) intacto e nunca
     * lança, então jamais quebra a geração do PDF.
     */
    /**
     * Carrega registros para um <mad-doc-data-table>/<mad-doc-repeater> a partir
     * de um model + filtro opcional. 100% Eloquent/Query Builder.
     *
     * O parâmetro $filter (opcional) aceita:
     *   - null              → todos os registros do model ($model::all())
     *   - Closure           → recebe o query builder do model (ou null se o
     *                         model não resolver) e devolve Builder/Relation/
     *                         Collection/array. Ex: fn($q) => $q->where(...)->limit(5)
     *   - Builder/Relation  → qualquer objeto com ->get()
     *   - Collection/array  → usado direto (escape hatch já-carregado)
     *
     * Nunca lança: erro de DB/model vira [] (loga warning) pra não derrubar o
     * PDF. $model pode ser FQCN ou nome curto (resolvido contra App\Models\).
     *
     * @return array<int, mixed> linhas (models Eloquent, stdClass ou arrays)
     */
    public static function queryRecords(string $model, mixed $filter = null): array
    {
        try {
            if ($filter instanceof \Closure) {
                return self::toRows($filter(self::modelQuery($model)));
            }
            // Builder/Relation/Collection/array já fornecidos.
            if (is_object($filter) || is_iterable($filter)) {
                return self::toRows($filter);
            }
            // Sem filter → todos os registros do model.
            $query = self::modelQuery($model);
            return $query ? self::toRows($query) : [];
        } catch (\Throwable $e) {
            self::warn('MadDocRuntime: queryRecords falhou, retornando []', [
                'model' => $model,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /** Resolve $model (FQCN ou nome curto → App\Models\X) num query builder Eloquent. */
    private static function modelQuery(string $model): ?object
    {
        if ($model === '') {
            return null;
        }
        $cls = \Mad\Database\ModelRegistry::resolve($model);
        if ($cls !== null && is_subclass_of($cls, \Illuminate\Database\Eloquent\Model::class)) {
            return $cls::query();
        }
        return null;
    }

    /** Normaliza Builder/Relation/Collection/array/Traversable num array de linhas. */
    private static function toRows(mixed $result): array
    {
        // Builder/Relation → executa a query (Collection).
        if (is_object($result)
            && ! $result instanceof \Traversable
            && method_exists($result, 'get')) {
            $result = $result->get();
        }
        if ($result instanceof \Traversable) {
            return iterator_to_array($result, false);
        }
        if (is_array($result)) {
            return array_values($result);
        }
        return [];
    }

    /**
     * Rótulo seguro do nome da classe pro log.
     *
     * Classes anônimas têm `::class` = "class@anonymous\0/abs/path/file.php:NN$h"
     * — vaza o caminho absoluto do filesystem do servidor no log. Colapsa pra
     * 'anonymous-class'; FQCN nomeado passa intacto (revela só o namespace).
     */
    private static function classLabel(object $value): string
    {
        $class = $value::class;
        return str_contains($class, '@anonymous') ? 'anonymous-class' : $class;
    }

    private static function warn(string $message, array $context = []): void
    {
        if (function_exists('logger')) {
            try {
                logger()->warning($message, $context);
            } catch (\Throwable) {
                // logging nunca pode derrubar o documento.
            }
        }
    }
}
