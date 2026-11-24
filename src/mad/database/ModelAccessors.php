<?php

namespace Mad\Database;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Atributo calculado do Model (accessor) que NÃO é coluna da tabela.
 *
 * Quem monta a tela escreve no Model `getNomeFormatadoAttribute()` ou
 * `nomeFormatado(): Attribute` e usa `{nome_formatado}` como coluna da
 * listagem, texto de combo ou do campo de busca (fórum #135). Ler o valor
 * funciona sempre que a linha é o Model — o accessor dispara. Mas o nome não
 * existe no banco: busca, ordenação e filtro que o mandassem para o SQL caíam
 * com "no such column", e uma linha crua (stdClass, sem Model por trás) sai
 * com o texto em branco.
 *
 * Esta classe é a fonte única do runtime para a pergunta "esse campo existe
 * só em PHP?". Accessor com o MESMO nome de uma coluna real não conta: a
 * coluna existe, ordena e filtra pelo banco como sempre.
 *
 * Schema que não dá para ler (conexão fora, tabela inexistente) responde "não
 * sei": nada é marcado como calculado e o comportamento é o de antes.
 *
 * As colunas da tabela ficam no memo POR REQUEST ({@see DataScope::memo()}),
 * não em `static`: sob Octane o worker sobreviveria a uma migration e
 * continuaria achando que a coluna nova não existe.
 */
final class ModelAccessors
{
    private const IDENT_RE = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /** Caminho simples ou de relação: `nome`, `cliente->nome`, `a->b->c`. */
    private const PATH_RE = '/^[A-Za-z_][A-Za-z0-9_]*(?:->[A-Za-z_][A-Za-z0-9_]*)*$/';

    /** `type_name` de coluna de texto em sqlite / mysql / mariadb / pgsql / sqlsrv. */
    private const TEXT_TYPE_RE = '/^(?:n?(?:var)?char|bpchar|character(?: varying)?|(?:tiny|medium|long|n)?text|citext|string|clob)$/i';

    /**
     * `$attr` é accessor do Model e não é coluna da tabela?
     *
     * Aceita o nome com ou sem chaves (`{nome_formatado}`).
     */
    public static function isVirtual(Model $model, string $attr): bool
    {
        $attr = self::unbrace($attr);
        if (! preg_match(self::IDENT_RE, $attr)) {
            return false;
        }

        try {
            if (! $model->hasGetMutator($attr) && ! $model->hasAttributeGetMutator($attr)) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }

        $columns = self::columns($model);
        if ($columns === null) {
            return false;   // não sei: fica como coluna, igual a antes
        }

        return ! isset($columns[strtolower($attr)]);
    }

    /**
     * O caminho termina num atributo calculado? `cliente->nome_formatado`
     * percorre as relações e testa a última parte no Model relacionado;
     * caminho sem relação (`nome_formatado`) testa no próprio Model.
     * Relação que não existe responde false.
     */
    public static function virtualPath(Model $model, string $path): bool
    {
        $path = self::unbrace($path);
        if (! preg_match(self::PATH_RE, $path)) {
            return false;
        }

        $parts  = explode('->', $path);
        $leaf   = array_pop($parts);
        $cursor = $model;
        foreach ($parts as $segment) {
            $cursor = self::related($cursor, $segment);
            if ($cursor === null) {
                return false;
            }
        }

        return self::isVirtual($cursor, $leaf);
    }

    /**
     * Os campos de um texto de exibição (`{a} - {cliente->b}` ou o nome nu)
     * que são calculados no Model.
     *
     * @return list<string> os caminhos como estão no texto, sem as chaves
     */
    public static function virtualTokens(Model $model, string $pattern): array
    {
        $out = [];
        foreach (self::tokens($pattern) as $token) {
            if (self::virtualPath($model, $token)) {
                $out[] = $token;
            }
        }

        return $out;
    }

    /**
     * `$col` é coluna da tabela do Model? null = schema ilegível ("não sei").
     * Sem chaves e sem caminho: só o nome simples da coluna.
     *
     * O que o próprio Model declara responde sem ir ao banco: chave,
     * `$fillable` (o Model gerado lista ali as colunas), timestamps e a coluna
     * da lixeira. Só o resto lê o schema — a listagem que ordena por `id` não
     * ganha uma consulta a mais a cada carga.
     */
    public static function isColumn(Model $model, string $col): ?bool
    {
        $lower    = strtolower($col);
        $declared = array_merge([$model->getKeyName()], $model->getFillable());
        if ($model->usesTimestamps()) {
            $declared[] = $model->getCreatedAtColumn();
            $declared[] = $model->getUpdatedAtColumn();
        }
        if (method_exists($model, 'getDeletedAtColumn')) {
            $declared[] = $model->getDeletedAtColumn();
        }
        foreach ($declared as $name) {
            if (is_string($name) && strtolower($name) === $lower) {
                return true;
            }
        }

        $columns = self::columns($model);
        if ($columns === null) {
            return null;
        }

        return isset($columns[strtolower($col)]);
    }

    /**
     * Colunas de texto da tabela (varchar, char, text…), fora as escondidas
     * do Model (`$hidden`: senha, token). É onde a busca procura quando o texto
     * de exibição só tem campos calculados.
     *
     * @return list<string>
     */
    public static function textColumns(Model $model): array
    {
        $columns = self::columns($model);
        if ($columns === null) {
            return [];
        }

        $hidden = array_flip(array_map('strtolower', $model->getHidden()));
        $out    = [];
        foreach ($columns as $lower => [$name, $type]) {
            if (! isset($hidden[$lower]) && preg_match(self::TEXT_TYPE_RE, $type)) {
                $out[] = $name;
            }
        }

        return $out;
    }

    /**
     * Colunas reais em que o accessor `$attr` filtra e busca, declaradas com
     * `#[FilterBy('cpf', 'cnpj')]` no método do accessor (fórum #393).
     *
     * Lista vazia = não declarado, ou nenhuma coluna válida: o campo segue só
     * exibição, como antes. Só entra coluna da tabela do PRÓPRIO Model,
     * conferida no schema (ou declarada no próprio Model: chave, `$fillable`),
     * fora as escondidas (`$hidden`: senha, token). Nada vem do navegador: o
     * nome das colunas sai do código do Model.
     *
     * @return list<string>
     */
    public static function filterColumns(Model $model, string $attr): array
    {
        $attr = self::unbrace($attr);
        if (! preg_match(self::IDENT_RE, $attr) || ! self::isVirtual($model, $attr)) {
            return [];
        }

        $hidden = array_flip(array_map('strtolower', $model->getHidden()));
        $out    = [];
        foreach (self::declaredFilterColumns($model, $attr) as $col) {
            if (! preg_match(self::IDENT_RE, $col) || isset($hidden[strtolower($col)])) {
                continue;
            }
            if (self::isColumn($model, $col) !== true || in_array($col, $out, true)) {
                continue;
            }
            $out[] = $col;
        }

        return $out;
    }

    /** Operador negativo => o positivo que ele nega. */
    private const NEGATED_OPS = [
        'not like' => 'like',
        '!='       => '=',
        '<>'       => '=',
        'not in'   => 'in',
        'empty'    => 'not empty',
        'is null'  => 'is not null',
    ];

    /**
     * WHERE de um campo calculado com `#[FilterBy]`: o mesmo predicado em cada
     * coluna declarada, unido com OU — "contém 123" acha a linha em que o CPF
     * OU o CNPJ contém 123.
     *
     * Operador negativo é a negação do positivo sobre o grupo inteiro:
     * "não contém 123" = nem o CPF nem o CNPJ contém 123. Cada coluna do grupo
     * negado exige `IS NOT NULL` antes do predicado, para o NOT não virar NULL
     * (e esconder a linha) quando uma das colunas está vazia.
     *
     * O valor nunca entra no SQL: `$apply` usa o builder (bind).
     *
     * @param list<string> $columns colunas já validadas ({@see filterColumns()}) e já
     *                              qualificadas pelo chamador (`tabela.coluna`)
     * @param callable(mixed $where, string $column, string $op): void $apply
     */
    public static function whereAnyColumn($q, array $columns, string $op, callable $apply): void
    {
        if ($columns === []) {
            return;
        }

        $positive = self::NEGATED_OPS[$op] ?? null;
        if ($positive === null) {
            $q->where(static function ($group) use ($columns, $op, $apply): void {
                foreach ($columns as $column) {
                    $group->orWhere(static function ($one) use ($column, $op, $apply): void {
                        $apply($one, $column, $op);
                    });
                }
            });

            return;
        }

        $q->whereNot(static function ($group) use ($columns, $positive, $apply): void {
            foreach ($columns as $column) {
                $group->orWhere(static function ($one) use ($column, $positive, $apply): void {
                    $one->whereNotNull($column);
                    $apply($one, $column, $positive);
                });
            }
        });
    }

    /** @var array<string, list<string>> classe|accessor => nomes declarados (código não muda no request) */
    private static array $declaredFilterMemo = [];

    /**
     * Nomes do `#[FilterBy(…)]` do método do accessor, sem validar: estilo
     * clássico (`getXxxAttribute`) e Laravel 9+ (`xxx(): Attribute`). Lê os
     * argumentos pela reflexão, sem instanciar o atributo — `FilterBy` sem o
     * `use` também casa pelo nome curto.
     *
     * @return list<string>
     */
    private static function declaredFilterColumns(Model $model, string $attr): array
    {
        $key = $model::class . '|' . $attr;
        if (array_key_exists($key, self::$declaredFilterMemo)) {
            return self::$declaredFilterMemo[$key];
        }

        $out = [];
        foreach (['get' . Str::studly($attr) . 'Attribute', Str::camel($attr)] as $method) {
            if (! method_exists($model, $method)) {
                continue;
            }
            try {
                $attributes = (new \ReflectionMethod($model, $method))->getAttributes();
            } catch (\Throwable) {
                continue;
            }
            foreach ($attributes as $attribute) {
                $name  = $attribute->getName();
                $short = ($pos = strrpos($name, '\\')) === false ? $name : substr($name, $pos + 1);
                if (strcasecmp($short, 'FilterBy') !== 0) {
                    continue;
                }
                try {
                    $args = $attribute->getArguments();
                } catch (\Throwable) {
                    continue;
                }
                array_walk_recursive($args, static function ($c) use (&$out): void {
                    if (is_string($c) && trim($c) !== '') {
                        $out[] = trim($c);
                    }
                });
            }
        }

        return self::$declaredFilterMemo[$key] = array_values(array_unique($out));
    }

    /**
     * `{nome}` / `{cliente->nome}` → `nome` / `cliente->nome`. Qualquer outra
     * coisa (template com texto, chaves soltas) volta como veio.
     */
    public static function unbrace(string $field): string
    {
        $f = trim($field);
        if (strlen($f) > 2 && $f[0] === '{' && substr($f, -1) === '}') {
            $inner = trim(substr($f, 1, -1));
            if (preg_match(self::PATH_RE, $inner)) {
                return $inner;
            }
        }

        return $f;
    }

    /**
     * Campos citados num texto de exibição: os `{…}` da máscara ou, sem
     * chaves, o próprio nome nu.
     *
     * @return list<string>
     */
    private static function tokens(string $pattern): array
    {
        $pattern = trim($pattern);
        if (str_contains($pattern, '{')) {
            preg_match_all('/\{([^{}]*)\}/', $pattern, $m);

            return array_values(array_unique(array_filter(
                array_map(static fn ($t) => trim(ltrim(trim($t), '$')), $m[1]),
                static fn ($t) => $t !== ''
            )));
        }

        return $pattern !== '' ? [$pattern] : [];
    }

    /** Model do outro lado de `$segment` (com a ponte snake↔camel), ou null. */
    private static function related(Model $model, string $segment): ?Model
    {
        foreach (array_unique([$segment, Str::camel($segment)]) as $name) {
            if (! method_exists($model, $name)) {
                continue;
            }
            // Método do próprio Eloquent (save, delete, push…) nunca é relação
            // — e chamá-lo para descobrir faria o que ele faz.
            try {
                $declaring = (new \ReflectionMethod($model, $name))->getDeclaringClass()->getName();
            } catch (\Throwable) {
                return null;
            }
            if (str_starts_with($declaring, 'Illuminate\\')) {
                return null;
            }
            try {
                $relation = $model->{$name}();
            } catch (\Throwable) {
                return null;
            }

            return $relation instanceof Relation ? $relation->getRelated() : null;
        }

        return null;
    }

    /**
     * Colunas da tabela do Model: nome em minúsculas => [nome, tipo]. null =
     * schema ilegível ("não sei").
     *
     * @return array<string, array{0:string,1:string}>|null
     */
    private static function columns(Model $model): ?array
    {
        $conn  = $model->getConnectionName();
        $table = $model->getTable();

        return DataScope::memo('model_accessors.columns', ($conn ?? '') . '|' . $table, static function () use ($conn, $table) {
            try {
                $out = [];
                foreach (Schema::connection($conn)->getColumns($table) as $c) {
                    $name = (string) ($c['name'] ?? '');
                    if ($name !== '') {
                        $out[strtolower($name)] = [$name, trim((string) ($c['type_name'] ?? ''))];
                    }
                }

                return $out === [] ? null : $out;
            } catch (\Throwable) {
                return null;
            }
        });
    }
}
