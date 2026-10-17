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
